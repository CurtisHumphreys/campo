import * as API from '../api.js';

let allSites = [];
let editMode = false;

// Pan/Zoom State (Internal)
let lastTransform = { x: 0, y: 0, scale: 1 };

function toNumber(v) {
    if (v === null || v === undefined || v === '') return NaN;
    const n = Number(v);
    return Number.isFinite(n) ? n : NaN;
}

export async function render(container) {
    container.innerHTML = `
        <div class="header-actions">
            <h1>Campsite Map <span style="font-size:12px; font-weight:600; opacity:0.7;">v38</span></h1>
            <div class="actions-group">
                <input type="text" id="map-search" placeholder="Search Member or Site..." class="search-input" style="max-width: 200px;">
                <button id="toggle-edit-btn" class="secondary small">Enable Edit Mode</button>
            </div>
        </div>

        <div id="map-debug" style="margin: 6px 0 10px 0; font-size: 12px; padding: 6px 10px; border-radius: 10px; background: rgba(37,99,235,0.08); color: var(--title-text, #343637);"></div>

        <div class="card" style="padding: 0; overflow: hidden; height: 70vh; display: flex;">
            <div id="map-scroll-wrapper" style="flex: 1; overflow: hidden; position: relative; background: #e2e8f0; touch-action: none; cursor: grab;">
                <div id="map-wrapper" class="map-container" style="display: inline-block; min-width: 800px; transform-origin: 0 0; position: relative;">
                    <img src="/public/img/map.jpg" id="camp-map-img" alt="Campsite Map" style="display: block; width: 100%; height: auto;">
                    <div id="pins-layer" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: auto; z-index: 10;"></div>
                </div>
            </div>
        </div>

        <!-- Edit Pin Modal -->
        <div id="pin-modal" class="modal hidden">
            <div class="modal-content">
                <h2>Map Site Pin</h2>
                <p>Clicking the map created a pin at this location. Which site is this?</p>
                <form id="pin-form">
                    <div class="form-group">
                        <label>Select Site Number</label>
                        <select id="pin-site-select" required>
                            <option value="">Loading...</option>
                        </select>
                    </div>
                    <input type="hidden" id="pin-x">
                    <input type="hidden" id="pin-y">
                    <div class="form-actions">
                        <button type="button" class="secondary" id="cancel-pin">Cancel</button>
                        <button type="submit">Save Pin</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Details Modal (Mobile) -->
        <div id="details-modal" class="modal hidden">
            <div class="modal-content">
                <h2 id="dm-site-number">Site -</h2>
                <p><strong>Status:</strong> <span id="dm-status">-</span></p>
                <p><strong>Occupants:</strong> <span id="dm-occupants">-</span></p>
                <div class="form-actions">
                    <button type="button" class="secondary" id="close-details">Close</button>
                    <button type="button" id="dm-action">Manage Site</button>
                </div>
            </div>
        </div>
    `;

    // If you can see this line, the updated map module has definitely loaded.
    const debugEl = document.getElementById('map-debug');
    if (debugEl) debugEl.textContent = 'Map module loaded. Fetching sites...';

    // Fetch Sites
    try {
        const res = await API.get('/sites');
        // Some endpoints may return {success:false,...} with HTTP 200
        if (res && typeof res === 'object' && res.success === false) {
            console.error('Failed to load sites:', res);
            allSites = [];
        } else {
            allSites = Array.isArray(res) ? res : [];
        }
        renderPins();
        // Debug: helpful when pins disappear due to data shape
        const mappedCount = allSites.filter(s => Number.isFinite(toNumber(s.map_x)) && Number.isFinite(toNumber(s.map_y))).length;
        const sample = allSites.slice(0, 3).map(s => ({ id: s.id, site_number: s.site_number, map_x: s.map_x, map_y: s.map_y }));
        console.log(`[Map] Sites loaded: ${allSites.length}. Mapped pins: ${mappedCount}. Sample:`, sample);
        if (debugEl) debugEl.textContent = `Sites: ${allSites.length} | Pins with coords: ${mappedCount}`;
    } catch (e) {
        console.error("Failed to load sites", e);
        allSites = [];
        if (debugEl) debugEl.textContent = 'Failed to load sites. Check console/network.';
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
    // Using mouseup/touchend logic inside panzoom to detect click vs drag
    // But for simplicity in Edit Mode, we attach a specific handler
    // We need to account for transform scale when calculating coordinates
    mapWrapper.addEventListener('click', (e) => {
        if (!editMode) return;
        if (e.target.closest('.map-pin')) return;

        // Bounding rect gives screen coords of transformed element
        const rect = mapWrapper.getBoundingClientRect();
        
        // Coordinates relative to the element (unscaled)
        // e.clientX is relative to viewport
        // rect.left is relative to viewport
        // So offset is (e.clientX - rect.left)
        // Since the element is scaled by CSS transform, this offset is scaled.
        // We need percentages relative to the *content* dimensions.
        
        // Actually, getBoundingClientRect returns the size *after* transform.
        // So (offset / rect.width) * 100 should still give correct % if we want pin to stay at that visual spot.
        
        const x = ((e.clientX - rect.left) / rect.width) * 100;
        const y = ((e.clientY - rect.top) / rect.height) * 100;

        openPinModal(x, y);
    });

    // Search
    document.getElementById('map-search').addEventListener('input', (e) => {
        const term = e.target.value.toLowerCase();
        document.querySelectorAll('.map-pin').forEach(pin => {
            const siteNum = pin.dataset.site.toLowerCase();
            const occ = pin.dataset.occupant.toLowerCase();
            const match = siteNum.includes(term) || occ.includes(term);
            
            pin.style.opacity = (term === '' || match) ? '1' : '0.2';
            pin.classList.toggle('highlight', match && term !== '');
        });
    });

    // Modals
    setupModals();
    initPanZoom();
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
        // If in edit mode, maybe allow panning still? Yes.
        panning = true;
        startCoords = { x: e.clientX - lastTransform.x, y: e.clientY - lastTransform.y };
        container.style.cursor = 'grabbing';
    });

    container.addEventListener('mouseup', () => {
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
}

function renderPins() {
    const layer = document.getElementById('pins-layer');
    layer.innerHTML = '';

    let rendered = 0;

    allSites.forEach(site => {
        const x = toNumber(site.map_x);
        const y = toNumber(site.map_y);

        // NOTE: Do not use truthy checks here. 0 is a valid coordinate.
        if (Number.isFinite(x) && Number.isFinite(y)) {
            const pin = document.createElement('div');
            pin.className = `map-pin status-${(site.status || 'Available').toLowerCase()}`;
            pin.style.left = `${x}%`;
            pin.style.top = `${y}%`;
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
                    if(confirm(`Remove pin for Site ${site.site_number}?`)) {
                        savePin(site.id, null, null);
                    }
                } else {
                    showDetails(site);
                }
            });

            layer.appendChild(pin);
            rendered++;
        }
    });

    // Lightweight on-screen debug (helps confirm data is arriving)
    const dbg = document.getElementById('map-debug');
    if (dbg) dbg.textContent = `Sites: ${allSites.length} | Pins: ${rendered}`;
}

function openPinModal(x, y) {
    const modal = document.getElementById('pin-modal');
    const select = document.getElementById('pin-site-select');
    
    const unmapped = allSites.filter(s => !Number.isFinite(toNumber(s.map_x)) || !Number.isFinite(toNumber(s.map_y))).sort((a,b) => 
        a.site_number.localeCompare(b.site_number, undefined, {numeric:true})
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
        // Navigate to Sites page within the SPA
        window.history.pushState(null, null, '/sites');
        const link = document.querySelector('a[href="/sites"]');
        if (link) link.click();
        modal.classList.add('hidden');
    };
    
    modal.classList.remove('hidden');
}

function setupModals() {
    document.getElementById('pin-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const siteId = document.getElementById('pin-site-select').value;
        const x = document.getElementById('pin-x').value;
        const y = document.getElementById('pin-y').value;

        if(!siteId) return alert("Select a site");

        await savePin(siteId, x, y);
        document.getElementById('pin-modal').classList.add('hidden');
    });

    document.getElementById('cancel-pin').onclick = () => document.getElementById('pin-modal').classList.add('hidden');
    document.getElementById('close-details').onclick = () => document.getElementById('details-modal').classList.add('hidden');
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
