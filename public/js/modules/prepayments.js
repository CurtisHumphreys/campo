import * as API from '../api.js';

let memberCache = [];

export async function render(container) {
    // Build the page layout including controls for search, filtering and bulk deletion.
    container.innerHTML = `
        <div class="header-actions">
            <h1>Pre-payments</h1>
            <div class="actions-group">
                <select id="camp-select-prep" class="camp-selector">
                    <option>Loading...</option>
                </select>
            </div>
            <div class="actions-group">
                <input type="text" id="prep-search" class="small-input" placeholder="Search name or transaction...">
                <select id="status-filter" class="small-input">
                    <option value="">All Statuses</option>
                    <option value="Unmatched">Unmatched</option>
                    <option value="Matched">Matched</option>
                    <option value="Partial">Partial</option>
                    <option value="Applied">Applied</option>
                </select>
                <button id="delete-all-prepayments" class="small danger">Delete All</button>
            </div>
        </div>
        <div class="glass-card">
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
                <tbody id="prepayments-list">
                    <tr><td colspan="7">Loading...</td></tr>
                </tbody>
            </table>
        </div>
        <!-- Quick Add Member Modal for prepayments -->
        <div id="pp-quick-add-modal" class="modal hidden">
            <div class="modal-content">
                <h2>Add New Member</h2>
                <form id="pp-quick-add-form">
                    <div class="form-row">
                        <label for="pp-qa-first">First Name</label>
                        <input type="text" id="pp-qa-first" required class="flex-fill">
                    </div>
                    <div class="form-row">
                        <label for="pp-qa-last">Last Name</label>
                        <input type="text" id="pp-qa-last" required class="flex-fill">
                    </div>
                    <div class="form-row">
                        <label for="pp-qa-concession">Concession</label>
                        <select id="pp-qa-concession" class="flex-fill">
                            <option value="No">No</option>
                            <option value="Yes">Yes</option>
                        </select>
                    </div>
                    <div class="form-row" style="justify-content:flex-end; gap: 1rem; margin-top:1rem;">
                        <button type="button" class="secondary" id="pp-qa-cancel">Cancel</button>
                        <button type="submit">Create &amp; Match</button>
                    </div>
                </form>
            </div>
        </div>
    `;

    const camps = await API.get('/camps');
    const campSelect = document.getElementById('camp-select-prep');
    campSelect.innerHTML = camps.map(c => `<option value="${c.id}">${c.name} (${c.year})</option>`).join('');

    // Pre-fetch members for search
    memberCache = await API.get('/members');

    // Keep a copy of the full list so we can filter client-side
    let prepaymentList = [];

    // Load initial camp's prepayments
    if (camps.length > 0) {
        loadPrepayments(camps[0].id);
    }

    campSelect.addEventListener('change', (e) => {
        loadPrepayments(e.target.value);
    });

    // Elements for filtering
    const searchInput = document.getElementById('prep-search');
    const statusFilter = document.getElementById('status-filter');
    const deleteAllBtn = document.getElementById('delete-all-prepayments');

    searchInput.addEventListener('input', applyFilters);
    statusFilter.addEventListener('change', applyFilters);

    deleteAllBtn.addEventListener('click', async () => {
        const campId = campSelect.value;
        if (!campId) return;
        if (!confirm('Are you sure you want to delete all pre-payments for this camp? This action cannot be undone.')) {
            return;
        }
        try {
            await API.post('/prepayments/delete-all', { camp_id: campId });
            // Reload list after deletion
            loadPrepayments(campId);
        } catch (e) {
            alert('Error deleting pre-payments: ' + e.message);
        }
    });

    // Quick Add Modal logic
    const qaModal = document.getElementById('pp-quick-add-modal');
    document.getElementById('pp-qa-cancel').addEventListener('click', () => qaModal.classList.add('hidden'));
    let pendingPrepaymentId = null;
    let pendingRow = null;
    document.getElementById('pp-quick-add-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const data = {
            first_name: document.getElementById('pp-qa-first').value,
            last_name: document.getElementById('pp-qa-last').value,
            fellowship: '',
            concession: document.getElementById('pp-qa-concession').value,
            site_fee_status: 'Unknown'
        };
        try {
            const res = await API.post('/members', data);
            if (res.success) {
                // Refresh member cache
                memberCache = await API.get('/members');
                // If we have a pending prepayment to match, link it now
                if (pendingPrepaymentId && pendingRow) {
                    try {
                        await API.post('/prepayments/match', { id: pendingPrepaymentId, member_id: res.id });
                        // Update local list and row UI
                        updateLocalMatch(pendingPrepaymentId, res.id, `${data.last_name}, ${data.first_name}`);
                        renderMatchOnRow(pendingRow, `${data.last_name}, ${data.first_name}`);
                    } catch (err) {
                        alert('Member created, but failed to link prepayment: ' + err.message);
                    }
                    pendingPrepaymentId = null;
                    pendingRow = null;
                }
                qaModal.classList.add('hidden');
            }
        } catch (err) {
            alert(err.message);
        }
    });

    /**
     * Fetch prepayments for a camp and refresh the local copy and table.
     */
    async function loadPrepayments(campId) {
        const tbody = document.getElementById('prepayments-list');
        tbody.innerHTML = '<tr><td colspan="7">Loading...</td></tr>';
        try {
            const list = await API.get(`/prepayments?camp_id=${campId}`);
            prepaymentList = list;
            applyFilters();
        } catch (err) {
            tbody.innerHTML = `<tr><td colspan="7" class="text-danger">Error: ${err.message}</td></tr>`;
        }
    }

    /**
     * Apply search and status filters to the prepayment list and re‑render the table.
     */
    function applyFilters() {
        const tbody = document.getElementById('prepayments-list');
        if (!prepaymentList) return;
        let filtered = prepaymentList.slice();
        const raw = searchInput.value || '';
        const term = raw.toLowerCase().trim();
        const status = statusFilter.value;
        if (term) {
            // Split into tokens so a search like "tom nobel" matches even if
            // the table shows "Nobel, Tom".  Each token must match either
            // first name, last name or transaction ID.
            const tokens = term.split(/\s+/).filter(Boolean);
            filtered = filtered.filter(p => {
                const fn = (p.first_name || '').toLowerCase();
                const ln = (p.last_name || '').toLowerCase();
                const tx = (p.transaction_id || '').toLowerCase();
                return tokens.every(t => fn.includes(t) || ln.includes(t) || tx.includes(t));
            });
        }
        if (status) {
            filtered = filtered.filter(p => (p.status || '') === status);
        }
        renderTable(filtered);
    }

    /**
     * Render table rows from the provided data. Each row includes a search box
     * for matching unmatched prepayments and displays matched members accordingly.
     */
    function renderTable(list) {
        const tbody = document.getElementById('prepayments-list');
        if (!list || list.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7">No pre-payments found.</td></tr>';
            return;
        }
        tbody.innerHTML = list.map(p => {
            const isMatched = p.status === 'Matched' && p.matched_member_id;
            const matchedName = (p.member_first_name || p.member_last_name) ? `${p.member_last_name}, ${p.member_first_name}` : (p.matched_member_id ? `ID: ${p.matched_member_id}` : '-');
            return `
                <tr data-id="${p.id}" data-status="${p.status || ''}">
                    <td>${p.first_name || '-'}</td>
                    <td>${p.last_name || '-'}</td>
                    <td>$${parseFloat(p.amount).toFixed(2)}</td>
                    <td>${p.transaction_id || '-'}</td>
                    <td><span class="badge ${isMatched ? 'paid' : 'overdue'}">${p.status || 'Unmatched'}</span></td>
                    <td>${isMatched ? `<strong>${matchedName}</strong>` : '<span class="text-muted">-</span>'}</td>
                    <td>
                        ${!isMatched ? `
                            <div class="search-box small-search">
                                <input type="text" class="match-search" placeholder="Search Member..." autocomplete="off">
                                <div class="dropdown-results hidden"></div>
                            </div>
                        ` : '<button class="small secondary btn-unmatch" disabled>Matched</button>'}
                    </td>
                </tr>
            `;
        }).join('');
        // Attach listeners for search boxes on newly rendered rows
        document.querySelectorAll('.match-search').forEach(input => {
            input.addEventListener('input', handleSearch);
            input.addEventListener('focus', handleSearch);
        });
    }

    /**
     * Handles searching for members to match a prepayment row. Shows the
     * dropdown with up to five results. If none match, offers an option
     * to add a new member prefilled with the prepayment name.
     */
    function handleSearch(e) {
        const input = e.target;
        const term = (input.value || '').toLowerCase().trim();
        const resultsBox = input.nextElementSibling;
        // Ensure width spans the search box
        resultsBox.style.width = '100%';
        // Determine the row context
        const row = input.closest('tr');

        // If no query, hide results
        if (!term) {
            resultsBox.classList.add('hidden');
            return;
        }
        // Split into tokens and filter memberCache
        const tokens = term.split(/\s+/).filter(Boolean);
        const filtered = memberCache
            .filter(m => {
                const first = (m.first_name || '').toLowerCase();
                const last = (m.last_name || '').toLowerCase();
                return tokens.every(t => first.includes(t) || last.includes(t));
            })
            .slice(0, 5);

        if (filtered.length === 0) {
            // Provide option to add new member using prepayment names
            resultsBox.innerHTML = `
                <div class="result-item no-hover">No members found</div>
                <div class="result-item add-new">Add new member</div>
            `;
            resultsBox.classList.remove('hidden');
            // Click handler for add new
            const addItem = resultsBox.querySelector('.add-new');
            addItem.addEventListener('click', () => {
                pendingPrepaymentId = row.dataset.id;
                pendingRow = row;
                // Prefill quick add form using row data
                const fn = row.children[0].textContent !== '-' ? row.children[0].textContent : '';
                const ln = row.children[1].textContent !== '-' ? row.children[1].textContent : '';
                document.getElementById('pp-qa-first').value = fn;
                document.getElementById('pp-qa-last').value = ln;
                // reset concession to default
                document.getElementById('pp-qa-concession').value = 'No';
                qaModal.classList.remove('hidden');
                resultsBox.classList.add('hidden');
            });
        } else {
            resultsBox.innerHTML = filtered.map(m => `
                <div class="result-item" data-mid="${m.id}" data-name="${m.last_name}, ${m.first_name}">
                    ${m.last_name}, ${m.first_name}
                </div>
            `).join('');
            resultsBox.classList.remove('hidden');
            // Add click listeners to items
            resultsBox.querySelectorAll('.result-item').forEach(item => {
                item.addEventListener('click', async () => {
                    const memberId = item.dataset.mid;
                    const memberName = item.dataset.name;
                    const prepayId = row.dataset.id;
                    // Optimistically update after matching
                    await matchMember(prepayId, memberId, row, memberName);
                    resultsBox.classList.add('hidden');
                });
            });
        }
    }

    /**
     * Perform the match operation and update UI without refreshing the entire table.
     */
    async function matchMember(prepayId, memberId, row, memberName) {
        try {
            await API.post('/prepayments/match', { id: prepayId, member_id: memberId });
            // Update the row display immediately
            renderMatchOnRow(row, memberName);
            // Update local data so filtered lists reflect match state
            updateLocalMatch(prepayId, memberId, memberName);
        } catch (e) {
            alert('Error linking member: ' + e.message);
        }
    }

    /**
     * Update the local cached prepaymentList to reflect a successful match.
     */
    function updateLocalMatch(prepayId, memberId, memberName) {
        if (!prepaymentList) return;
        prepaymentList = prepaymentList.map(p => {
            if (String(p.id) === String(prepayId)) {
                const parts = memberName.split(',');
                const last = parts[0] ? parts[0].trim() : '';
                const first = parts[1] ? parts[1].trim() : '';
                return {
                    ...p,
                    matched_member_id: memberId,
                    status: 'Matched',
                    member_first_name: first,
                    member_last_name: last
                };
            }
            return p;
        });
    }

    /**
     * Given a table row and member name, update the status, matched name and
     * actions cell to reflect a successful match.
     */
    function renderMatchOnRow(row, memberName) {
        // Update status badge
        const statusSpan = row.querySelector('td:nth-child(5) span');
        statusSpan.classList.remove('overdue');
        statusSpan.classList.add('paid');
        statusSpan.textContent = 'Matched';
        // Update matched member cell
        const matchCell = row.querySelector('td:nth-child(6)');
        matchCell.innerHTML = `<strong>${memberName}</strong>`;
        // Replace action cell with a disabled button
        const actionCell = row.querySelector('td:nth-child(7)');
        actionCell.innerHTML = '<button class="small secondary btn-unmatch" disabled>Matched</button>';
    }

    // Close dropdowns on click outside
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.search-box')) {
            document.querySelectorAll('.dropdown-results').forEach(d => d.classList.add('hidden'));
        }
    });
}

