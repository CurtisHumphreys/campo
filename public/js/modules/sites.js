import * as API from '../api.js';

let allSites = [];
let allMembers = []; // Cache for matching
let waitlistData = []; // Cache for sorting
let currentFilter = 'All';
let searchQuery = '';
let currentSort = { key: 'created_at', dir: 'desc' };

export async function render(container) {
    container.innerHTML = `
        <div class="header-actions">
            <h1>Sites</h1>
            <div class="actions-group">
                <button id="view-waitlist-btn" class="secondary">Waitlist</button>
                <button id="import-sites-btn" class="secondary">Import</button>
                <button id="add-site-btn">Add Site</button>
            </div>
        </div>

        <div class="site-controls" style="position: sticky; top: 0; background: var(--background); z-index: 10; padding-bottom: 0.5rem;">
            <div class="card" style="padding: 0.75rem; margin-bottom: 1rem;">
                <input type="text" id="site-search" placeholder="Search Site # or Name..." class="search-input" style="margin-bottom: 0.75rem;">
                
                <div class="filter-bar">
                    <button class="filter-chip active" data-status="All">All</button>
                    <button class="filter-chip" data-status="Available">Available</button>
                    <button class="filter-chip" data-status="Allocated">Allocated</button>
                    <button class="filter-chip" data-status="Inactive">Inactive</button>
                </div>
                
                <div style="display:flex; justify-content:space-between; font-size:0.8rem; color:var(--text-muted);">
                    <span>Total: <strong id="count-total">0</strong></span>
                    <span>Free: <strong id="count-free" style="color:var(--success);">0</strong></span>
                </div>
            </div>
        </div>

        <div id="sites-grid" class="site-grid">
            <div style="grid-column: 1/-1; text-align: center; padding: 2rem;">Loading sites...</div>
        </div>

        <!-- Site Modal -->
        <div id="site-modal" class="modal hidden">
            <div class="modal-content">
                <h2 id="site-modal-title">Edit Site</h2>
                <form id="site-form">
                    <input type="hidden" id="site_id">
                    <div class="grid-2">
                        <div class="form-group"><label>Site Number</label><input type="text" id="site_number" required></div>
                        <div class="form-group"><label>Section</label><input type="text" id="site_section"></div>
                    </div>
                    <div class="grid-2">
                        <div class="form-group">
                            <label>Type</label>
                            <select id="site_type">
                                <option value="Powered">Powered</option>
                                <option value="Unpowered">Unpowered</option>
                                <option value="Cabin">Cabin</option>
                                <option value="Glamping">Glamping</option>
                                <option value="Dorm">Dorm</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Status</label>
                            <select id="site_status">
                                <option value="Available">Available</option>
                                <option value="Allocated">Allocated</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group" id="occupant-field">
                        <label>Add Occupant (Search)</label>
                        <input type="text" id="site_occupant" list="members-datalist" placeholder="Type to search...">
                        <datalist id="members-datalist"></datalist>
                        <input type="hidden" id="site_member_id">
                    </div>
                    
                    <div id="current-occupants-list" style="margin-bottom:1rem;"></div>

                    <div class="form-actions">
                        <button type="button" class="secondary" id="cancel-site-modal">Cancel</button>
                        <button type="submit">Save</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Waitlist Modal -->
        <div id="waitlist-modal" class="modal hidden" style="align-items:flex-start; padding-top:2rem;">
            <div class="modal-content" style="max-width:95vw; width:1200px; max-height:90vh; overflow-y:auto;">
                <div style="display:flex; justify-content:space-between; margin-bottom:1rem;">
                    <h2>Waitlist Submissions</h2>
                    <button class="small secondary" id="close-waitlist">Close</button>
                </div>
                <div style="overflow-x:auto;">
                    <table class="data-table" style="font-size:0.85rem;">
                        <thead>
                            <tr>
                                <th class="sortable" data-sort="score">Score</th>
                                <th class="sortable" data-sort="priority">Priority</th>
                                <th class="sortable" data-sort="created_at">Submission Date</th>
                                <th class="sortable" data-sort="name">Name</th>
                                <th class="sortable" data-sort="site_type">Type</th>
                                <th>Details</th>
                                <th>Considerations</th>
                                <th>Allocation</th>
                            </tr>
                        </thead>
                        <tbody id="waitlist-tbody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    `;

    // 1. Fetch Data
    try {
        const [sitesData, membersData] = await Promise.all([
            API.get('/sites'),
            API.get('/members')
        ]);
        
        allMembers = membersData;
        
        // Fix numeric sorting
        allSites = sitesData.sort((a, b) => {
            return a.site_number.localeCompare(b.site_number, undefined, { numeric: true, sensitivity: 'base' });
        });

        const datalist = document.getElementById('members-datalist');
        if (datalist) {
            datalist.innerHTML = membersData.map(m => 
                `<option value="${m.last_name}, ${m.first_name}" data-id="${m.id}">`
            ).join('');
        }

        updateView();

    } catch (e) {
        document.getElementById('sites-grid').innerHTML = `<div style="color:red; text-align:center;">Error: ${e.message}</div>`;
    }

    // 2. Listeners
    searchQuery = ''; 
    const searchInput = document.getElementById('site-search');
    if (searchInput) {
        searchInput.value = ''; 
        searchInput.addEventListener('input', (e) => {
            searchQuery = e.target.value.toLowerCase();
            updateView();
        });
    }

    document.querySelectorAll('.filter-chip').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.filter-chip').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            currentFilter = btn.dataset.status;
            updateView();
        });
    });

    setupModals(container);
}

