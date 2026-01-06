import * as API from '../api.js';

// Camp Map module
// Makes pins visible via inline styles (so it won't depend on CSS caches)
// and ensures the overlay layer is positioned above the map image.

let editMode = false;
let sitesCache = [];

function toNumber(v) {
  if (v === null || v === undefined) return null;
  if (typeof v === 'number') return Number.isFinite(v) ? v : null;
  if (typeof v === 'string') {
    const t = v.trim();
    if (!t) return null;
    const n = Number(t);
    return Number.isFinite(n) ? n : null;
  }
  return null;
}

function hasCoords(site) {
  const x = toNumber(site.map_x);
  const y = toNumber(site.map_y);
  // allow 0
  return x !== null && y !== null;
}

function statusToColour(status) {
  const s = String(status || '').toLowerCase();
  // use a small, consistent palette (no theme changes elsewhere)
  if (s.includes('occupied')) return '#ee5e78';
  if (s.includes('available')) return '#94d0a7';
  if (s.includes('hold')) return '#f2c94c';
  if (s.includes('closed')) return '#75848f';
  return '#377dff';
}

function applyPinStyles(pinEl, status) {
  pinEl.style.position = 'absolute';
  pinEl.style.width = '14px';
  pinEl.style.height = '14px';
  pinEl.style.borderRadius = '999px';
  pinEl.style.transform = 'translate(-50%, -50%)';
  pinEl.style.border = '2px solid #ffffff';
  pinEl.style.boxShadow = '0 2px 8px rgba(0,0,0,0.35)';
  pinEl.style.background = statusToColour(status);
  pinEl.style.cursor = 'pointer';
  pinEl.style.pointerEvents = 'auto';
}

function ensureOverlayLayer(mapWrapper) {
  // Ensure the wrapper is the positioning context
  mapWrapper.style.position = 'relative';
  mapWrapper.style.overflow = 'hidden';

  const img = mapWrapper.querySelector('#camp-map-img');
  if (img) {
    img.style.position = 'relative';
    img.style.zIndex = '1';
    img.style.display = 'block';
  }

  let layer = mapWrapper.querySelector('#pins-layer');
  if (!layer) {
    layer = document.createElement('div');
    layer.id = 'pins-layer';
    mapWrapper.appendChild(layer);
  }

  layer.style.position = 'absolute';
  layer.style.inset = '0';
  layer.style.zIndex = '50';
  layer.style.pointerEvents = 'auto';
  return layer;
}

function renderDebug(container, totalSites, pinsWithCoords) {
  const debug = document.createElement('div');
  debug.id = 'map-debug';
  debug.style.fontSize = '12px';
  debug.style.margin = '8px 0';
  debug.style.opacity = '0.8';
  debug.textContent = `Sites: ${totalSites} | Pins with coords: ${pinsWithCoords} | Edit mode: ${editMode ? 'ON' : 'OFF'}`;
  container.prepend(debug);
}

async function fetchSites() {
  // Uses the authenticated endpoint used by the admin map view
  return await API.get('/sites');
}

async function savePin(siteId, xPct, yPct) {
  // The API historically used PUT /sites/:id/map-pin or POST to a map-pin route.
  // We support both by trying the newer route first.
  const payload = { map_x: xPct, map_y: yPct };

  try {
    // If your backend supports this, it will work.
    return await API.post(`/sites/${siteId}/map-pin`, payload);
  } catch (e) {
    // Fallback: update the site record directly (common in many versions)
    return await API.put(`/sites/${siteId}`, payload);
  }
}

async function clearPin(siteId) {
  const payload = { map_x: null, map_y: null };
  try {
    return await API.post(`/sites/${siteId}/map-pin`, payload);
  } catch (e) {
    return await API.put(`/sites/${siteId}`, payload);
  }
}

