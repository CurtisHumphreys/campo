import * as API from './api.js';

let allSites = [];

// Pan/Zoom State
let scale = 1;
let pointX = 0;
let pointY = 0;
let startX = 0;
let startY = 0;
let isDragging = false;

async function init() {
    try {
        allSites = await API.get('/public/sites-map');
        renderPins();
    } catch (e) {
        console.error("Failed to load public sites map", e);
        const el = document.getElementById('map-status');
        if (el) el.textContent = 'Unable to load map data.';
    }

    setupPanZoom();
    setupSearch();
}

function renderPins(filter = '') {
    const layer = document.getElementById('pins-layer');
    if (!layer) return;
    layer.innerHTML = '';

    allSites.forEach(site => {
        if (!site.map_x || !site.map_y) return;

        const q = filter.toLowerCase().trim();
        const matches = !q ||
            (site.occupants || '').toLowerCase().includes(q) ||
            (site.site_number || '').toLowerCase().includes(q);

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

        // Click Handler (Touchend is better for mobile to avoid ghost clicks if dragging)
        pin.addEventListener('click', (e) => {
            e.stopPropagation();
            showDetails(site);
        });
        // Prevent drag start on pins
        pin.addEventListener('touchstart', (e) => e.stopPropagation());

        layer.appendChild(pin);
    });
}

function setupSearch() {
    const input = document.getElementById('map-search');
    if (!input) return;
    input.addEventListener('input', (e) => {
        renderPins(e.target.value || '');
    });
}

function showDetails(site) {
    const modal = document.getElementById('details-modal');
    document.getElementById('dm-site-number').textContent = `Site ${site.site_number}`;
    document.getElementById('dm-status').textContent = site.status || '';
    document.getElementById('dm-occupants').textContent = site.occupants || 'None';
    modal.classList.remove('hidden');
}

function setupPanZoom() {
    const container = document.getElementById('map-scroll-wrapper');
    const content = document.getElementById('map-wrapper');
    if (!container || !content) return;

    function setTransform() {
        content.style.transform = `translate(${pointX}px, ${pointY}px) scale(${scale})`;
    }

    // Mouse drag
    container.addEventListener('mousedown', (e) => {
        isDragging = true;
        container.style.cursor = 'grabbing';
        startX = e.clientX - pointX;
        startY = e.clientY - pointY;
    });
    window.addEventListener('mouseup', () => {
        isDragging = false;
        container.style.cursor = 'grab';
    });
    container.addEventListener('mousemove', (e) => {
        if (!isDragging) return;
        e.preventDefault();
        pointX = e.clientX - startX;
        pointY = e.clientY - startY;
        setTransform();
    });

    // Wheel zoom
    container.addEventListener('wheel', (e) => {
        e.preventDefault();
        const rect = container.getBoundingClientRect();
        const x = (e.clientX - rect.left - pointX) / scale;
        const y = (e.clientY - rect.top - pointY) / scale;

        const delta = -e.deltaY;
        const factor = delta > 0 ? 1.1 : 0.9;
        const newScale = Math.min(Math.max(0.5, scale * factor), 5);

        pointX -= x * (newScale - scale);
        pointY -= y * (newScale - scale);
        scale = newScale;

        setTransform();
    }, { passive: false });

    // Touch pan (single touch)
    container.addEventListener('touchstart', (e) => {
        if (e.touches.length === 1) {
            isDragging = true;
            startX = e.touches[0].clientX - pointX;
            startY = e.touches[0].clientY - pointY;
        }
    }, { passive: true });

    container.addEventListener('touchmove', (e) => {
        if (!isDragging || e.touches.length !== 1) return;
        e.preventDefault();
        pointX = e.touches[0].clientX - startX;
        pointY = e.touches[0].clientY - startY;
        setTransform();
    }, { passive: false });

    container.addEventListener('touchend', () => {
        isDragging = false;
    }, { passive: true });

    // Close modal
    const closeBtn = document.getElementById('dm-close');
    if (closeBtn) closeBtn.addEventListener('click', () => {
        document.getElementById('details-modal').classList.add('hidden');
    });

    setTransform();
}

init();