async function loadPrepayments(campId) {
    const tbody = document.getElementById('prepayments-list');
    tbody.innerHTML = '<tr><td colspan="7">Loading...</td></tr>';

    try {
        const list = await API.get(`/prepayments?camp_id=${campId}`);
        if (list.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7">No pre-payments found. Import them via "Import Legacy".</td></tr>';
            return;
        }

        tbody.innerHTML = list.map(p => {
            const isMatched = p.status === 'Matched' && p.matched_member_id;
            // Use member_first_name/last_name for matched names to avoid confusion with prepayment names
            const matchedName = (p.member_first_name || p.member_last_name) ? `${p.member_last_name}, ${p.member_first_name}` : `ID: ${p.matched_member_id}`;

            return `
            <tr data-id="${p.id}">
                <td>${p.first_name || '-'}</td>
                <td>${p.last_name || '-'}</td>
                <td>$${parseFloat(p.amount).toFixed(2)}</td>
                <td>${p.transaction_id || '-'}</td>
                <td><span class="badge ${isMatched ? 'paid' : 'overdue'}">${p.status}</span></td>
                <td>
                    ${isMatched ? `<strong>${matchedName}</strong>` : '<span class="text-muted">-</span>'}
                </td>
                <td>
                    ${!isMatched ? `
                        <div class="search-box small-search">
                            <input type="text" class="match-search" placeholder="Search Member..." autocomplete="off">
                            <div class="dropdown-results hidden"></div>
                        </div>
                    ` : '<button class="small secondary btn-unmatch" disabled>Matched</button>'}
                </td>
            </tr>
        `}).join('');

        // Attach listeners for search
        document.querySelectorAll('.match-search').forEach(input => {
            input.addEventListener('input', handleSearch);
            input.addEventListener('focus', handleSearch);
        });

        // Close dropdowns on click outside
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.search-box')) {
                document.querySelectorAll('.dropdown-results').forEach(d => d.classList.add('hidden'));
            }
        });

    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="7" class="text-danger">Error: ${err.message}</td></tr>`;
    }
}

function handleSearch(e) {
    const input = e.target;
    const term = input.value.toLowerCase();
    const resultsBox = input.nextElementSibling;

    // Position absolute relative to parent
    resultsBox.style.width = '100%';

    const filtered = memberCache.filter(m =>
        m.first_name.toLowerCase().includes(term) ||
        m.last_name.toLowerCase().includes(term)
    ).slice(0, 5);

    if (filtered.length === 0) {
        resultsBox.innerHTML = '<div class="result-item no-hover">No members found</div>';
    } else {
        resultsBox.innerHTML = filtered.map(m => `
            <div class="result-item" data-mid="${m.id}">
                ${m.last_name}, ${m.first_name}
            </div>
        `).join('');
    }

    resultsBox.classList.remove('hidden');

    // Add click listeners to items
    resultsBox.querySelectorAll('.result-item').forEach(item => {
        item.addEventListener('click', async () => {
            if (item.classList.contains('no-hover')) return;
            const memberId = item.dataset.mid;
            const row = input.closest('tr');
            const prepayId = row.dataset.id;

            await matchMember(prepayId, memberId, row);
        });
    });
}

async function matchMember(prepayId, memberId, row) {
    try {
        await API.post('/prepayments/match', { id: prepayId, member_id: memberId });

        // Optimistic Update
        const campId = document.getElementById('camp-select-prep').value;
        loadPrepayments(campId); // Reload to refresh state cleanly
    } catch (e) {
        alert('Error linking member: ' + e.message);
    }
}
