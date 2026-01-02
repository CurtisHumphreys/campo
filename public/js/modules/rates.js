import * as API from '../api.js';

export async function render(container) {
    // Provide a simplified, grid-based interface for daily camp rates. Each site type is a row,
    // and each occupant type is a column. This makes it easy to scan and edit rates like a spreadsheet.
    container.innerHTML = `
        <div class="header-actions">
            <h1>Camp Rates Configuration</h1>
            <div class="actions-group">
                <select id="camp-select-rates" class="camp-selector">
                    <option>Loading...</option>
                </select>
            </div>
        </div>
        <div class="card">
            <table class="rates-edit-table">
                <thead id="rates-header"></thead>
                <tbody id="rates-grid"></tbody>
            </table>
        </div>
    `;

    // Predefine occupant types and display labels. Keys must match the user_type values in the DB.
    const occupantTypes = [
        { key: 'Couple', label: 'Adult Couple' },
        { key: 'Single', label: 'Adult Single (+14yrs)' },
        { key: 'Concession Couple', label: 'Concession Couple' },
        { key: 'Concession Single', label: 'Concession Single' },
        { key: 'Child', label: 'Child (5-13)' },
        { key: 'Family Cap', label: 'Family Cap' },
        { key: 'Offpeak', label: 'Offpeak' },
        { key: 'Offpeak Concession Single', label: 'Offpeak Concession Single' },
        // Add annual site contributions for standard and concession.  These represent the
        // one-year site fee amounts for non-concession and concession members respectively.
        { key: 'Site Fee Standard', label: 'Site Fee (Standard)' },
        { key: 'Site Fee Concession', label: 'Site Fee (Concession)' }
    ];

    // Common site types. Could be extended or loaded from DB if needed.
    const siteTypes = [
        'Unpowered Site',
        'Powered Site',
        'Dorms (KFC)',
        'Family Room',
        'Special Use',
        'Day Trip'
    ];

    const camps = await API.get('/camps');
    const campSelect = document.getElementById('camp-select-rates');
    campSelect.innerHTML = camps.map(c => `<option value="${c.id}">${c.name} (${c.year})</option>`).join('');

    // Build header row
    const headerRow = ['<th>Site Type</th>'];
    occupantTypes.forEach(occ => {
        headerRow.push(`<th>${occ.label}</th>`);
    });
    document.getElementById('rates-header').innerHTML = `<tr>${headerRow.join('')}</tr>`;

    // Load rates and construct grid
    if (camps.length > 0) {
        loadGrid(camps[0].id);
    }
    campSelect.addEventListener('change', (e) => loadGrid(e.target.value));

    async function loadGrid(campId) {
        const gridBody = document.getElementById('rates-grid');
        gridBody.innerHTML = '<tr><td colspan="10">Loading...</td></tr>';
        try {
            const rates = await API.get(`/rates?camp_id=${campId}`);
            // Build a map for quick lookup by item + user_type
            const rateMap = {};
            rates.forEach(r => {
                const key = `${r.item}|${r.user_type}`;
                rateMap[key] = { id: r.id, amount: r.amount, category: r.category };
            });
            // Build rows
            const rowsHtml = siteTypes.map(site => {
                const cells = [`<th>${site}</th>`];
                occupantTypes.forEach(occ => {
                    const key = `${site}|${occ.key}`;
                    const rec = rateMap[key];
                    const val = rec ? parseFloat(rec.amount).toFixed(2) : '';
                    const rateId = rec ? rec.id : '';
                    // Each cell is an input for editing
                    cells.push(`<td><input type="number" step="0.01" class="rate-input" data-id="${rateId}" data-site="${site}" data-type="${occ.key}" value="${val}" placeholder="-" /></td>`);
                });
                return `<tr>${cells.join('')}</tr>`;
            }).join('');
            gridBody.innerHTML = rowsHtml;

            // Attach change handlers to each input to save updates
            gridBody.querySelectorAll('.rate-input').forEach(input => {
                input.addEventListener('change', async (ev) => {
                    const inp = ev.target;
                    const newVal = inp.value === '' ? null : parseFloat(inp.value);
                    const id = inp.dataset.id;
                    const site = inp.dataset.site;
                    const type = inp.dataset.type;
                    const data = {
                        camp_id: campId,
                        category: 'Daily Rate',
                        item: site,
                        user_type: type,
                        amount: newVal
                    };
                    try {
                        if (id) {
                            // Update existing
                            await API.post(`/rate/update?id=${id}`, data);
                        } else {
                            // Create new. Do not create if no value entered.
                            if (newVal !== null) {
                                const res = await API.post('/rates', data);
                                // Set the new id so subsequent edits use update
                                inp.dataset.id = res.id;
                            }
                        }
                    } catch (err) {
                        alert('Error saving rate: ' + err.message);
                    }
                });
            });
        } catch (err) {
            gridBody.innerHTML = `<tr><td colspan="10" class="text-danger">Error: ${err.message}</td></tr>`;
        }
    }

    // Close the render function definition
}