function updateView() {
    const grid = document.getElementById('sites-grid');
    if (!grid) return;

    const filtered = allSites.filter(s => {
        if (currentFilter !== 'All' && s.status !== currentFilter) return false;
        if (searchQuery) {
            const num = (s.site_number || '').toLowerCase();
            const occ = (s.occupants || '').toLowerCase();
            return num.includes(searchQuery) || occ.includes(searchQuery);
        }
        return true;
    });

    document.getElementById('count-total').textContent = allSites.length;
    document.getElementById('count-free').textContent = allSites.filter(s => s.status === 'Available').length;

    if (filtered.length === 0) {
        grid.innerHTML = `<div style="grid-column:1/-1; text-align:center; color:var(--text-muted); padding:2rem;">No sites found.</div>`;
        return;
    }

    grid.innerHTML = filtered.map(s => {
        const statusClass = `status-${s.status.toLowerCase()}`;
        const isFree = s.status === 'Available';
        let occDisplay = '';
        if (s.occupants_list && s.occupants_list.length > 0) {
            occDisplay = s.occupants_list.map(o => o.name).join(', ');
        } else {
            occDisplay = s.occupants || (isFree ? 'Empty' : 'Unknown');
        }
        
        return `
        <div class="site-card ${statusClass}">
            <div class="site-header">
                <div class="site-number">${s.site_number}</div>
                <span class="badge ${s.status.toLowerCase()}">${s.status}</span>
            </div>
            <div class="site-type">${s.site_type || 'Site'}</div>
            <div class="site-body">
                ${!isFree ? `<div class="site-occupant">${occDisplay}</div>` : ''}
            </div>
            <div class="site-actions">
                ${isFree 
                    ? `<button class="small" onclick="window.editSite(${s.id})">Allocate</button>`
                    : `<button class="small secondary" onclick="window.editSite(${s.id})">Manage</button>`
                }
            </div>
        </div>
        `;
    }).join('');
}

