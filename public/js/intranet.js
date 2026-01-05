import * as API from './api.js';

// Utility to set text content and toggle muted styling
function setText(id, value, fallback = '') {
  const el = document.getElementById(id);
  if (!el) return;
  const text = (value ?? '').toString().trim();
  el.textContent = text || fallback;
  if (text) {
    el.classList.remove('muted');
  } else {
    el.classList.add('muted');
  }
}

// Format camp subtitle (name, year, dates)
function fmtCampSubtitle(camp) {
  if (!camp) return 'No active camp set';
  const name = camp.name ? String(camp.name).trim() : 'Active Camp';
  const year = camp.year ? ` ${camp.year}` : '';
  const start = camp.start_date ? new Date(camp.start_date).toLocaleDateString('en-AU') : '';
  const end = camp.end_date ? new Date(camp.end_date).toLocaleDateString('en-AU') : '';
  const dates = (start && end) ? ` • ${start} to ${end}` : '';
  return `${name}${year}${dates}`;
}

async function loadContent() {
  try {
    const data = await API.get('/public/intranet');
    const camp = data.camp || null;
    const content = data.content || {};

    const subtitleEl = document.getElementById('camp-subtitle');
    if (subtitleEl) subtitleEl.textContent = fmtCampSubtitle(camp);

    setText('program-content', content.program, 'No program posted yet.');
    setText('notifications-content', content.notifications, 'No notifications right now.');
    setText('events-content', content.events, 'No events listed yet.');

    const updated = content.updated_at ? new Date(content.updated_at).toLocaleString('en-AU') : '';
    const metaEl = document.getElementById('program-updated');
    if (metaEl) metaEl.textContent = updated ? `Updated ${updated}` : '';
  } catch (err) {
    console.error(err);
    // Show generic failure messages
    const subtitleEl = document.getElementById('camp-subtitle');
    if (subtitleEl) subtitleEl.textContent = 'Unable to load camp';
    setText('program-content', '', 'Unable to load program.');
    setText('notifications-content', '', 'Unable to load notifications.');
    setText('events-content', '', 'Unable to load events.');
  }
}

// Install prompt handling
let deferredPrompt = null;
function setupInstallPrompt() {
  const installBtnId = 'install-intranet-btn';
  let installButton = document.getElementById(installBtnId);
  window.addEventListener('beforeinstallprompt', (e) => {
    // Prevent the mini-infobar from appearing on mobile
    e.preventDefault();
    deferredPrompt = e;
    // Show install button if not already there
    if (!installButton) {
      installButton = document.createElement('button');
      installButton.id = installBtnId;
      installButton.textContent = 'Install App';
      installButton.style.position = 'fixed';
      installButton.style.bottom = '1rem';
      installButton.style.left = '50%';
      installButton.style.transform = 'translateX(-50%)';
      installButton.style.padding = '0.75rem 1.25rem';
      installButton.style.background = '#2563eb';
      installButton.style.color = '#fff';
      installButton.style.border = 'none';
      installButton.style.borderRadius = '0.75rem';
      installButton.style.boxShadow = '0 2px 4px rgba(0,0,0,0.15)';
      installButton.style.zIndex = '9999';
      installButton.style.fontSize = '1rem';
      installButton.style.cursor = 'pointer';
      document.body.appendChild(installButton);
    } else {
      installButton.style.display = 'block';
    }
    installButton.addEventListener('click', async () => {
      if (!deferredPrompt) return;
      installButton.style.display = 'none';
      deferredPrompt.prompt();
      const { outcome } = await deferredPrompt.userChoice;
      deferredPrompt = null;
      console.log('User response to the install prompt:', outcome);
    }, { once: true });
  });
}

// Initialize intranet page
function init() {
  loadContent();
  setupInstallPrompt();
}

init();