function buildPin(site, layer, onClick) {
  const x = toNumber(site.map_x);
  const y = toNumber(site.map_y);
  if (x === null || y === null) return null;

  const pin = document.createElement('div');
  pin.className = 'map-pin';
  applyPinStyles(pin, site.status);

  pin.style.left = `${x}%`;
  pin.style.top = `${y}%`;

  // Tooltip
  const label = site.site_number ?? site.site_name ?? site.id ?? '';
  pin.title = String(label);

  pin.addEventListener('click', (ev) => {
    ev.stopPropagation();
    onClick(site);
  });

  layer.appendChild(pin);
  return pin;
}

function clearPins(layer) {
  while (layer.firstChild) layer.removeChild(layer.firstChild);
}

function updateDebug(container, totalSites, pinsWithCoords) {
  const d = container.querySelector('#map-debug');
  if (d) d.textContent = `Sites: ${totalSites} | Pins with coords: ${pinsWithCoords} | Edit mode: ${editMode ? 'ON' : 'OFF'}`;
}

export async function render(container) {
  container.innerHTML = `
    <div class="header-actions" style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
      <h1 style="margin:0;">Camp Map</h1>
      <label style="display:flex;align-items:center;gap:8px;user-select:none;">
        <input type="checkbox" id="edit-toggle" />
        <span>Enable Edit Mode</span>
      </label>
    </div>
    <div id="map-wrapper" style="margin-top:10px;">
      <img src="/public/img/map.jpg" id="camp-map-img" alt="Campsite Map" style="width:100%;height:auto;" />
      <div id="pins-layer"></div>
    </div>
    <p class="muted" style="margin-top:10px;">Tip: Turn on Edit Mode, then click the map to place a pin. Click an existing pin to remove it.</p>
  `;

  const wrapper = container.querySelector('#map-wrapper');
  const layer = ensureOverlayLayer(wrapper);

  const toggle = container.querySelector('#edit-toggle');
  toggle.checked = editMode;
  toggle.addEventListener('change', () => {
    editMode = toggle.checked;
    updateDebug(container, sitesCache.length, sitesCache.filter(hasCoords).length);
  });

  // Fetch sites and draw pins
  sitesCache = await fetchSites();
  const pinsWithCoords = sitesCache.filter(hasCoords).length;
  renderDebug(container, sitesCache.length, pinsWithCoords);

  const redraw = () => {
    clearPins(layer);
    for (const s of sitesCache) {
      if (!hasCoords(s)) continue;
      buildPin(s, layer, async (site) => {
        if (!editMode) {
          // Non-edit: could show a modal in your app; keep it simple to not disrupt structure
          return;
        }
        const ok = confirm(`Remove pin for site ${site.site_number ?? site.site_name ?? site.id}?`);
        if (!ok) return;
        await clearPin(site.id);
        // update cache
        const idx = sitesCache.findIndex(x => x.id === site.id);
        if (idx >= 0) {
          sitesCache[idx] = { ...sitesCache[idx], map_x: null, map_y: null };
        }
        updateDebug(container, sitesCache.length, sitesCache.filter(hasCoords).length);
        redraw();
      });
    }
  };

  redraw();

  // Place pin on map click in edit mode
  wrapper.addEventListener('click', async (ev) => {
    if (!editMode) return;

    const img = wrapper.querySelector('#camp-map-img');
    if (!img) return;

    const rect = img.getBoundingClientRect();
    const x = ((ev.clientX - rect.left) / rect.width) * 100;
    const y = ((ev.clientY - rect.top) / rect.height) * 100;

    // pick a site to pin
    const siteNumber = prompt('Enter site number to pin (e.g. 12):');
    if (!siteNumber) return;

    const match = sitesCache.find(s => String(s.site_number ?? '').trim() === String(siteNumber).trim());
    if (!match) {
      alert('Site not found. Please check the site number.');
      return;
    }

    await savePin(match.id, x, y);

    // update cache
    const idx = sitesCache.findIndex(s => s.id === match.id);
    if (idx >= 0) {
      sitesCache[idx] = { ...sitesCache[idx], map_x: x, map_y: y };
    }

    updateDebug(container, sitesCache.length, sitesCache.filter(hasCoords).length);
    redraw();
  });
}
