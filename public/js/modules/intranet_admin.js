import * as API from '../api.js';

export async function render(container) {
    container.innerHTML = `
        <div class="header-actions">
            <h1>Intranet (Public Page)</h1>
            <div class="actions-group">
                <a class="secondary small" href="/campo/intranet" target="_blank" rel="noopener">Open Intranet</a>
                <a class="secondary small" href="/campo/public-map" target="_blank" rel="noopener">Open Public Map</a>
            </div>
        </div>

        <div class="card">
            <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:center;">
                <div>
                    <div style="font-weight:600;">Active Camp</div>
                    <div id="active-camp-line" style="color: var(--muted, #64748b); margin-top:4px;">Loading…</div>
                </div>
                <button id="save-intranet" class="primary">Save</button>
            </div>
        </div>

        <div class="grid-2" style="margin-top:12px;">
            <div class="card">
                <h2 style="margin-top:0;">Current Camp Program</h2>
                <p class="muted" style="margin-top:-6px;">Shown on the public intranet page.</p>
                <textarea id="program" rows="10" style="width:100%;"></textarea>
                <div class="muted" style="font-size:0.85rem; margin-top:8px;">Tip: Use new lines. Formatting is preserved.</div>
            </div>

            <div class="card">
                <h2 style="margin-top:0;">Camp Notifications</h2>
                <p class="muted" style="margin-top:-6px;">Important updates, changes, reminders.</p>
                <textarea id="notifications" rows="10" style="width:100%;"></textarea>
                <div class="muted" style="font-size:0.85rem; margin-top:8px;">Tip: One item per line works well.</div>
            </div>
        </div>

        <div class="card" style="margin-top:12px;">
            <h2 style="margin-top:0;">Camp Events</h2>
            <p class="muted" style="margin-top:-6px;">Activities and times for the current camp.</p>
            <textarea id="events" rows="10" style="width:100%;"></textarea>
        </div>

        <div class="card" style="margin-top:12px;">
            <div class="muted" id="last-updated"></div>
        </div>
    `;

    await load();

    document.getElementById('save-intranet').addEventListener('click', async () => {
        const payload = {
            program: document.getElementById('program').value || '',
            notifications: document.getElementById('notifications').value || '',
            events: document.getElementById('events').value || '',
        };

        try {
            await API.post('/intranet', payload);
            toast('Saved');
            await load();
        } catch (e) {
            alert('Failed to save: ' + e.message);
        }
    });
}

async function load() {
    const data = await API.get('/intranet');

    const campLine = document.getElementById('active-camp-line');
    if (campLine) {
        if (!data.camp) {
            campLine.textContent = 'No active camp set. Set a camp to Active first.';
        } else {
            const c = data.camp;
            campLine.textContent = `${c.name || 'Camp'} ${c.year || ''} (${c.start_date || ''} to ${c.end_date || ''})`;
        }
    }

    const content = data.content || {};
    document.getElementById('program').value = content.program || '';
    document.getElementById('notifications').value = content.notifications || '';
    document.getElementById('events').value = content.events || '';

    const upd = content.updated_at ? new Date(content.updated_at).toLocaleString('en-AU') : '';
    const last = document.getElementById('last-updated');
    if (last) last.textContent = upd ? `Last updated: ${upd}` : '';
}

function toast(msg) {
    // Minimal toast using existing styling conventions (no new theme)
    const el = document.createElement('div');
    el.textContent = msg;
    el.style.position = 'fixed';
    el.style.left = '50%';
    el.style.bottom = '18px';
    el.style.transform = 'translateX(-50%)';
    el.style.background = '#0f172a';
    el.style.color = '#fff';
    el.style.padding = '10px 14px';
    el.style.borderRadius = '12px';
    el.style.fontSize = '0.9rem';
    el.style.zIndex = '9999';
    el.style.boxShadow = '0 10px 22px rgba(0,0,0,0.25)';
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 1200);
}
