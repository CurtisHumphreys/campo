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
        allSites = await API.get('/sites');
        renderPins();
    } catch (e) {
        console.error("Failed to load sites", e);
    }

    // Search
    document.getElementById('map-search').addEventListener('input', (e) => {
        const term = e.target.value.toLowerCase();
        document.querySelectorAll('.map-pin').forEach(pin => {
            const siteNum = pin.dataset.site.toLowerCase();
            const occ = pin.dataset.occupant.toLowerCase();
            const match = siteNum.includes(term) || occ.includes(term);
            
            pin.style.opacity = (term === '' || match) ? '1' : '0.2';
            pin.classList.toggle('highlight', match && term !== '');
            
            // If match, maybe center map? (Optional enhancement)
        });
    });

    document.getElementById('close-details').onclick = () => document.getElementById('details-modal').classList.add('hidden');
    
    // Initialize Pan/Zoom
    initPanZoom();
}

function renderPins() {
    const layer = document.getElementById('pins-layer');
    layer.innerHTML = '';

    allSites.forEach(site => {
        if (site.map_x && site.map_y) {
            const pin = document.createElement('div');
            pin.className = `map-pin status-${(site.status || 'Available').toLowerCase()}`;
            pin.style.left = `${site.map_x}%`;
            pin.style.top = `${site.map_y}%`;
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
        }
    });
}

function showDetails(site) {
    const modal = document.getElementById('details-modal');
    document.getElementById('dm-site-number').textContent = `Site ${site.site_number}`;
    document.getElementById('dm-status').textContent = site.status;
    document.getElementById('dm-occupants').textContent = site.occupants || 'None';
    modal.classList.remove('hidden');
}

function initPanZoom() {
    const container = document.getElementById('map-scroll-wrapper');
    const content = document.getElementById('map-wrapper');

    let panning = false;
    let startCoords = { x: 0, y: 0 };
    let lastTransform = { x: 0, y: 0, scale: 1 };
    
    // Set initial scale to fit width if image is huge
    // (Optional: logic to auto-fit)

    const updateTransform = () => {
        content.style.transform = `translate(${lastTransform.x}px, ${lastTransform.y}px) scale(${lastTransform.scale})`;
    };

    container.addEventListener('mousedown', (e) => {
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
        const newScale = Math.min(Math.max(0.5, lastTransform.scale * factor), 5); // Limit zoom

        lastTransform.x -= xs * (newScale - lastTransform.scale);
        lastTransform.y -= ys * (newScale - lastTransform.scale);
        lastTransform.scale = newScale;
        
        updateTransform();
    });

    // Touch Support
    let lastTouchDistance = 0;
    
    container.addEventListener('touchstart', (e) => {
        if (e.touches.length === 1) {
            panning = true;
            startCoords = { x: e.touches[0].clientX - lastTransform.x, y: e.touches[0].clientY - lastTransform.y };
        } else if (e.touches.length === 2) {
            panning = false;
            lastTouchDistance = getDistance(e.touches);
        }
    });

    container.addEventListener('touchmove', (e) => {
        e.preventDefault(); // Prevent page scroll
        if (e.touches.length === 1 && panning) {
            lastTransform.x = e.touches[0].clientX - startCoords.x;
            lastTransform.y = e.touches[0].clientY - startCoords.y;
            updateTransform();
        } else if (e.touches.length === 2) {
            const dist = getDistance(e.touches);
            const mid = getMidpoint(e.touches);
            
            // Calculate zoom center relative to content
            const xs = (mid.x - container.getBoundingClientRect().left - lastTransform.x) / lastTransform.scale;
            const ys = (mid.y - container.getBoundingClientRect().top - lastTransform.y) / lastTransform.scale;

            const scaleChange = dist / lastTouchDistance;
            const newScale = Math.min(Math.max(0.5, lastTransform.scale * scaleChange), 5);

            lastTransform.x -= xs * (newScale - lastTransform.scale);
            lastTransform.y -= ys * (newScale - lastTransform.scale);
            lastTransform.scale = newScale;
            lastTouchDistance = dist;
            
            updateTransform();
        }
    });

    container.addEventListener('touchend', (e) => {
        if (e.touches.length < 2) {
            lastTouchDistance = 0;
        }
        if (e.touches.length === 0) {
            panning = false;
        }
    });
}

function getDistance(touches) {
    const dx = touches[0].clientX - touches[1].clientX;
    const dy = touches[0].clientY - touches[1].clientY;
    return Math.sqrt(dx * dx + dy * dy);
}

function getMidpoint(touches) {
    return {
        x: (touches[0].clientX + touches[1].clientX) / 2,
        y: (touches[0].clientY + touches[1].clientY) / 2
    };
}

init();