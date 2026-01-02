import * as API from '../api.js';

export async function render(container) {
    container.innerHTML = `
        <h1>Import Data</h1>
        <div class="card">
            <div class="form-group">
                <label>Import Type</label>
                <select id="import-type">
                    <option value="legacy">Legacy Payments (Full Dump)</option>
                    <option value="prepayments">Pre-payments (ChurchSuite CSV)</option>
                    <option value="rates">Camp Rates (CSV)</option>
                </select>
            </div>

            <div id="camp-select-wrapper" class="form-group hidden">
                <label>Select Camp</label>
                <select id="import-camp-select"><option>Loading...</option></select>
            </div>

            <div class="form-group">
                <label>File (CSV)</label>
                <input type="file" id="import-file" accept=".csv">
            </div>

            <div class="form-group">
                 <a href="#" id="download-template-link" style="font-size:0.9rem; text-decoration:underline;">Download Template</a>
            </div>

            <button id="upload-btn">Upload & Import</button>
            <div id="import-status" class="hidden" style="margin-top:1rem;"></div>
            
            <div id="unmatched-prepayments-section" class="hidden" style="margin-top:2rem;">
                <h3>Unmatched Pre-payments</h3>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>First Name</th>
                            <th>Last Name</th>
                            <th>Amount</th>
                            <th>Transaction ID</th>
                            <th>Status</th>
                            <th>Matched Member</th>
                            <th style="width: 250px;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="unmatched-prep-list">
                    </tbody>
                </table>
            </div>
            
            <hr style="margin: 2rem 0;">
            <p class="text-small text-muted" id="import-hint">
                <strong>Legacy Payments:</strong> Cols: Year, First Name, Last Name, Site Type, Site Number...<br>
            </p>
        </div>
    `;

    // Load Camps
    const camps = await API.get('/camps');
    const campSelect = document.getElementById('import-camp-select');

    if (camps.length > 0) {
        campSelect.innerHTML = camps.map(c => `<option value="${c.id}">${c.name} (${c.year})</option>`).join('');
    } else {
        campSelect.innerHTML = '<option value="">No Active Camps</option>';
    }

    // Toggle Camp Select & Template Link
    const typeSelect = document.getElementById('import-type');
    const campWrapper = document.getElementById('camp-select-wrapper');
    const templateLink = document.getElementById('download-template-link');
    const hintText = document.getElementById('import-hint');

    function updateUI() {
        const type = typeSelect.value;
        // Show camp selector for ALL types now (legacy, prepayments, rates)
        if (type === 'legacy' || type === 'prepayments' || type === 'rates') {
            campWrapper.classList.remove('hidden');
        } else {
            campWrapper.classList.add('hidden'); // Should not happen with current options
        }

        // Update Template Download
        let csvContent = "";
        let filename = "template.csv";

        if (type === 'legacy') {
            // Updated Legacy Columns: Removed "Camp", kept "Year" as col 0
            csvContent = "Year,First Name,Last Name,Site Type,Site Number,Arrive,Depart,Total Nights,Pre-paid,Camp Fees,Site Fees,Total,Eftpos,Cash,Cheque,Other,Concession,Payment Date,Site Fee Year Paid,Headcount";
            filename = "legacy_payments_template.csv";
            hintText.innerHTML = `<strong>Legacy Payments:</strong> Cols: Year, First Name, Last Name, Site Type, Site Number, Arrive, Depart...`;
        } else if (type === 'prepayments') {
            csvContent = "First Name,Last Name,Amount,Transaction ID";
            filename = "prepayments_template.csv";
            hintText.innerHTML = `<strong>Pre-payments:</strong> Cols: First Name, Last Name, Amount, Transaction ID`;
        } else if (type === 'rates') {
            csvContent = "Category,Item,User Type,Amount";
            filename = "rates_template.csv";
            hintText.innerHTML = `<strong>Camp Rates:</strong> Cols: Category, Item, User Type, Amount`;
        }

        templateLink.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csvContent);
        templateLink.download = filename;
    }

    typeSelect.addEventListener('change', updateUI);
    updateUI(); // Init

    // Upload Logic
    document.getElementById('upload-btn').addEventListener('click', async () => {
        const fileInput = document.getElementById('import-file');
        const file = fileInput.files[0];
        if (!file) return alert('Please select a file');

        const type = typeSelect.value;
        const campId = campSelect.value;
        
        if (!campId) return alert('Please select a camp');

        const formData = new FormData();
        formData.append('file', file);
        formData.append('camp_id', campId); // Always append camp_id now

        let url = '/campo/api/import'; // Default Legacy
        if (type === 'prepayments') {
            url = '/campo/api/import/prepayments';
        } else if (type === 'rates') {
            url = '/campo/api/import/rates';
        }

        const status = document.getElementById('import-status');
        status.textContent = 'Importing... please wait.';
        status.classList.remove('hidden');
        status.className = '';

        try {
            const response = await fetch(url, {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            if (result.success) {
                let msg = `Success! `;
                if (result.count !== undefined) msg += `Processed ${result.count} records. `;
                if (result.matched !== undefined) msg += `Matched ${result.matched}. `;
                status.textContent = msg;
                status.classList.add('text-success');

                // If importing prepayments, fetch unmatched and display for quick matching
                if (type === 'prepayments') {
                    // Fetch unmatched prepayments for this camp
                    const unmatched = await API.get(`/prepayments?camp_id=${campId}&status=Unmatched`);
                    // Preload members for search
                    membersCache = await API.get('/members');
                    renderUnmatched(unmatched);
                }
            } else {
                throw new Error(result.message || 'Unknown Error');
            }
        } catch (err) {
            status.textContent = 'Error: ' + err.message;
            status.classList.add('text-danger');
        }
    });

    // Member search cache for matching unmatched prepayments
    let membersCache = [];
    let pendingPrepayId = null;
    let pendingPrepayRow = null;

    /**
     * Render unmatched prepayments list with search and quick-add capabilities.
     */
    function renderUnmatched(list) {
        const section = document.getElementById('unmatched-prepayments-section');
        const tbody = document.getElementById('unmatched-prep-list');
        if (!list || list.length === 0) {
            section.classList.add('hidden');
            return;
        }
        section.classList.remove('hidden');
        tbody.innerHTML = list.map(p => {
            return `
                <tr data-id="${p.id}">
                    <td>${p.first_name || '-'}</td>
                    <td>${p.last_name || '-'}</td>
                    <td>$${parseFloat(p.amount).toFixed(2)}</td>
                    <td>${p.transaction_id || '-'}</td>
                    <td><span class="badge overdue">${p.status || 'Unmatched'}</span></td>
                    <td><span class="text-muted">-</span></td>
                    <td>
                        <div class="search-box small-search">
                            <input type="text" class="um-match-search" placeholder="Search Member..." autocomplete="off">
                            <div class="dropdown-results hidden"></div>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');
        // Attach search events
        document.querySelectorAll('.um-match-search').forEach(input => {
            input.addEventListener('input', handleUMSearch);
            input.addEventListener('focus', handleUMSearch);
        });
    }

    /**
     * Handle search inside unmatched prepayment list. Mirrors functionality
     * in prepayments.js but scoped to this module.
     */
    function handleUMSearch(e) {
        const input = e.target;
        const term = input.value.toLowerCase();
        const resultsBox = input.nextElementSibling;
        const row = input.closest('tr');
        const filtered = membersCache.filter(m =>
            m.first_name.toLowerCase().includes(term) ||
            m.last_name.toLowerCase().includes(term)
        ).slice(0, 5);
        if (term && filtered.length === 0) {
            resultsBox.innerHTML = `
                <div class="result-item no-hover">No members found</div>
                <div class="result-item add-new">Add new member</div>
            `;
            resultsBox.classList.remove('hidden');
            const addItem = resultsBox.querySelector('.add-new');
            addItem.addEventListener('click', () => {
                pendingPrepayId = row.dataset.id;
                pendingPrepayRow = row;
                // Prefill quick add modal
                const fn = row.children[0].textContent !== '-' ? row.children[0].textContent : '';
                const ln = row.children[1].textContent !== '-' ? row.children[1].textContent : '';
                showQuickAdd(fn, ln);
                resultsBox.classList.add('hidden');
            });
        } else {
            resultsBox.innerHTML = filtered.map(m => `
                <div class="result-item" data-mid="${m.id}" data-name="${m.last_name}, ${m.first_name}">
                    ${m.last_name}, ${m.first_name}
                </div>
            `).join('');
            if (filtered.length > 0) {
                resultsBox.classList.remove('hidden');
                resultsBox.querySelectorAll('.result-item').forEach(item => {
                    item.addEventListener('click', async () => {
                        const memberId = item.dataset.mid;
                        const memberName = item.dataset.name;
                        const pid = row.dataset.id;
                        try {
                            await API.post('/prepayments/match', { id: pid, member_id: memberId });
                            // Update UI to show matched
                            const statusSpan = row.querySelector('td:nth-child(5) span');
                            statusSpan.classList.remove('overdue');
                            statusSpan.classList.add('paid');
                            statusSpan.textContent = 'Matched';
                            const matchCell = row.querySelector('td:nth-child(6)');
                            matchCell.innerHTML = `<strong>${memberName}</strong>`;
                            const actionCell = row.querySelector('td:nth-child(7)');
                            actionCell.innerHTML = '<button class="small secondary btn-unmatch" disabled>Matched</button>';
                            resultsBox.classList.add('hidden');
                        } catch (err) {
                            alert('Error linking member: ' + err.message);
                        }
                    });
                });
            } else {
                resultsBox.classList.add('hidden');
            }
        }
    }

    function showQuickAdd(firstName, lastName) {
        let modal = document.getElementById('im-quick-add-modal');
        if (!modal) {
            // Build modal on first use
            const div = document.createElement('div');
            div.id = 'im-quick-add-modal';
            div.className = 'modal hidden';
            div.innerHTML = `
                <div class="modal-content">
                    <h2>Add New Member</h2>
                    <form id="im-quick-add-form">
                        <div class="form-group">
                            <label for="im-qa-first">First Name</label>
                            <input type="text" id="im-qa-first" required class="flex-fill">
                        </div>
                        <div class="form-group">
                            <label for="im-qa-last">Last Name</label>
                            <input type="text" id="im-qa-last" required class="flex-fill">
                        </div>
                        <div class="form-group">
                            <label for="im-qa-concession">Concession</label>
                            <select id="im-qa-concession" class="flex-fill">
                                <option value="No">No</option>
                                <option value="Yes">Yes</option>
                            </select>
                        </div>
                        <div class="form-row" style="justify-content:flex-end; gap: 1rem; margin-top:1rem;">
                            <button type="button" class="secondary" id="im-qa-cancel">Cancel</button>
                            <button type="submit">Create &amp; Match</button>
                        </div>
                    </form>
                </div>
            `;
            container.appendChild(div);
            modal = div;
            modal.querySelector('#im-qa-cancel').addEventListener('click', () => modal.classList.add('hidden'));
        }
        modal.querySelector('#im-qa-first').value = firstName || '';
        modal.querySelector('#im-qa-last').value = lastName || '';
        modal.querySelector('#im-qa-concession').value = 'No';
        modal.classList.remove('hidden');
        
        const form = modal.querySelector('#im-quick-add-form') || modal.querySelector('form');
        form.onsubmit = async (ev) => {
            ev.preventDefault();
            const data = {
                first_name: modal.querySelector('#im-qa-first').value,
                last_name: modal.querySelector('#im-qa-last').value,
                fellowship: '',
                concession: modal.querySelector('#im-qa-concession').value,
                site_fee_status: 'Unknown'
            };
            try {
                const res = await API.post('/members', data);
                if (res.success) {
                    membersCache = await API.get('/members');
                    if (pendingPrepayId && pendingPrepayRow) {
                        try {
                            await API.post('/prepayments/match', { id: pendingPrepayId, member_id: res.id });
                            const statusSpan = pendingPrepayRow.querySelector('td:nth-child(5) span');
                            statusSpan.classList.remove('overdue');
                            statusSpan.classList.add('paid');
                            statusSpan.textContent = 'Matched';
                            const matchCell = pendingPrepayRow.querySelector('td:nth-child(6)');
                            matchCell.innerHTML = `<strong>${data.last_name}, ${data.first_name}</strong>`;
                            const actionCell = pendingPrepayRow.querySelector('td:nth-child(7)');
                            actionCell.innerHTML = '<button class="small secondary btn-unmatch" disabled>Matched</button>';
                        } catch (err) {
                            alert('Member created, but failed to link prepayment: ' + err.message);
                        }
                    }
                    pendingPrepayId = null;
                    pendingPrepayRow = null;
                    modal.classList.add('hidden');
                }
            } catch (err) {
                alert(err.message);
            }
        };
    }
}