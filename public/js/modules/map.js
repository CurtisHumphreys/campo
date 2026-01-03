import * as API from '../api.js';

let allSites = [];
let editMode = false;

// Pan/Zoom State (Internal)
let lastTransform = { x: 0, y: 0, scale: 1 };

export async function render(container) {
    container.innerHTML = `
        <div class="header-actions">
            <h1>Campsite Map</h1>
            <div class="actions-group">
                <input type="text" id="map-search" placeholder="Search Member or Site..." class="search-input" style="max-width: 200px;">
                <button id="toggle-edit-btn" class="secondary small">Enable Edit Mode</button>
            </div>
        </div>

        <div class="card" style="padding: 0; overflow: hidden; height: 70vh; display: flex;">
            <div id="map-scroll-wrapper" style="flex: 1; overflow: hidden; position: relative; background: #e2e8f0; touch-action: none; cursor: grab;">
                <div id="map-wrapper" class="map-container" style="display: inline-block; min-width: 800px; transform-origin: 0 0;">
                    <img src="/campo/public/img/map.jpg" id="camp-map-img" alt="Campsite Map" style="display: block; width: 100%; height: auto;">
                    <div id="pins-layer" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: auto;"></div>
                </div>
            </div>
        </div>

        <!-- Edit Pin Modal -->
        <div id="pin-modal" class="modal hidden">
            <div class="modal-content">
                <h2>Map Site Pin</h2>
                <p>Clicking the map created a pin at this location. Which site is this?</p>
                <form id="pin-form">
                    <label>Site</label>
                    <select id="pin-site-select">
                        <option value="">Loading...</option>
                    </select>

                    <input type="hidden" id="pin-x">
                    <input type="hidden" id="pin-y">

                    <div class="form-actions">
                        <button type="button" id="pin-cancel" class="secondary">Cancel</button>
                        <button type="submit">Save Pin</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Details Modal -->
        <div id="details-modal" class="modal hidden">
            <div class="modal-content">
                <h2 id="dm-site-number">Site</h2>
                <p><strong>Status:</strong> <span id="dm-status"></span></p>
                <p><strong>Occupants:</strong> <span id="dm-occupants"></span></p>

                <div class="form-actions">
                    <button type="button" id="dm-close" class="secondary">Close</button>
                    <button type="button" id="dm-action" class="primary">Go to Site</button>
                </div>
            </div>
        </div>
    `;

    // Load sites
    try {
        allSites = await API.get('/sites');
        renderPins();
    } catch (e) {
        console.error("Failed to load sites", e);
    }

    // Toggle Edit Mode
    const toggleBtn = document.getElementById('toggle-edit-btn');
    toggleBtn.addEventListener('click', () => {
        editMode = !editMode;
        toggleBtn.textContent = editMode ? 'Disable Edit Mode' : 'Enable Edit Mode';
        toggleBtn.classList.toggle('danger', editMode);
        document.getElementById('map-wrapper').classList.toggle('edit-active', editMode);
    });

    // Map Click (Add Pin)
    const mapWrapper = document.getElementById('map-wrapper');
    mapWrapper.addEventListener('click', (e) => {
        if (!editMode) return;

        // Don’t trigger on pin clicks
        if (e.target.closest('.map-pin')) return;

        const rect = mapWrapper.getBoundingClientRect();

        const x = ((e.clientX - rect.left) / rect.width) * 100;
        const y = ((e.clientY - rect.top) / rect.height) * 100;

        openPinModal(x, y);
    });

    // Search
    document.getElementById('map-search').addEventListener('input', (e) => {
        const q = e.target.value.toLowerCase().trim();
        renderPins(q);
    });

    setupModals();
    initPanZoom();
}

function renderPins(filter = '') {
    const layer = document.getElementById('pins-layer');
    if (!layer) return;
    layer.innerHTML = '';

    allSites.forEach(site => {
        if (!site.map_x || !site.map_y) return;

        const matches = !filter ||
            (site.occupants || '').toLowerCase().includes(filter) ||
            (site.site_number || '').toLowerCase().includes(filter);

        if (!matches) return;

        const pin = document.createElement('div');
        pin.className = 'map-pin';
        pin.style.position = 'absolute';
        pin.style.left = site.map_x + '%';
        pin.style.top = site.map_y + '%';
        pin.style.transform = 'translate(-50%, -50%)';
        pin.style.width = '14px';
        pin.style.height = '14px';
        pin.style.borderRadius = '50%';
        pin.style.background = site.status === 'Occupied' ? '#ef4444' : '#22c55e';
        pin.style.border = '2px solid white';
        pin.style.boxShadow = '0 1px 2px rgba(0,0,0,0.3)';
        pin.style.cursor = 'pointer';

        pin.dataset.id = site.id;
        pin.dataset.site = site.site_number;
        pin.dataset.occupant = site.occupants || '';

        pin.title = `Site ${site.site_number}`;

        // Prevent drag propagation
        pin.addEventListener('mousedown', e => e.stopPropagation());
        pin.addEventListener('touchstart', e => e.stopPropagation());

        pin.addEventListener('click', (e) => {
            e.stopPropagation();
            if (editMode) {
                if (confirm(`Remove pin for Site ${site.site_number}?`)) {
                    savePin(site.id, null, null);
                }
            } else {
                showDetails(site);
            }
        });

        layer.appendChild(pin);
    });
}

