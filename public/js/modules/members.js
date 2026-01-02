import * as API from '../api.js';

// Helper to perform multi-token search on members.  Splits the query into
// individual tokens and ensures each token matches either the member's
// first or last name.  If no query is provided, all members match.
function memberMatchesQuery(member, query) {
    const q = (query || '').toLowerCase().trim();
    if (!q) return true;
    const tokens = q.split(/\s+/).filter(Boolean);
    const first = (member.first_name || '').toLowerCase();
    const last = (member.last_name || '').toLowerCase();
    return tokens.every(t => first.includes(t) || last.includes(t));
}

export async function render(container) {
    container.innerHTML = `
        <div class="header-actions">
            <h1>Members</h1>
            <div class="actions-group">
                <button id="import-members-btn" class="secondary">Import CSV</button>
                <button id="delete-all-members-btn" class="danger">Delete All</button>
                <button id="add-member-btn">Add Member</button>
            </div>
        </div>
        <div class="card">
            <input type="text" id="member-search" placeholder="Search members..." class="search-input">
        </div>
        <div class="card">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Fellowship</th>
                        <th>Concession</th>
                        <th>Site Fee Status</th>
                        <th>Paid Until</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="members-list">
                    <tr><td colspan="6">Loading...</td></tr>
                </tbody>
            </table>
        </div>
        
        <!-- Add/Edit Member Modal -->
        <div id="member-modal" class="modal hidden">
            <div class="modal-content">
                <h2 id="modal-title">Add Member</h2>
                <form id="member-form">
                    <input type="hidden" id="member-id">
                    <div class="form-group">
                        <label>First Name</label>
                        <input type="text" id="first_name" required>
                    </div>
                    <div class="form-group">
                        <label>Last Name</label>
                        <input type="text" id="last_name" required>
                    </div>
                    <div class="form-group">
                        <label>Fellowship</label>
                        <input type="text" id="fellowship">
                    </div>
                    <div class="form-group">
                        <label>Concession</label>
                        <select id="concession">
                            <option value="No">No</option>
                            <option value="Yes">Yes</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Site Fee Status</label>
                        <select id="site_fee_status">
                            <option value="Unknown">Unknown</option>
                            <option value="Paid">Paid</option>
                            <option value="Unpaid">Unpaid</option>
                            <option value="Overdue">Overdue</option>
                            <option value="Exempt">Exempt</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Site Fee Paid Until</label>
                        <input type="date" id="site_fee_paid_until" placeholder="YYYY-MM-DD">
                        <small class="hint">Optional. Leave blank if unknown.</small>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="secondary" id="cancel-modal">Cancel</button>
                        <button type="submit">Save</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Import Modal -->
        <div id="import-modal" class="modal hidden">
            <div class="modal-content">
                <h2>Import Members</h2>
                <p>Upload a CSV file with columns: <code>First Name, Last Name, Fellowship, Concession, Site Fee Status, Site Fee Paid Until</code>. Dates can be <code>YYYY-MM-DD</code> or Australian <code>D/M/YYYY</code>.</p>
                <div class="form-group">
                    <a href="#" id="download-template">Download CSV Template</a>
                </div>
                <form id="import-form">
                    <div class="form-group">
                        <input type="file" id="import-file" accept=".csv" required>
                    </div>
                    <div id="import-status" class="status-msg hidden"></div>
                    <div class="form-actions">
                        <button type="button" class="secondary" id="cancel-import">Close</button>
                        <button type="submit">Import</button>
                    </div>
                </form>
            </div>
        </div>
    `;

    const members = await API.get('/members');
    window.allMembers = members; // Expose for helpers if needed
    renderTable(members);

    // There is no partner selection in this simplified version, so skip populating a partner list.

    // Search
    const searchInput = document.getElementById('member-search');
    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            const term = e.target.value || '';
            const filtered = members.filter(m => memberMatchesQuery(m, term));
            renderTable(filtered);
        });
    }

    // Modal Logic
    const modal = document.getElementById('member-modal');
    const form = document.getElementById('member-form');

    document.getElementById('add-member-btn').addEventListener('click', () => {
        form.reset();
        document.getElementById('member-id').value = '';
        document.getElementById('modal-title').textContent = 'Add Member';
        modal.classList.remove('hidden');
    });

    // Quick Add Spouse removed: partner functionality is not supported in this version

    document.getElementById('cancel-modal').addEventListener('click', () => {
        modal.classList.add('hidden');
    });

    // Use the `{ once: true }` option so this handler runs only once per render,
    // preventing duplicate submissions if multiple listeners accumulate.
    // Ensure the submit handler runs only once per render to prevent duplicate submissions.
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const id = document.getElementById('member-id').value;
        const data = {
            first_name: document.getElementById('first_name').value,
            last_name: document.getElementById('last_name').value,
            fellowship: document.getElementById('fellowship').value,
            concession: document.getElementById('concession').value,
            site_fee_status: document.getElementById('site_fee_status').value,
            site_fee_paid_until: (document.getElementById('site_fee_paid_until')?.value || '').trim()
        };

        // Disable the submit button to prevent duplicate submissions
        const submitBtn = form.querySelector('button[type="submit"]');
        submitBtn.disabled = true;
        submitBtn.textContent = 'Saving...';
        try {
            if (id) {
                await API.post(`/member/update?id=${id}`, data);
            } else {
                await API.post('/members', data);
            }
            modal.classList.add('hidden');
            render(container); // Refresh
        } catch (err) {
            alert('Error: ' + err.message);
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Save';
        }
    }, { once: true });

    // Edit Handler
    window.editMember = (id) => {
        const member = members.find(m => m.id == id);
        if (member) {
            document.getElementById('member-id').value = member.id;
            document.getElementById('first_name').value = member.first_name;
            document.getElementById('last_name').value = member.last_name;
            document.getElementById('fellowship').value = member.fellowship;
            const cVal = String(member.concession).toLowerCase();
            document.getElementById('concession').value = (cVal === 'yes' || cVal === '1' || cVal === 'true') ? 'Yes' : 'No';
            document.getElementById('site_fee_status').value = member.site_fee_status;
            // Populate paid-until if present (API returns site_fee_paid_until)
            const paidUntil = (member.site_fee_paid_until || member.paid_until || '').toString().slice(0, 10);
            const paidUntilEl = document.getElementById('site_fee_paid_until');
            if (paidUntilEl) paidUntilEl.value = paidUntil;
            document.getElementById('modal-title').textContent = 'Edit Member';
            modal.classList.remove('hidden');
        }
    };

    // Import Logic
    const importModal = document.getElementById('import-modal');
    const importStatus = document.getElementById('import-status');

    document.getElementById('import-members-btn').addEventListener('click', () => {
        document.getElementById('import-form').reset();
        importStatus.classList.add('hidden');
        importModal.classList.remove('hidden');
    });

    document.getElementById('cancel-import').addEventListener('click', () => {
        importModal.classList.add('hidden');
    });

    // Delete All Logic
    const deleteBtn = document.getElementById('delete-all-members-btn');
    if (deleteBtn) {
        deleteBtn.addEventListener('click', async () => {
            const confirmation = prompt('WARNING: This will delete ALL members, payments, and site allocations.\n\nType "DELETE" to confirm:');
            if (confirmation === 'DELETE') {
                try {
                    await API.post('/members/delete-all', {});
                    alert('All members deleted.');
                    render(container);
                } catch (err) {
                    alert('Error deleting members: ' + err.message);
                }
            }
        });
    }

    // Prebuild the CSV template link once per render to avoid multiple download events.
    const templateLink = document.getElementById('download-template');
    if (templateLink) {
        const csvContent = 'First Name,Last Name,Fellowship,Concession,Site Fee Status,Site Fee Paid Until\nJohn,Doe,Sydney,No,Paid,31/12/2026';
        templateLink.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csvContent);
        templateLink.download = 'members_template.csv';
    }

    document.getElementById('import-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const fileInput = document.getElementById('import-file');
        const file = fileInput.files[0];
        if (!file) return;

        const formData = new FormData();
        formData.append('file', file);

        importStatus.textContent = 'Importing...';
        importStatus.classList.remove('hidden', 'text-success', 'text-danger');

        try {
            const response = await fetch('/campo/api/import/members', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();

            if (result.success) {
                importStatus.textContent = `Success! Imported ${result.count} new, Updated ${result.updated} existing.`;
                importStatus.classList.add('text-success');
                setTimeout(() => {
                    importModal.classList.add('hidden');
                    render(container);
                }, 2000);
            } else {
                importStatus.textContent = 'Error: ' + result.message;
                importStatus.classList.add('text-danger');
            }
        } catch (err) {
            importStatus.textContent = 'Error: ' + err.message;
            importStatus.classList.add('text-danger');
        }
    });
}

