import * as API from '../api.js';

let editMode = false;

function toNum(v) {
  const n = Number(v);
  return Number.isFinite(n) ? n : null;
}

function isValidCoord(n) {
  // allow 0..100 (0 is valid)
  return typeof n === 'number' && Number.isFinite(n) && n >= 0 && n <= 100;
}

function ensureOverlaySized(wrapper, img, overlay) {
  if (!wrapper || !img || !overlay) return;

  const sync = () => {
    // Make sure wrapper/overlay have a real height that matches the rendered image
    const imgRect = img.getBoundingClientRect();
    if (imgRect.height > 0) {
      // Force wrapper height so absolute overlay has area to render into
      const currentWrapperRect = wrapper.getBoundingClientRect();
      if (currentWrapperRect.height < imgRect.height - 1) {
        wrapper.style.height = `${imgRect.height}px`;
      }
      overlay.style.height = `${imgRect.height}px`;
    }
  };

  // on load + resize
  if (img.complete) sync();
  img.addEventListener('load', sync);
  window.addEventListener('resize', sync);

  // also run a little after render (fonts/layout settling)
  setTimeout(sync, 50);
  setTimeout(sync, 250);
}

function makePin(site) {
  const pin = document.createElement('div');
  pin.className = 'map-pin';
  pin.dataset.siteId = site.id;

  // Inline styles: avoids any CSS cache weirdness
  pin.style.position = 'absolute';
  pin.style.width = '14px';
  pin.style.height = '14px';
  pin.style.borderRadius = '50%';
  pin.style.transform = 'translate(-50%, -50%)';
  pin.style.border = '2px solid #fff';
  pin.style.boxShadow = '0 4px 10px rgba(0,0,0,0.25)';
  pin.style.cursor = 'pointer';

  // colour by status if available
  const status = (site.status || '').toLowerCase();
  let colour = '#2563eb'; // default blue
  if (status.includes('occupied')) colour = '#ef4444';
  else if (status.includes('available')) colour = '#22c55e';
  else if (status.includes('spare')) colour = '#f59e0b';
  pin.style.background = colour;

  return pin;
}

function renderPins(overlay, sites, onPinClick) {
  overlay.innerHTML = '';

  let pinsWithCoords = 0;

  sites.forEach(site => {
    const x = toNum(site.map_x);
    const y = toNum(site.map_y);

    if (!isValidCoord(x) || !isValidCoord(y)) return;
    pinsWithCoords++;

    const pin = makePin(site);
    pin.style.left = `${x}%`;
    pin.style.top = `${y}%`;

    pin.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      onPinClick(site);
    });

    overlay.appendChild(pin);
  });

  return pinsWithCoords;
}

async function fetchSites() {
  // authenticated endpoint
  return await API.get('/sites');
}

export async function render(container) {
  container.innerHTML = `
    <div class="card">
      <div style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
        <h1 style="margin:0;">Camp Map</h1>
        <label style="display:flex; align-items:center; gap:8px; user-select:none;">
          <input type="checkbox" id="toggleEdit" />
          <span>Enable Edit Mode</span>
        </label>
      </div>

      <!-- v39 debug line -->
      <div id="mapDebug" style="margin-top:10px; font-size: 13px; opacity: 0.85;">
        Map module v39 loaded. Fetching sites...
      </div>

      <div id="map-wrapper" style="margin-top:12px; width:100%; position:relative;">
        <img
          id="camp-map-img"
          src="/public/img/map.jpg"
          alt="Campsite Map"
          style="display:block; width:100%; height:auto; position:relative; z-index:1;"
        />
        <div
          id="pins-layer"
          style="position:absolute; left:0; top:0; right:0; bottom:0; z-index:50; pointer-events:auto;"
        ></div>
      </div>
    </div>
  `;

  const toggle = container.querySelector('#toggleEdit');
  const debug = container.querySelector('#mapDebug');
  const wrapper = container.querySelector('#map-wrapper');
  const img = container.querySelector('#camp-map-img');
  const overlay = container.querySelector('#pins-layer');

  // Make sure overlay has real height (this was the likely cause of "DOM pins exist but invisible")
  ensureOverlaySized(wrapper, img, overlay);

  let sites = [];
  try {
    sites = await fetchSites();
  } catch (e) {
    console.error(e);
    debug.textContent = 'Failed to fetch sites (see console).';
    return;
  }

  const pinsWithCoords = renderPins(overlay, sites, async (site) => {
    if (!editMode) {
      // non-edit mode: simple info modal (keep minimal)
      alert(`${site.site_name || site.name || 'Site'}\nStatus: ${site.status || ''}`);
      return;
    }

    // edit mode: remove pin
    if (!confirm(`Remove pin from ${site.site_name || site.name || 'this site'}?`)) return;

    try {
      await API.post(`/sites/${site.id}/map`, { map_x: null, map_y: null });
      // refresh local + rerender
      site.map_x = null;
      site.map_y = null;
      const count = renderPins(overlay, sites, () => {});
      debug.textContent = `Sites: ${sites.length} | Pins with coords: ${count}`;
    } catch (err) {
      console.error(err);
      alert('Failed to remove pin.');
 See console.');
    }
  });

  debug.textContent = `Sites: ${sites.length} | Pins with coords: ${pinsWithCoords}`;

  toggle.addEventListener('change', () => {
    editMode = !!toggle.checked;
  });

  // Clicking on map in edit mode -> add pin
  overlay.addEventListener('click', async (e) => {
    if (!editMode) return;

    // calculate percent coords relative to wrapper
    const rect = wrapper.getBoundingClientRect();
    const px = ((e.clientX - rect.left) / rect.width) * 100;
    const py = ((e.clientY - rect.top) / rect.height) * 100;

    // choose site to pin
    const siteIdStr = prompt('Enter Site ID to pin at this location (e.g. 123):');
    const siteId = Number(siteIdStr);
    if (!Number.isFinite(siteId)) return;

    const site = sites.find(s => Number(s.id) === siteId);
    if (!site) {
      alert('Site not found.');
      return;
    }

    try {
      await API.post(`/sites/${site.id}/map`, { map_x: px, map_y: py });
      site.map_x = px;
      site.map_y = py;
      const count = renderPins(overlay, sites, (s) => {});
      debug.textContent = `Sites: ${sites.length} | Pins with coords: ${count}`;
    } catch (err) {
      console.error(err);
      alert('Failed to save pin.');
    }
  });
}
