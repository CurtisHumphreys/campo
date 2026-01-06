import * as API from '../api.js';

let allSites = [];
let editMode = false;
let draggedPin = null;

export async function render(container) {
    // 1. RENDER HTML IMMEDIATELY so IDs exist in the DOM
    container.innerHTML = `
        <div class="header-actions">
            <h1>Camp Map</h1>
            <div class="actions-group">
                <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                    <input type="checkbox" id="edit-mode-toggle">
                    <strong>Edit Mode (Drag Pins)</strong>
                </label>
            </div>
        </div>

        <div class="card" style="padding:0; overflow:auto; display:flex; justify-content:center; background:#cbd5e1;">
            <div class="map-container" style="position:relative; width:fit-content;">
                <!-- map.jpg -->
                <img src="/public/img/map.jpg" id="camp-map-img" style="display:block; max-width:none;" alt="Campsite Map">
                
                <!-- Layer for Pins -->
                <div id="pins-layer"></div>
            </div>
        </div>

        <div class="card">
            <h3>Map Debug</h3>
            <p id="map-debug" class="muted">Loading sites...</p>
        </div>
    `;

    // 2. Setup Events
    const toggle = document.getElementById('edit-mode-toggle');
    if (toggle) {
        toggle.checked = editMode;
        toggle.addEventListener('change', (e) => {
            editMode = e.target.checked;
            updateEditState();
        });
    }

    // 3. Fetch Data
    try {
        allSites = await API.get('/sites');
        updateDebug(`Loaded ${allSites.length} sites.`);
        renderPins();
    } catch (err) {
        console.error('Failed to load sites:', err);
        updateDebug('Error loading sites: ' + err.message);
    }
}

function updateEditState() {
    const layer = document.getElementById('pins-layer');
    if (!layer) return;
    layer.style.pointerEvents = editMode ? 'auto' : 'none';
    
    // Visual cue
    document.querySelectorAll('.map-pin').forEach(p => {
        p.style.cursor = editMode ? 'move' : 'default';
        p.style.border = editMode ? '2px dashed yellow' : '2px solid white';
    });
}

function renderPins() {
    const layer = document.getElementById('pins-layer');
    if (!layer) return; // Guard clause against null error

    layer.innerHTML = '';

    let renderedCount = 0;

    allSites.forEach(site => {
        // Only render if coordinates exist
        if (site.map_x === null || site.map_y === null || site.map_x === undefined) return;

        const pin = document.createElement('div');
        pin.className = 'map-pin';
        pin.title = `Site ${site.site_number}`;
        
        // Position using percentages
        pin.style.left = `${site.map_x}%`;
        pin.style.top = `${site.map_y}%`;
        
        // Color based on status
        pin.style.backgroundColor = getStatusColor(site);

        // DRAG LOGIC
        pin.addEventListener('mousedown', (e) => startDrag(e, site, pin));
        
        layer.appendChild(pin);
        renderedCount++;
    });

    updateDebug(`Rendered ${renderedCount} pins.`);
    updateEditState();
}

function startDrag(e, site, pinElement) {
    if (!editMode) return;
    e.preventDefault();
    e.stopPropagation();

    const container = document.querySelector('.map-container');
    const rect = container.getBoundingClientRect();
    
    function onMove(moveEvent) {
        const x = moveEvent.clientX - rect.left;
        const y = moveEvent.clientY - rect.top;
        
        // Convert to percentage
        const perX = (x / rect.width) * 100;
        const perY = (y / rect.height) * 100;
        
        pinElement.style.left = `${perX}%`;
        pinElement.style.top = `${perY}%`;
        
        site.tempX = perX;
        site.tempY = perY;
    }

    function onUp() {
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
        
        if (site.tempX !== undefined) {
            savePinPosition(site.id, site.tempX, site.tempY);
            site.map_x = site.tempX;
            site.map_y = site.tempY;
        }
    }

    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
}

async function savePinPosition(id, x, y) {
    try {
        await API.post(`/sites/${id}/map`, { map_x: x, map_y: y });
        console.log(`Saved Site ${id}: ${x.toFixed(2)}%, ${y.toFixed(2)}%`);
    } catch (err) {
        alert('Failed to save position: ' + err.message);
    }
}

function getStatusColor(site) {
    // Logic can be expanded, currently just defaulting to green or red based on occupant
    if (site.occupant_id || site.occupant_name) return '#ef4444'; // Red
    return '#10b981'; // Green
}

function updateDebug(msg) {
    const el = document.getElementById('map-debug');
    if (el) el.textContent = msg;
}