// Delete member helper. Confirms with the user and calls the API route to remove the member and associated data.
window.deleteMember = async (id) => {
    if (!id) return;
    const ok = confirm('Are you sure you want to delete this member? This will remove their allocations and payments.');
    if (!ok) return;
    try {
        const res = await API.post(`/member/delete?id=${id}`, {});
        // Reload the current members view by calling render on the current container if available.
        const container = document.getElementById('main-content') || document.body;
        await render(container);
    } catch (err) {
        alert('Error deleting member: ' + err.message);
    }
};

function renderTable(members) {
    const tbody = document.getElementById('members-list');
    if (!tbody) return; // Guard against navigation
    if (members.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6">No members found</td></tr>';
        return;
    }
    tbody.innerHTML = members.map(m => {
        // Normalise concession to Yes/No
        const concession = (m.concession == '1' || m.concession === 'Yes' || m.concession === 'yes' || m.concession === 'true') ? 'Yes' : 'No';
        // Format paid_until date.  If null or empty, show '-'
        let paidUntil = m.site_fee_paid_until;
        if (!paidUntil || paidUntil === '0000-00-00') paidUntil = '-';
        return `
        <tr>
            <td>${m.last_name}, ${m.first_name}</td>
            <td>${m.fellowship || ''}</td>
            <td>${concession}</td>
            <td><span class="badge ${m.site_fee_status.toLowerCase()}">${m.site_fee_status}</span></td>
            <td>${paidUntil}</td>
            <td>
                <button class="small" onclick="editMember(${m.id})">Edit</button>
                <button class="small danger" onclick="deleteMember(${m.id})">Delete</button>
            </td>
        </tr>`;
    }).join('');
}
