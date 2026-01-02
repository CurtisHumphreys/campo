import * as API from '../api.js';

export async function render(container) {
    container.innerHTML = `
        <div class="header-actions">
            <h1>Camps</h1>
            <button id="add-camp-btn">Create Camp</button>
        </div>
        <div class="card">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Year</th>
                        <th>Dates</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="camps-list">
                    <tr><td colspan="5">Loading...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Modal -->
        <div id="camp-modal" class="modal hidden">
            <div class="modal-content">
                <h2>Create Camp</h2>
                <form id="camp-form">
                    <input type="hidden" id="camp_id">
                    <div class="form-group">
                        <label>Camp Name</label>
                        <input type="text" id="camp_name" required>
                    </div>
                    <div class="form-group">
                        <label>Year</label>
                        <input type="number" id="camp_year" value="${new Date().getFullYear()}" required>
                    </div>
                    <div class="grid-2">
                        <div class="form-group">
                            <label>Start Date</label>
                            <input type="date" id="start_date" required>
                        </div>
                        <div class="form-group">
                            <label>End Date</label>
                            <input type="date" id="end_date" required>
                        </div>
                    </div>
                    <div class="grid-2">
                        <div class="form-group">
                            <label>On-Peak Start</label>
                            <input type="date" id="on_peak_start">
                        </div>
                        <div class="form-group">
                            <label>On-Peak End</label>
                            <input type="date" id="on_peak_end">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select id="camp_status">
                            <option value="Draft">Draft</option>
                            <option value="Active">Active</option>
                            <option value="Closed">Closed</option>
                        </select>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="secondary" id="cancel-camp-modal">Cancel</button>
                        <button type="submit">Save</button>
                    </div>
                </form>
            </div>
        </div>
    `;

    const camps = await API.get('/camps');
    renderTable(camps);

    // Modal Logic
    const modal = document.getElementById('camp-modal');
    document.getElementById('add-camp-btn').addEventListener('click', () => {
        document.getElementById('camp-form').reset();
        document.getElementById('camp_id').value = '';
        document.querySelector('#camp-modal h2').textContent = 'Create Camp';
        modal.classList.remove('hidden');
    });
    document.getElementById('cancel-camp-modal').addEventListener('click', () => {
        modal.classList.add('hidden');
    });

    document.getElementById('camp-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const id = document.getElementById('camp_id').value;
        const data = {
            name: document.getElementById('camp_name').value,
            year: document.getElementById('camp_year').value,
            start_date: document.getElementById('start_date').value,
            end_date: document.getElementById('end_date').value,
            on_peak_start: document.getElementById('on_peak_start').value,
            on_peak_end: document.getElementById('on_peak_end').value,
            status: document.getElementById('camp_status').value
        };

        try {
            if (id) {
                await API.post(`/camp/update?id=${id}`, data);
            } else {
                await API.post('/camps', data);
            }
            modal.classList.add('hidden');
            render(container);
        } catch (err) {
            alert('Error: ' + err.message);
        }
    });
}

function renderTable(camps) {
    const tbody = document.getElementById('camps-list');
    if (!tbody) return;
    if (camps.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5">No camps found</td></tr>';
        return;
    }
    tbody.innerHTML = camps.map(c => `
        <tr>
            <td>${c.name}</td>
            <td>${c.year}</td>
            <td>${c.start_date} to ${c.end_date}</td>
            <td><span class="badge ${c.status.toLowerCase()}">${c.status}</span></td>
            <td>
                <button class="small" onclick="editCamp(${c.id})">Edit</button>
                <button class="small secondary" onclick="deleteCamp(${c.id})" style="margin-left: 0.5rem; color: var(--danger); border-color: var(--danger);">Delete</button>
            </td>
            </td>
        </tr>
    `).join('');

    // Attach global handlers (quick hack for inline onclicks)
    window.editCamp = (id) => {
        const camp = camps.find(c => c.id == id);
        if (!camp) return;

        document.getElementById('camp_id').value = camp.id;
        document.getElementById('camp_name').value = camp.name;
        document.getElementById('camp_year').value = camp.year;
        document.getElementById('start_date').value = camp.start_date;
        document.getElementById('end_date').value = camp.end_date;
        document.getElementById('on_peak_start').value = camp.on_peak_start;
        document.getElementById('on_peak_end').value = camp.on_peak_end;
        document.getElementById('camp_status').value = camp.status;

        document.querySelector('#camp-modal h2').textContent = 'Edit Camp';
        document.getElementById('camp-modal').classList.remove('hidden');
    };

    window.deleteCamp = async (id) => {
        const camp = camps.find(c => c.id == id);
        if (!camp) return;

        const confirmation = prompt(`To delete camp "${camp.name}", please type "DELETE" below: `);
        if (confirmation === 'DELETE') {
            try {
                await API.post(`/ camp / delete? id = ${id}`, {});
                // Refresh
                document.querySelector('[data-link="/campo/camps"]').click();
            } catch (err) {
                alert('Error deleting camp: ' + err.message);
            }
        }
    };
}
