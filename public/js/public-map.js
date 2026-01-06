import * as API from './api.js';

let allSites = [];

// Pan/Zoom State
let lastTransform = { x: 0, y: 0, scale: 1 };
let isDragging = false;
let startX = 0, startY = 0;

// Initialize
init();

async function init() {
    // 1. Ensure DOM elements exist before doing anything
    const wrapper = document.getElementById('map-scroll-wrapper');
    const container = document.getElementById('map-wrapper');
    const layer = document.getElementById('pins-layer');

    if (!wrapper || !container || !layer) {
        console.error("Map DOM elements missing. Check HTML IDs.");
        return;
    }

    // 2. Fetch Data
    try {
        console.log("Fetching public map sites...");
        // Fallback to /sites if public endpoint fails (though auth might block it)
        try {
             allSites = await API.get('/public/sites-map'); 
        } catch (e) {
             console.warn("Public endpoint failed, trying standard...", e);
             allSites = await API.get('/sites');
        }
        
        console.log("Sites loaded:", allSites.length);
        renderPins();
    } catch (e) {
        console.error("Failed to load map data", e);
    }

    setupSearch();
    setupModal();
    initPanZoom();
}

function renderPins() {
    const layer = document.getElementById('pins-layer');
    if (!layer) return;
    
    layer.innerHTML = '';
    let renderedCount = 0;

    allSites.forEach(site => {
        // Ensure coordinates are valid numbers
        if (!site.map_x || !site.map_y) return;

        const pin = document.createElement('div');
        pin.className = 'map-pin';
        
        // Position
        pin.style.left = `${site.map_x}%`;
        pin.style.top = `${site.map_y}%`;
        
        // Search Metadata
        pin.dataset.site = (site.site_number || '').toString();
        
        // Handle occupant name (could be nested or direct depending on endpoint)
        let occupant = site.occupant_name || '';
        if (site.occupant && typeof site.occupant === 'string') occupant = site.occupant;
        pin.dataset.occupant = occupant.toLowerCase();
        
        // Status Color
        const status = site.status || (site.occupant_name ? 'Occupied' : 'Available');
        if (status === 'Occupied') pin.style.backgroundColor = '#ef4444'; // Red
        else if (status === 'Reserved') pin.style.backgroundColor = '#f59e0b'; // Amber
        else pin.style.backgroundColor = '#10b981'; // Green

        // Interaction
        pin.onclick = (e) => {
            e.stopPropagation();
            showSiteDetails(site);
        };

        layer.appendChild(pin);
        renderedCount++;
    });

    console.log(`Rendered ${renderedCount} pins.`);
}

function showSiteDetails(site) {
    const modal = document.getElementById('details-modal');
    if(!modal) return;

    document.getElementById('dm-site-number').textContent = `Site ${site.site_number}`;
    document.getElementById('dm-status').textContent = site.status || (site.occupant_name ? 'Occupied' : 'Available');
    document.getElementById('dm-occupants').textContent = site.occupant_name || '-';
    
    modal.classList.remove('hidden');
}

function setupSearch() {
    const searchInput = document.getElementById('map-search');
    if (!searchInput) return;

    searchInput.addEventListener('input', (e) => {
        const term = e.target.value.toLowerCase();
        document.querySelectorAll('.map-pin').forEach(pin => {
            const siteNum = pin.dataset.site.toLowerCase();
            const occ = pin.dataset.occupant || '';
            const match = siteNum.includes(term) || occ.includes(term);
            
            if (term === '') {
                pin.style.opacity = '1';
                pin.classList.remove('highlight');
            } else {
                pin.style.opacity = match ? '1' : '0.1';
                pin.classList.toggle('highlight', match);
            }
        });
    });
}

function setupModal() {
    const close = document.getElementById('close-details');
    if(close) close.onclick = () => document.getElementById('details-modal').classList.add('hidden');
}

function initPanZoom() {
    const container = document.getElementById('map-wrapper');
    const wrapper = document.getElementById('map-scroll-wrapper');
    if (!container || !wrapper) return;

    // Mouse Drag
    wrapper.addEventListener('mousedown', e => {
        isDragging = true;
        startX = e.clientX - lastTransform.x;
        startY = e.clientY - lastTransform.y;
        container.style.cursor = 'grabbing';
    });

    window.addEventListener('mousemove', e => {
        if (!isDragging) return;
        e.preventDefault();
        lastTransform.x = e.clientX - startX;
        lastTransform.y = e.clientY - startY;
        updateTransform();
    });

    window.addEventListener('mouseup', () => {
        isDragging = false;
        container.style.cursor = 'grab';
    });
    
    // Wheel Zoom
    wrapper.addEventListener('wheel', e => {
        e.preventDefault();
        const scaleAmount = -e.deltaY * 0.001;
        const newScale = Math.min(Math.max(0.5, lastTransform.scale * (1 + scaleAmount)), 5);
        lastTransform.scale = newScale;
        updateTransform();
    }, { passive: false });

    function updateTransform() {
        container.style.transform = `translate(${lastTransform.x}px, ${lastTransform.y}px) scale(${lastTransform.scale})`;
    }
}
