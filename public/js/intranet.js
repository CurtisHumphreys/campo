import * as API from './api.js';

function fmtCampSubtitle(camp) {
    if (!camp) return 'No active camp set';
    const name = camp.name ? String(camp.name).trim() : 'Active Camp';
    const year = camp.year ? ` ${camp.year}` : '';
    const start = camp.start_date ? new Date(camp.start_date).toLocaleDateString('en-AU') : '';
    const end = camp.end_date ? new Date(camp.end_date).toLocaleDateString('en-AU') : '';
    const dates = (start && end) ? ` • ${start} to ${end}` : '';
    return `${name}${year}${dates}`;
}

function setText(id, value, fallback='') {
    const el = document.getElementById(id);
    if (!el) return;
    const txt = (value ?? '').toString().trim();
    el.textContent = txt || fallback;
    el.classList.toggle('muted', !txt);
}

async function init() {
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
    } catch (e) {
        console.error(e);
        const subtitleEl = document.getElementById('camp-subtitle');
        if (subtitleEl) subtitleEl.textContent = 'Unable to load camp info';
        setText('program-content', '', 'Unable to load program.');
        setText('notifications-content', '', 'Unable to load notifications.');
        setText('events-content', '', 'Unable to load events.');
    }
}

init();