function setupModals(container) {
    const siteModal = document.getElementById('site-modal');
    const siteForm = document.getElementById('site-form');
    const waitModal = document.getElementById('waitlist-modal');

    document.getElementById('add-site-btn').addEventListener('click', () => {
        siteForm.reset();
        document.getElementById('site_id').value = '';
        document.getElementById('current-occupants-list').innerHTML = '';
        document.getElementById('site-modal-title').textContent = 'Add Site';
        siteModal.classList.remove('hidden');
    });

    document.getElementById('cancel-site-modal').addEventListener('click', () => siteModal.classList.add('hidden'));

    document.getElementById('site_occupant').addEventListener('change', (e) => {
        const val = e.target.value;
        const options = document.querySelectorAll('#members-datalist option');
        for (let opt of options) {
            if (opt.value === val) {
                document.getElementById('site_member_id').value = opt.getAttribute('data-id');
                break;
            }
        }
    });

    // Global Edit Function
    window.editSite = (id) => {
        const site = allSites.find(s => s.id == id);
        if (!site) return;

        document.getElementById('site_id').value = site.id;
        document.getElementById('site_number').value = site.site_number;
        document.getElementById('site_section').value = site.section;
        document.getElementById('site_type').value = site.site_type;
        document.getElementById('site_status').value = site.status;
        
        document.getElementById('site_occupant').value = '';
        document.getElementById('site_member_id').value = '';

        // Render Current Occupants with Delete Button
        const occContainer = document.getElementById('current-occupants-list');
        occContainer.innerHTML = '';
        if (site.occupants_list && site.occupants_list.length > 0) {
            occContainer.innerHTML = `<label>Current Occupants:</label>` + site.occupants_list.map(o => `
                <div style="display:flex; justify-content:space-between; align-items:center; background:var(--background); padding:0.5rem; border-radius:4px; margin-bottom:0.25rem;">
                    <span>${o.name}</span>
                    <button type="button" class="small danger" style="padding:2px 6px;" onclick="window.removeOccupant(${site.id}, ${o.id})">X</button>
                </div>
            `).join('');
        }

        document.getElementById('site-modal-title').textContent = `Manage Site ${site.site_number}`;
        siteModal.classList.remove('hidden');
    };
    
    // Remove Occupant Handler
    window.removeOccupant = async (siteId, memberId) => {
        if(!confirm("Remove this member from the site?")) return;
        try {
            await API.post('/site/deallocate', { site_id: siteId, member_id: memberId });
            // Refresh data locally
            const site = allSites.find(s => s.id == siteId);
            if(site) {
                // Remove from list
                site.occupants_list = site.occupants_list.filter(o => o.id != memberId);
                // Update text
                site.occupants = site.occupants_list.map(o=>o.name).join(', ');
                if(site.occupants_list.length === 0) site.status = 'Available';
                
                updateView();
                window.editSite(siteId); // Re-render modal to show changes
            }
        } catch (e) { alert(e.message); }
    };

    // Save Site
    siteForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const id = document.getElementById('site_id').value;
        const data = {
            site_number: document.getElementById('site_number').value,
            section: document.getElementById('site_section').value,
            site_type: document.getElementById('site_type').value,
            status: document.getElementById('site_status').value,
            member_id: document.getElementById('site_member_id').value
        };

        const btn = siteForm.querySelector('button[type="submit"]');
        btn.disabled = true;
        
        try {
            if (id) await API.post(`/site/update?id=${id}`, data);
            else await API.post('/sites', data);
            
            siteModal.classList.add('hidden');
            render(container); // Full refresh
        } catch (err) {
            alert('Error saving site: ' + err.message);
        } finally {
            btn.disabled = false;
        }
    });

    // Waitlist Logic
    document.getElementById('view-waitlist-btn').addEventListener('click', async () => {
        waitModal.classList.remove('hidden');
        renderWaitlistTable();
    });
    
    document.getElementById('close-waitlist').onclick = () => waitModal.classList.add('hidden');

    function renderWaitlistTable() {
        const tbody = document.getElementById('waitlist-tbody');
        tbody.innerHTML = '<tr><td colspan="8">Loading...</td></tr>';
        
        API.get('/site/waitlist').then(list => {
            waitlistData = list.map(w => {
                // Calculate Score
                const created = new Date(w.created_at);
                const months = (new Date().getFullYear() - created.getFullYear()) * 12 + (new Date().getMonth() - created.getMonth());
                const daysIntended = parseInt(w.intended_days) || 0;
                
                let score = 0;
                score += (parseInt(w.adults)||0) + (parseInt(w.kids)||0); // 1 per Pax
                score += Math.floor(months / 3); // 1 per 3 months
                score += Math.floor(daysIntended / 5); // 1 per 5 days
                if (w.overflow_willing === 'Yes') score += 2;
                if (w.subscription_willing !== 'No') score += 1;
                
                return { ...w, score };
            });

            // Sort logic
            waitlistData.sort((a, b) => {
                let valA = a[currentSort.key];
                let valB = b[currentSort.key];
                if (currentSort.key === 'name') {
                    valA = `${a.last_name} ${a.first_name}`.toLowerCase();
                    valB = `${b.last_name} ${b.first_name}`.toLowerCase();
                }
                
                if (valA < valB) return currentSort.dir === 'asc' ? -1 : 1;
                if (valA > valB) return currentSort.dir === 'asc' ? 1 : -1;
                return 0;
            });

            if (waitlistData.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8">No submissions.</td></tr>';
                return;
            }
            
            tbody.innerHTML = waitlistData.map(w => {
                // Match Member Logic
                let matchHtml = '';
                const matchedMember = allMembers.find(m => 
                    m.first_name.toLowerCase() == w.first_name.toLowerCase() && 
                    m.last_name.toLowerCase() == w.last_name.toLowerCase()
                );
                
                if (matchedMember) {
                    matchHtml = `<div style="color:var(--success); font-size:0.8rem;">✔ Matched: Member #${matchedMember.id}</div>
                                 <button class="small" onclick="window.allocateWaitlist(${w.id}, ${matchedMember.id}, '${w.site_type}')">Allocate</button>`;
                } else {
                    matchHtml = `<button class="small secondary" onclick="window.createAndAllocateWaitlist(${w.id})">Create & Allocate</button>`;
                }

                // Priority Selector
                const priorities = ['Low', 'Medium', 'High', 'Critical'];
                const priorityOpts = priorities.map(p => 
                    `<option value="${p}" ${w.priority === p ? 'selected' : ''}>${p}</option>`
                ).join('');

                return `
                <tr>
                    <td style="font-weight:bold; font-size:1.1rem; text-align:center;">${w.score}</td>
                    <td>
                        <select onchange="window.updatePriority(${w.id}, this.value)" class="small-input" style="padding:2px;">
                            ${priorityOpts}
                        </select>
                    </td>
                    <td>${new Date(w.created_at).toLocaleDateString()}</td>
                    <td>
                        <strong>${w.first_name} ${w.last_name}</strong><br>
                        <small style="color:var(--text-muted);">${w.home_assembly || ''}</small>
                    </td>
                    <td>${w.site_type}</td>
                    <td style="font-size:0.85rem;">
                        <div>Pax: ${w.adults}A ${w.kids}K</div>
                        <div>Days: ${w.intended_days || 0}</div>
                        <div>Overflow: ${w.overflow_willing}</div>
                        <div>Sub: ${w.subscription_willing}</div>
                    </td>
                    <td style="font-size:0.8rem; color:var(--text-muted); max-width:150px;">
                        ${w.special_considerations ? `<div><strong>Special:</strong> ${w.special_considerations}</div>` : ''}
                        ${w.additional_comments ? `<div><strong>Comments:</strong> ${w.additional_comments}</div>` : ''}
                    </td>
                    <td>
                        ${matchHtml}
                        <button class="small danger" style="margin-top:0.5rem;" onclick="window.deleteWaitlist(${w.id})">Del</button>
                    </td>
                </tr>
            `;
            }).join('');
            
            // Attach Sort Listeners
            document.querySelectorAll('#waitlist-modal th.sortable').forEach(th => {
                th.style.cursor = 'pointer';
                th.onclick = () => {
                    const key = th.dataset.sort;
                    currentSort.dir = (currentSort.key === key && currentSort.dir === 'desc') ? 'asc' : 'desc';
                    currentSort.key = key;
                    renderWaitlistTable();
                };
            });
            
        }).catch(e => {
            tbody.innerHTML = `<tr><td colspan="8">Error: ${e.message}</td></tr>`;
        });
    }

    // Global Waitlist Handlers
    window.updatePriority = async (id, priority) => {
        try {
            await API.post(`/site/waitlist-update?id=${id}`, { priority });
        } catch (e) { alert("Failed to save priority: " + e.message); }
    };

    window.allocateWaitlist = async (waitlistId, memberId, prefType) => {
        const siteNum = prompt(`Enter Site Number to allocate (Pref: ${prefType}):`);
        if (!siteNum) return;
        
        let site = allSites.find(s => s.site_number === siteNum);
        
        // If site doesn't exist, create it?
        if (!site) {
            if(confirm(`Site ${siteNum} does not exist. Create it now?`)) {
                try {
                    const res = await API.post('/sites', {
                        site_number: siteNum,
                        section: '',
                        site_type: prefType,
                        status: 'Available'
                    });
                    // Refresh sites list
                    const [sitesData] = await Promise.all([API.get('/sites')]);
                    allSites = sitesData;
                    site = allSites.find(s => s.id == res.id);
                } catch(err) {
                    return alert("Failed to create site: " + err.message);
                }
            } else {
                return;
            }
        }
        
        try {
            await API.post('/sites/allocate', { site_id: site.id, member_id: memberId });
            alert(`Allocated to Site ${site.site_number}`);
            // Remove from waitlist or mark as allocated? Deleting for now as requested flow.
             await API.post(`/site/waitlist-delete?id=${waitlistId}`, {}); 
             document.getElementById('view-waitlist-btn').click();
             render(container); 
        } catch (e) { alert(e.message); }
    };
    
    window.createAndAllocateWaitlist = async (waitlistId) => {
        if(!confirm("Create new member from this application and allocate?")) return;
        // Fetch specific waitlist item details from local cache
        const w = waitlistData.find(x => x.id == waitlistId);
        if(!w) return;
        
        try {
             const res = await API.post('/members', {
                 first_name: w.first_name,
                 last_name: w.last_name,
                 fellowship: w.home_assembly,
                 concession: 'No', // Default
                 site_fee_status: 'Unknown'
             });
             
             if(res.success) {
                 allMembers = await API.get('/members'); // update cache
                 window.allocateWaitlist(waitlistId, res.id, w.site_type);
             }
        } catch(e) { alert(e.message); }
    };
    
    window.deleteWaitlist = async (id) => {
        if(!confirm("Delete this application?")) return;
        await API.post(`/site/waitlist-delete?id=${id}`, {});
        renderWaitlistTable();
    };
}