function openPinModal(x, y) {
    const modal = document.getElementById('pin-modal');
    const select = document.getElementById('pin-site-select');

    const unmapped = allSites.filter(s => !s.map_x).sort((a, b) =>
        a.site_number.localeCompare(b.site_number, undefined, { numeric: true })
    );

    select.innerHTML = unmapped.map(s => `<option value="${s.id}">${s.site_number} (${s.site_type})</option>`).join('');

    document.getElementById('pin-x').value = x;
    document.getElementById('pin-y').value = y;

    modal.classList.remove('hidden');
}

function showDetails(site) {
    const modal = document.getElementById('details-modal');
    document.getElementById('dm-site-number').textContent = `Site ${site.site_number}`;
    document.getElementById('dm-status').textContent = site.status;
    document.getElementById('dm-occupants').textContent = site.occupants || 'None';

    const btn = document.getElementById('dm-action');
    btn.onclick = () => {
        window.location.href = `/campo/sites?site=${encodeURIComponent(site.site_number)}`;
    };

    modal.classList.remove('hidden');
}

function setupModals() {
    // Pin modal
    document.getElementById('pin-cancel').addEventListener('click', () => {
        document.getElementById('pin-modal').classList.add('hidden');
    });

    document.getElementById('pin-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const siteId = document.getElementById('pin-site-select').value;
        const x = document.getElementById('pin-x').value;
        const y = document.getElementById('pin-y').value;

        if (!siteId) return alert("Please select a site");

        await savePin(siteId, x, y);
        document.getElementById('pin-modal').classList.add('hidden');
    });

    // Details modal
    document.getElementById('dm-close').addEventListener('click', () => {
        document.getElementById('details-modal').classList.add('hidden');
    });
}

function initPanZoom() {
    const container = document.getElementById('map-scroll-wrapper');
    const content = document.getElementById('map-wrapper');
    if (!container || !content) return;

    let panning = false;
    let startCoords = { x: 0, y: 0 };

    const updateTransform = () => {
        content.style.transform = `translate(${lastTransform.x}px, ${lastTransform.y}px) scale(${lastTransform.scale})`;
    };

    // Restore previous state if returning to map
    updateTransform();

    // Mouse Events
    container.addEventListener('mousedown', (e) => {
        panning = true;
        container.style.cursor = 'grabbing';
        startCoords = { x: e.clientX - lastTransform.x, y: e.clientY - lastTransform.y };
    });

    container.addEventListener('mouseup', () => {
        panning = false;
        container.style.cursor = 'grab';
    });

    container.addEventListener('mouseleave', () => {
        panning = false;
        container.style.cursor = 'grab';
    });

    container.addEventListener('mousemove', (e) => {
        if (!panning) return;
        e.preventDefault();
        lastTransform.x = e.clientX - startCoords.x;
        lastTransform.y = e.clientY - startCoords.y;
        updateTransform();
    });

    container.addEventListener('wheel', (e) => {
        e.preventDefault();
        const xs = (e.clientX - container.getBoundingClientRect().left - lastTransform.x) / lastTransform.scale;
        const ys = (e.clientY - container.getBoundingClientRect().top - lastTransform.y) / lastTransform.scale;

        const delta = -e.deltaY;
        const factor = delta > 0 ? 1.1 : 0.9;
        const newScale = Math.min(Math.max(0.5, lastTransform.scale * factor), 5);

        lastTransform.x -= xs * (newScale - lastTransform.scale);
        lastTransform.y -= ys * (newScale - lastTransform.scale);
        lastTransform.scale = newScale;

        updateTransform();
    });

    // Touch Events (Basic Pan support for internal map)
    container.addEventListener('touchstart', (e) => {
        if (e.touches.length === 1) {
            panning = true;
            startCoords = { x: e.touches[0].clientX - lastTransform.x, y: e.touches[0].clientY - lastTransform.y };
        }
    });

    container.addEventListener('touchmove', (e) => {
        if (panning && e.touches.length === 1) {
            e.preventDefault();
            lastTransform.x = e.touches[0].clientX - startCoords.x;
            lastTransform.y = e.touches[0].clientY - startCoords.y;
            updateTransform();
        }
    });

    container.addEventListener('touchend', () => {
        panning = false;
    });
}

async function savePin(id, x, y) {
    try {
        await API.post(`/site/update?id=${id}`, { map_x: x, map_y: y });
        const site = allSites.find(s => s.id == id);
        if (site) {
            site.map_x = x;
            site.map_y = y;
        }
        renderPins();
    } catch (e) {
        alert('Error saving pin: ' + e.message);
    }
}
