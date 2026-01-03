import * as API from '../api.js';

// Updated Helper: Handles commas and variations
function memberMatchesQuery(member, query) {
    const q = (query || '').replace(/,/g, ' ').toLowerCase().trim();
    if (!q) return true;
    const tokens = q.split(/\s+/).filter(Boolean);
    const first = (member.first_name || '').toLowerCase();
    const last = (member.last_name || '').toLowerCase();
    return tokens.every(t => first.includes(t) || last.includes(t));
}

export async function render(container) {
    const today = new Date().toLocaleDateString('en-CA', { timeZone: 'Australia/Adelaide' });
    let isRefundMode = false;
    
    // Global helper for counters
    window.adjustCount = (id, delta) => {
        const input = document.getElementById(id);
        if(!input) return;
        let val = parseInt(input.value) || 0;
        val = Math.max(0, val + delta);
        input.value = val;
        input.dispatchEvent(new Event('input')); // Trigger calculation
    };
    
    container.innerHTML = `
        <div class="header-actions" style="justify-content: space-between; align-items: center;">
            <h1>Take Payments</h1>
            <div style="display:flex; gap:0.5rem;">
                <button id="refund-toggle-btn" class="secondary small">Refund Mode: OFF</button>
                <button id="hold-payment-btn" class="secondary small">Hold</button>
                <button id="restore-payment-btn" class="secondary small hidden">Restore</button>
                <button id="reset-form-btn" class="danger small">Reset</button>
            </div>
        </div>

        <!-- Main Layout Grid -->
        <div class="payment-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 1.5rem;">
            
            <!-- Left Column -->
            <div class="left-col" style="display: flex; flex-direction: column; gap: 1.5rem;">
                
                <!-- Section 1: Camp & Member -->
                <div class="section-card sec-camp" style="margin-bottom:0;">
                    <h2><span class="section-number">1</span> Camp &amp; Member</h2>
                    <div class="form-row">
                        <label style="min-width:100px;">Camp</label>
                        <select id="camp-select" class="flex-fill" tabindex="1"><option>Loading...</option></select>
                    </div>
                    <div class="form-row" id="member-search-row">
                        <label style="min-width:100px;">Member</label>
                        <div class="search-box flex-fill">
                            <input type="text" id="member-search" placeholder="Search..." autocomplete="off" tabindex="2">
                            <button class="small secondary" id="btn-new-member" style="position:absolute; right:5px; top:5px; padding: 2px 8px;">New</button>
                            <div id="member-results" class="dropdown-results hidden"></div>
                        </div>
                    </div>
                    
                    <div id="member-card" class="member-info hidden" style="background:var(--background-soft); padding:0.75rem; border-radius:0.5rem; border:1px solid var(--border); margin-top:0.5rem;">
                        <div class="member-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.25rem;">
                            <strong id="member-name-display" style="font-size:1rem; color:var(--primary);">-</strong>
                            <div style="display:flex; gap:0.25rem;">
                                <button class="small secondary" id="btn-edit-member" style="padding:2px 8px; font-size:0.75rem;">Edit</button>
                                <button class="small danger" id="clear-member" style="padding:2px 8px; font-size:0.75rem;">Change</button>
                            </div>
                        </div>
                        <div class="member-details" style="font-size:0.85rem; color:var(--text-muted);">
                             <div style="margin-bottom:0.25rem;"><strong>Site:</strong> <span id="display-site-alloc">-</span></div>
                            <strong>Site Fee's Next Due:</strong> <span id="display-paid-until">-</span> | 
                            <span id="pre-pay-amount" style="color:var(--success); font-weight:bold;">Pre-Payment: $0.00</span>
                        </div>
                        <div class="member-history" style="margin-top:0.5rem; border-top:1px solid var(--border); padding-top:0.5rem;">
                             <div style="font-size:0.8rem; font-weight:bold; margin-bottom:0.25rem;">Last 3 Payments:</div>
                             <div id="payment-history" class="history-list"></div>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Stay Dates -->
                <div class="section-card sec-dates" style="margin-bottom:0;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.75rem;">
                        <h2><span class="section-number">2</span> Stay Dates</h2>
                        <select id="date-mode-toggle" class="small-input" style="width:auto; padding:0.25rem;">
                            <option value="range">Range</option>
                            <option value="days">Days</option>
                        </select>
                    </div>
                    
                    <div id="date-range-mode">
                        <div class="form-row">
                            <label style="min-width:80px;">Arrival</label>
                            <input type="date" id="arrival-date" value="${today}" class="flex-fill" tabindex="3">
                        </div>
                        <div class="form-row">
                            <label style="min-width:80px;">Depart</label>
                            <input type="date" id="departure-date" value="${today}" class="flex-fill" tabindex="4">
                        </div>
                    </div>

                    <div id="date-days-mode" class="hidden">
                        <div id="days-list" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap:0.25rem;"></div>
                    </div>
                </div>

                <!-- Section 3: Occupants & Rates -->
                <div class="section-card sec-occupants" style="margin-bottom:0;">
                    <h2><span class="section-number">3</span> Occupants &amp; Rates</h2>
                    <div style="display:flex; gap:1rem; margin-bottom:0.75rem;">
                        <div class="occupant-row" style="margin:0;">
                            <label style="min-width:auto; margin-right:0.5rem;">Adults</label>
                            <button type="button" class="counter-btn secondary" onclick="window.adjustCount('calc-adults', -1)" style="width:24px; height:24px;">-</button>
                            <input type="number" id="calc-adults" value="0" min="0" style="width:40px; text-align:center; margin:0 0.25rem; padding:0.25rem;" tabindex="5">
                            <button type="button" class="counter-btn secondary" onclick="window.adjustCount('calc-adults', 1)" style="width:24px; height:24px;">+</button>
                        </div>
                        <div class="occupant-row" style="margin:0;">
                            <label style="min-width:auto; margin-right:0.5rem;">Kids</label>
                            <button type="button" class="counter-btn secondary" onclick="window.adjustCount('calc-kids', -1)" style="width:24px; height:24px;">-</button>
                            <input type="number" id="calc-kids" value="0" min="0" style="width:40px; text-align:center; margin:0 0.25rem; padding:0.25rem;" tabindex="6">
                            <button type="button" class="counter-btn secondary" onclick="window.adjustCount('calc-kids', 1)" style="width:24px; height:24px;">+</button>
                        </div>
                    </div>

                    <div class="form-row">
                        <select id="calc-concession" class="flex-fill" tabindex="7" style="margin-right:0.5rem;">
                            <option value="No">No Concession</option>
                            <option value="Yes">Concession</option>
                        </select>
                        <select id="calc-site-type" class="flex-fill" tabindex="8">
                            <option value="">Site Type...</option>
                            <option value="Unpowered Site">Unpowered</option>
                            <option value="Powered Site">Powered</option>
                            <option value="Dorms (KFC)">Dorms</option>
                            <option value="Family Room">Family Room</option>
                            <option value="Special Use">Special Use</option>
                        </select>
                    </div>
                    
                    <div style="display:flex; gap:1rem; font-size:0.9rem;">
                        <label style="display:flex; align-items:center;"><input type="checkbox" id="add-day-rate" style="width:auto; margin-right:0.25rem;"> Day Rate</label>
                        <label style="display:flex; align-items:center;"><input type="checkbox" id="add-caravan-storage" style="width:auto; margin-right:0.25rem;"> Storage</label>
                    </div>

                    <div class="rate-breakdown" style="margin-top:0.75rem; padding:0.5rem;">
                        <div id="breakdown-list" style="font-size: 0.85rem; line-height: 1.4; max-height:80px; overflow-y:auto;">Select options...</div>
                        <div style="display:flex; justify-content: space-between; border-top: 1px solid var(--border); padding-top: 0.25rem; margin-top: 0.25rem;">
                            <strong>Total:</strong>
                            <span id="calculation-total" style="font-weight:bold; color:var(--primary);">$0.00</span>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Right Column -->
            <div class="right-col" style="display: flex; flex-direction: column;">
                
                <!-- Section 4: Payment -->
                <div class="section-card sec-payment" style="flex:1; display:flex; flex-direction:column; margin-bottom:0;">
                    <h2><span class="section-number">4</span> Payment</h2>
                    
                    <div class="form-row">
                        <label style="min-width:100px;">Pre-paid</label>
                        <div style="display:flex; gap:0.5rem; flex:1;">
                            <input type="number" id="prepaid-input" value="0.00" step="0.01" tabindex="11">
                            <button type="button" class="small secondary" id="btn-max-pre">Max</button>
                        </div>
                    </div>

                    <div class="form-row">
                        <label style="min-width:100px;">Site Fees</label>
                        <select id="add-site-fee-months" class="flex-fill" tabindex="16">
                            <option value="0">Add Months...</option>
                            ${Array.from({length: 24}, (_, i) => `<option value="${i+1}">${i+1} Month${i===0?'':'s'}</option>`).join('')}
                        </select>
                    </div>
                    <div style="text-align:right; font-size:0.85rem; color:var(--text-muted); margin-top:-0.5rem; margin-bottom:0.5rem;">
                        New Expiry: <span id="new-paid-until" style="font-weight:bold;">-</span>
                    </div>

                    <div class="form-row">
                        <label style="min-width:100px;">Add/Disc</label>
                        <div style="display:flex; gap:0.5rem; flex:1;">
                            <input type="number" id="add-donation" placeholder="Add $" step="0.01" class="small-input">
                            <input type="number" id="add-discount" placeholder="Disc $" step="0.01" class="small-input" style="border-color:var(--danger);">
                        </div>
                    </div>
                    
                    <hr style="border:0; border-top:1px dashed var(--border); margin:0.5rem 0;">

                    <div class="form-row" style="margin-bottom:0.5rem;"><label style="width:80px;">EFTPOS</label><div style="flex:1; display:flex; gap:0.5rem;"><input type="number" id="pay-eftpos" class="tender-input flex-fill" placeholder="0.00" tabindex="12"><button type="button" class="small secondary btn-all" data-target="pay-eftpos">All</button></div></div>
                    <div class="form-row" style="margin-bottom:0.5rem;"><label style="width:80px;">Cash</label><div style="flex:1; display:flex; gap:0.5rem;"><input type="number" id="pay-cash" class="tender-input flex-fill" placeholder="0.00" tabindex="13"><button type="button" class="small secondary btn-all" data-target="pay-cash">All</button></div></div>
                    <div class="form-row" style="margin-bottom:1rem;"><label style="width:80px;">Cheque</label><div style="flex:1; display:flex; gap:0.5rem;"><input type="number" id="pay-cheque" class="tender-input flex-fill" placeholder="0.00" tabindex="14"><button type="button" class="small secondary btn-all" data-target="pay-cheque">All</button></div></div>

                    <div class="payment-summary" style="background:var(--surface); border:1px solid var(--border); padding:0.75rem;">
                        <div class="summary-row"><span>Total</span><span id="total-fees">$0.00</span></div>
                        <div class="summary-row" style="color:var(--danger); font-size:0.85rem;"><span>Less Pre-paid</span><span id="deducted-prepaid">-$0.00</span></div>
                        <div class="summary-row" style="color:var(--text-muted); font-size:0.85rem;"><span>Tendered</span><span id="total-tendered">-$0.00</span></div>
                        <div class="summary-row balance" style="margin-top:0.25rem; padding-top:0.25rem;"><span>Balance</span><span id="balance-due">$0.00</span></div>
                    </div>

                    <div class="form-row">
                        <label style="min-width:auto; margin-right:0.5rem;">Date</label>
                        <input type="date" id="payment-date" value="${today}" style="padding:0.25rem;">
                    </div>
                    
                    <textarea id="notes" rows="2" placeholder="Notes..." style="width:100%; margin-bottom:1rem; font-size:0.85rem;" tabindex="17"></textarea>
                    
                    <button id="submit-payment" style="width:100%; padding:0.75rem; font-size:1.1rem; margin-top:auto;" tabindex="18" disabled>Submit Payment</button>
                </div>

            </div>
        </div>

        <!-- Add/Edit Member Modal (Shared for both actions) -->
        <div id="member-modal" class="modal hidden">
            <div class="modal-content">
                <h2 id="modal-title">Add New Member</h2>
                <form id="member-form">
                    <input type="hidden" id="edit-member-id">
                    <div class="grid-2">
                        <div class="form-group"><label>First Name</label><input type="text" id="edit-first-name" required></div>
                        <div class="form-group"><label>Last Name</label><input type="text" id="edit-last-name" required></div>
                    </div>
                    <div class="grid-2">
                        <div class="form-group"><label>Fellowship</label><input type="text" id="edit-fellowship"></div>
                        <div class="form-group">
                            <label>Concession</label>
                            <select id="edit-concession">
                                <option value="No">No</option>
                                <option value="Yes">Yes</option>
                            </select>
                        </div>
                    </div>
                    <div class="grid-2">
                         <div class="form-group">
                            <label>Site Fee Status</label>
                             <select id="edit-site-fee-status">
                                <option value="Unknown">Unknown</option>
                                <option value="Paid">Paid</option>
                                <option value="Unpaid">Unpaid</option>
                                <option value="Overdue">Overdue</option>
                                <option value="Exempt">Exempt</option>
                            </select>
                        </div>
                         <div class="form-group">
                             <label>Allocated Site</label>
                             <input type="text" id="edit-site-alloc" placeholder="Site #">
                         </div>
                    </div>
                    <div class="form-row" style="justify-content:flex-end; gap:1rem;"><button type="button" class="secondary" id="cancel-member-modal">Cancel</button><button type="submit">Save</button></div>
                </form>
            </div>
        </div>

        <!-- Restore Payment Modal -->
        <div id="restore-modal" class="modal hidden">
             <div class="modal-content">
                <h2>Restore Held</h2>
                <div id="held-list" style="max-height:300px; overflow-y:auto;"></div>
                <button type="button" class="secondary" id="close-restore" style="margin-top:1rem; width:100%;">Close</button>
            </div>
        </div>
    `;

    // Initialize State - Correctly scoped!
    let camps = [];
    let currentRates = [];
    let currentCamp = null;
    let memberPrepayments = 0;
    let memberPrepaymentRecords = [];
    let unmatchedPrepayments = [];
    let currentSitePaidUntil = null;

    // Attach listeners
    Promise.resolve().then(() => {
        // Refund Toggle
        document.getElementById('refund-toggle-btn').addEventListener('click', (e) => {
            isRefundMode = !isRefundMode;
            const btn = e.target;
            btn.textContent = isRefundMode ? "Refund Mode: ON" : "Refund Mode: OFF";
            btn.classList.toggle('danger', isRefundMode);
            btn.classList.toggle('secondary', !isRefundMode);
            calculate();
        });

        // "All" Buttons
        document.querySelectorAll('.btn-all').forEach(btn => {
            btn.addEventListener('click', () => {
                const targetId = btn.dataset.target;
                const targetInput = document.getElementById(targetId);
                
                ['pay-eftpos', 'pay-cash', 'pay-cheque'].forEach(t => { 
                    if(t !== targetId) document.getElementById(t).value = ''; 
                });

                calculate(); // Updates totals first
                const total = parseFloat(document.getElementById('total-fees').textContent.replace('$','')) || 0;
                const prepaid = parseFloat(document.getElementById('deducted-prepaid').textContent.replace('-$','')) || 0;
                const remaining = total - prepaid; // Allow negative for refund mode
                
                targetInput.value = remaining.toFixed(2);
                targetInput.dispatchEvent(new Event('input')); 
            });
        });
        
        // Reset Form
        const resetBtn = document.getElementById('reset-form-btn');
        if(resetBtn) {
            resetBtn.addEventListener('click', () => {
                if(confirm("Are you sure you want to reset the form?")) {
                    render(container);
                }
            });
        }

        // Hold Payment
        const holdBtn = document.getElementById('hold-payment-btn');
        if(holdBtn) holdBtn.addEventListener('click', holdPayment);
        
        const restoreBtn = document.getElementById('restore-payment-btn');
        if(restoreBtn) restoreBtn.addEventListener('click', showRestoreModal);
        
        checkHeldPayments();
        
        // Max Prepayment Button
        const maxPreBtn = document.getElementById('btn-max-pre');
        if(maxPreBtn) {
            maxPreBtn.addEventListener('click', () => {
                // Ensure we use the correct memberPrepayments value
                document.getElementById('prepaid-input').value = memberPrepayments.toFixed(2);
                calculate();
            });
        }
        
        // Date Mode Toggle
        const dateToggle = document.getElementById('date-mode-toggle');
        if(dateToggle) {
            dateToggle.addEventListener('change', (e) => {
                const mode = e.target.value;
                const rangeMode = document.getElementById('date-range-mode');
                const daysMode = document.getElementById('date-days-mode');
                
                if(rangeMode) rangeMode.classList.toggle('hidden', mode !== 'range');
                if(daysMode) daysMode.classList.toggle('hidden', mode !== 'days');
                
                if(mode === 'days') generateDayCheckboxes();
                calculate();
            });
        }

        // Modal Listeners
        document.getElementById('btn-new-member').addEventListener('click', () => {
             document.getElementById('member-form').reset();
             document.getElementById('edit-member-id').value = '';
             document.getElementById('modal-title').textContent = "Add New Member";
             document.getElementById('member-modal').classList.remove('hidden');
        });

        document.getElementById('btn-edit-member').addEventListener('click', () => {
            const memId = container.dataset.selectedMemberId;
            if(!memId) return;
            const mem = membersCache.find(m => m.id == memId);
            if(mem) {
                document.getElementById('edit-member-id').value = mem.id;
                document.getElementById('edit-first-name').value = mem.first_name;
                document.getElementById('edit-last-name').value = mem.last_name;
                document.getElementById('edit-fellowship').value = mem.fellowship;
                document.getElementById('edit-concession').value = (String(mem.concession).toLowerCase() === 'yes' || mem.concession == 1) ? 'Yes' : 'No';
                document.getElementById('edit-site-fee-status').value = mem.site_fee_status;
            }
             document.getElementById('modal-title').textContent = "Edit Member";
             document.getElementById('member-modal').classList.remove('hidden');
        });

        document.getElementById('cancel-member-modal').addEventListener('click', (e) => {
            e.preventDefault();
            document.getElementById('member-modal').classList.add('hidden');
        });
        
        document.getElementById('member-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const id = document.getElementById('edit-member-id').value;
            const data = {
                first_name: document.getElementById('edit-first-name').value,
                last_name: document.getElementById('edit-last-name').value,
                fellowship: document.getElementById('edit-fellowship').value,
                concession: document.getElementById('edit-concession').value,
                site_fee_status: document.getElementById('edit-site-fee-status').value
            };
            const siteNum = document.getElementById('edit-site-alloc').value;
            
            try {
                let res;
                if(id) {
                    res = await API.post(`/member/update?id=${id}`, data);
                } else {
                    res = await API.post('/members', data);
                }
                
                // Handle Site Alloc
                if(siteNum) {
                     const sites = await API.get('/sites');
                     const site = sites.find(s => s.site_number === siteNum);
                     if (site) await API.post('/sites/allocate', { site_id: site.id, member_id: (id || res.id) });
                }

                alert('Member Saved!');
                membersCache = await API.get('/members');
                selectMember((id || res.id), `${data.first_name} ${data.last_name}`);
                document.getElementById('member-modal').classList.add('hidden');
            } catch(err) { alert(err.message); }
        });
    });

    // 1. Load Data (Assign to outer 'camps' variable)
    try {
        const fetchedCamps = await API.get('/camps/active');
        camps = fetchedCamps; // FIX: Update outer scope var
        const campSelect = document.getElementById('camp-select');
        if(campSelect) {
            campSelect.innerHTML = camps.map(c => `<option value="${c.id}">${c.name}</option>`).join('');
            if (camps.length > 0) loadCampData(camps[0].id);
            campSelect.addEventListener('change', (e) => loadCampData(e.target.value));
        }
    } catch(e) { console.error(e); }

    async function loadCampData(campId) {
        currentCamp = camps.find(c => c.id == campId);
        try {
            currentRates = await API.get(`/rates?camp_id=${campId}`);
            unmatchedPrepayments = await API.get(`/prepayments?camp_id=${campId}&status=Unmatched`);
            
            // Re-gen days if mode is days
            const toggle = document.getElementById('date-mode-toggle');
            if (toggle && toggle.value === 'days') {
                generateDayCheckboxes();
            }
            calculate();
        } catch (e) { console.error(e); }
    }
    
    function generateDayCheckboxes() {
        if (!currentCamp) return;
        const start = new Date(currentCamp.start_date);
        const end = new Date(currentCamp.end_date);
        start.setDate(start.getDate() - 5);
        end.setDate(end.getDate() + 5);
        
        const list = document.getElementById('days-list');
        if(!list) return;
        list.innerHTML = '';
        
        let loop = new Date(start);
        while (loop <= end) {
            const dateStr = loop.toLocaleDateString('en-AU', { weekday: 'short', day: 'numeric', month: 'short' });
            const isoDate = loop.toISOString().split('T')[0];
            
            list.innerHTML += `
                <div class="day-item" style="border:1px solid var(--border); padding:0.25rem; border-radius:4px; font-size:0.75rem;">
                    <label style="display:block; margin-bottom:0;">
                        <input type="checkbox" class="day-check" data-date="${isoDate}"> 
                        ${dateStr}
                    </label>
                    <input type="number" class="day-count small-input" data-date="${isoDate}" value="0" min="0" disabled style="width:100%; padding:2px;">
                </div>
            `;
            loop.setDate(loop.getDate() + 1);
        }
        
        list.querySelectorAll('.day-check').forEach(chk => {
            chk.addEventListener('change', (e) => {
                const countInput = e.target.parentElement.nextElementSibling;
                const totalOcc = (parseInt(document.getElementById('calc-adults').value)||0) + (parseInt(document.getElementById('calc-kids').value)||0);
                countInput.disabled = !e.target.checked;
                if (e.target.checked) countInput.value = totalOcc;
                else countInput.value = 0;
                calculate();
            });
        });
        
        list.querySelectorAll('.day-count').forEach(inp => {
            inp.addEventListener('input', calculate);
        });
    }

    // 2. Member Search
    const searchInput = document.getElementById('member-search');
    const resultsBox = document.getElementById('member-results');
    let membersCache = await API.get('/members');

    if(searchInput) {
        searchInput.addEventListener('input', (e) => {
            const term = (e.target.value || '').trim();
            if (term.length < 2) {
                if(resultsBox) resultsBox.classList.add('hidden');
                return;
            }
            const filtered = membersCache.filter(m => memberMatchesQuery(m, term)).slice(0, 5);
            let html = filtered.map(m => `
                <div class="result-item" data-id="${m.id}" data-name="${m.first_name} ${m.last_name}">
                    <strong>${m.last_name}, ${m.first_name}</strong>
                </div>
            `).join('');
            html += `<div class="result-item create-new" style="border-top:1px solid #eee; color:var(--primary);">+ Add "${e.target.value}"</div>`;
            if(resultsBox) {
                resultsBox.innerHTML = html;
                resultsBox.classList.remove('hidden');
            }
        });
    }

    if(resultsBox) {
        resultsBox.addEventListener('click', (e) => {
            const item = e.target.closest('.result-item');
            if (item) {
                if (item.classList.contains('create-new')) {
                    document.getElementById('member-form').reset();
                    document.getElementById('edit-member-id').value = '';
                    document.getElementById('member-modal').classList.remove('hidden');
                } else {
                    selectMember(item.dataset.id, item.dataset.name);
                }
                resultsBox.classList.add('hidden');
            }
        });
    }

    document.getElementById('clear-member').addEventListener('click', () => {
        render(container); 
    });

    async function selectMember(id, name) {
        document.getElementById('member-search-row').classList.add('hidden');
        document.getElementById('member-card').classList.remove('hidden');
        document.getElementById('member-name-display').textContent = name;
        
        // Store for Edit modal usage
        container.dataset.selectedMemberId = id;
        
        const member = membersCache.find(m => m.id == id);
        const history = await API.get(`/member/history?id=${id}`);

        document.getElementById('edit-member-id').value = member.id;
        document.getElementById('edit-first-name').value = member.first_name;
        document.getElementById('edit-last-name').value = member.last_name;
        document.getElementById('edit-fellowship').value = member.fellowship || '';
        document.getElementById('edit-concession').value = (String(member.concession).toLowerCase() === 'yes') ? 'Yes' : 'No';
        document.getElementById('edit-site-fee-status').value = member.site_fee_status;

        const currentAlloc = history.allocations.find(a => a.is_current == 1);
        const displaySite = document.getElementById('display-site-alloc');
        if (currentAlloc) {
            document.getElementById('edit-site-alloc').value = currentAlloc.site_number;
            if(displaySite) displaySite.textContent = `${currentAlloc.site_number} (${currentAlloc.site_type})`;
            
            const opts = document.getElementById('calc-site-type').options;
            for(let o of opts) { if (o.value === currentAlloc.site_type) document.getElementById('calc-site-type').value = o.value; }
            document.getElementById('arrival-date').value = currentAlloc.start_date;
        } else {
            document.getElementById('edit-site-alloc').value = '';
            if(displaySite) displaySite.textContent = '-';
        }

        currentSitePaidUntil = history.site_fee_paid_until || member.site_fee_paid_until || null;
        document.getElementById('display-paid-until').textContent = (currentSitePaidUntil && currentSitePaidUntil !== '0000-00-00') ? currentSitePaidUntil : '-';

        const usablePre = (history.prepayments || []).filter(p => parseFloat(p.amount) > 0);
        const totalPre = usablePre.reduce((sum, p) => sum + parseFloat(p.amount), 0);
        memberPrepayments = totalPre;
        memberPrepaymentRecords = usablePre;
        document.getElementById('pre-pay-amount').textContent = `Pre-Payment: $${totalPre.toFixed(2)}`;
        document.getElementById('prepaid-input').value = '0.00';

        document.getElementById('calc-concession').value = (String(member.concession).toLowerCase() === 'yes') ? 'Yes' : 'No';
        
        // Show History
        const histBox = document.getElementById('payment-history');
        if(histBox) {
            if (history.payments && history.payments.length > 0) {
                histBox.innerHTML = history.payments.slice(0,3).map(p => { 
                    const dateStr = new Date(p.payment_date).toLocaleDateString();
                    return `<div style="font-size:0.75rem; border-bottom:1px solid #eee; padding:2px;">${dateStr}: <strong>$${parseFloat(p.total).toFixed(2)}</strong> <span style="color:var(--text-muted);">${p.notes || ''}</span></div>`;
                }).join('');
            } else {
                histBox.innerHTML = '<div style="font-size:0.8rem; color:var(--text-muted);">No recent payments.</div>';
            }
        }

        calculate();
    }

    // Listeners for Calc
    const calcInputs = [
        'arrival-date', 'departure-date', 
        'calc-adults', 'calc-kids', 'calc-concession', 'calc-site-type', 
        'prepaid-input', 'add-site-fee-months', 'add-day-rate', 'add-caravan-storage', 'add-donation', 'add-discount',
        'pay-cash', 'pay-cheque', 'pay-eftpos'
    ];
    calcInputs.forEach(id => {
        const el = document.getElementById(id);
        if (el) { el.addEventListener('change', calculate); el.addEventListener('input', calculate); }
    });
    
    ['calc-adults', 'calc-kids'].forEach(id => {
        const el = document.getElementById(id);
        if(el) {
            el.addEventListener('change', () => {
                if (document.getElementById('date-mode-toggle').value === 'days') {
                    const totalOcc = (parseInt(document.getElementById('calc-adults').value)||0) + (parseInt(document.getElementById('calc-kids').value)||0);
                    document.querySelectorAll('.day-check:checked').forEach(chk => {
                        chk.parentElement.nextElementSibling.value = totalOcc; 
                    });
                    calculate();
                }
            });
        }
    });

    function calculate() {
        const list = document.getElementById('breakdown-list');
        const adults = parseInt(document.getElementById('calc-adults').value)||0;
        const kids = parseInt(document.getElementById('calc-kids').value)||0;
        const concession = document.getElementById('calc-concession').value === 'Yes';
        const siteType = document.getElementById('calc-site-type').value;

        const getRate = (uType) => {
            const r = currentRates.find(r => r.item === siteType && r.user_type === uType);
            return r ? parseFloat(r.amount) : 0;
        };

        let campFee = 0;
        let breakdown = [];
        
        // 1. Camp Fees
        if (siteType) {
            const mode = document.getElementById('date-mode-toggle').value;
            let daysToCalc = [];
            
            if (mode === 'range') {
                const s = new Date(document.getElementById('arrival-date').value);
                const e = new Date(document.getElementById('departure-date').value);
                while(s < e) {
                    daysToCalc.push({ date: new Date(s), count: adults+kids, adults: adults, kids: kids });
                    s.setDate(s.getDate()+1);
                }
            } else {
                document.querySelectorAll('.day-check:checked').forEach(chk => {
                    const count = parseInt(chk.parentElement.nextElementSibling.value)||0;
                    if(count > 0) {
                        let effK = Math.min(count, kids);
                        let effA = Math.max(0, count - effK);
                        daysToCalc.push({ date: new Date(chk.dataset.date), count: count, adults: effA, kids: effK });
                    }
                });
            }

            const onPeakStart = currentCamp ? new Date(currentCamp.on_peak_start) : null;
            const onPeakEnd = currentCamp ? new Date(currentCamp.on_peak_end) : null;

            daysToCalc.forEach(d => {
                const isOff = onPeakStart && onPeakEnd && (d.date < onPeakStart || d.date > onPeakEnd);
                let nightly = 0;
                if(isOff) {
                    let r = 0;
                    if(concession) r = getRate('Offpeak Concession Single') || getRate('Off Peak Concession');
                    else r = getRate('Offpeak') || getRate('Off Peak');
                    nightly = (r===0 ? 30.00 : r);
                } else {
                    let aRate = 0;
                    if(concession) {
                        if(d.adults >= 2) aRate = (getRate('Concession Couple') || getRate('Concession')*2)/2 * d.adults;
                        else aRate = (getRate('Concession Single') || getRate('Concession')) * d.adults;
                    } else {
                        if(d.adults >= 2) aRate = (getRate('Couple') || getRate('Adult')*2)/2 * d.adults;
                        else aRate = (getRate('Single') || getRate('Adult')) * d.adults;
                    }
                    nightly = aRate + (getRate('Child') * d.kids);
                    const cap = getRate('Family Cap');
                    if(cap > 0 && nightly > cap) nightly = cap;
                }
                campFee += nightly;
            });
            
            if(daysToCalc.length > 0) breakdown.push(`Camp Fee (${daysToCalc.length} nights): $${campFee.toFixed(2)}`);
        }

        // 2. Site Fees
        const months = parseInt(document.getElementById('add-site-fee-months').value)||0;
        let siteFee = 0;
        if(months > 0) {
            let annual = concession ? (getRate('Site Fee Concession')||320) : (getRate('Site Fee Standard')||400);
            siteFee = Math.round((annual/12)*months);
            breakdown.push(`Site Fee (${months} months): $${siteFee.toFixed(2)}`);
            
            const now = new Date();
            let base = (currentSitePaidUntil && currentSitePaidUntil !== '0000-00-00') ? new Date(currentSitePaidUntil) : now;
            if(base < now) base = now; 
            base.setMonth(base.getMonth() + months);
            document.getElementById('new-paid-until').textContent = base.toLocaleDateString('en-AU');
            container.dataset.newPaidUntilIso = base.toISOString().split('T')[0];
        } else {
            document.getElementById('new-paid-until').textContent = '-';
            container.dataset.newPaidUntilIso = '';
        }

        // 3. Other
        let other = 0;
        if(document.getElementById('add-day-rate').checked) {
            const dr = 15.00 * (adults+kids);
            other += dr;
            breakdown.push(`Day Rate: $${dr.toFixed(2)}`);
        }
        if(document.getElementById('add-caravan-storage').checked) {
            other += 50.00;
            breakdown.push(`Storage: $50.00`);
        }
        const donation = parseFloat(document.getElementById('add-donation').value)||0;
        if(donation > 0) {
            other += donation;
            breakdown.push(`Donation: $${donation.toFixed(2)}`);
        }

        let total = campFee + siteFee + other;
        
        // 4. Discount
        const discount = parseFloat(document.getElementById('add-discount').value)||0;
        if(discount > 0) {
            total -= discount;
            breakdown.push(`Discount: -$${discount.toFixed(2)}`);
        }
        
        // REFUND MODE Logic
        if (isRefundMode) {
             total = total * -1; // Invert the total bill
        }

        list.innerHTML = breakdown.join('<br>') || 'Select options...';
        document.getElementById('calculation-total').textContent = `$${total.toFixed(2)}`;
        
        document.getElementById('total-fees').textContent = `$${total.toFixed(2)}`;
        const prepaid = parseFloat(document.getElementById('prepaid-input').value)||0;
        document.getElementById('deducted-prepaid').textContent = `-$${prepaid.toFixed(2)}`;
        
        const cash = parseFloat(document.getElementById('pay-cash').value)||0;
        const chq = parseFloat(document.getElementById('pay-cheque').value)||0;
        const eft = parseFloat(document.getElementById('pay-eftpos').value)||0;
        const tendered = cash + chq + eft;
        document.getElementById('total-tendered').textContent = `-$${tendered.toFixed(2)}`;
        
        const due = total - prepaid - tendered;
        
        const dueEl = document.getElementById('balance-due');
        dueEl.textContent = `$${Math.abs(due).toFixed(2)}`; // Show absolute value for display, but logic uses real
        
        const submitBtn = document.getElementById('submit-payment');
        if (Math.abs(due) < 0.01) {
            submitBtn.disabled = false;
            dueEl.style.color = 'var(--success)';
            dueEl.textContent = "$0.00";
        } else {
            submitBtn.disabled = true;
            dueEl.style.color = 'var(--danger)';
        }

        container.dataset.campFee = isRefundMode ? -campFee : campFee;
        container.dataset.siteFee = isRefundMode ? -siteFee : siteFee;
        container.dataset.otherAmount = isRefundMode ? -other : other;
        container.dataset.total = total;
    }

    // --- Hold ---
    function holdPayment() {
        const name = prompt("Reference Name:");
        if(!name) return;
        const state = {
            memberId: document.getElementById('edit-member-id').value,
            memberName: document.getElementById('member-name-display').textContent,
            campId: document.getElementById('camp-select').value,
            adults: document.getElementById('calc-adults').value,
            kids: document.getElementById('calc-kids').value,
            siteType: document.getElementById('calc-site-type').value,
            concession: document.getElementById('calc-concession').value,
            dayRate: document.getElementById('add-day-rate').checked,
            storage: document.getElementById('add-caravan-storage').checked,
            siteFeeMonths: document.getElementById('add-site-fee-months').value,
            donation: document.getElementById('add-donation').value,
            discount: document.getElementById('add-discount').value,
            timestamp: new Date().toISOString(),
            refName: name
        };
        let held = JSON.parse(localStorage.getItem('held_payments') || '[]');
        held.push(state);
        localStorage.setItem('held_payments', JSON.stringify(held));
        alert("Payment Held.");
        checkHeldPayments();
        render(container); 
    }

    function checkHeldPayments() {
        const held = JSON.parse(localStorage.getItem('held_payments') || '[]');
        const btn = document.getElementById('restore-payment-btn');
        if (held.length > 0) btn.classList.remove('hidden');
        else btn.classList.add('hidden');
    }

    function showRestoreModal() {
        const held = JSON.parse(localStorage.getItem('held_payments') || '[]');
        const list = document.getElementById('held-list');
        list.innerHTML = held.map((h, i) => `
            <div style="padding:0.5rem; border:1px solid #eee; display:flex; justify-content:space-between; margin-bottom:0.5rem;">
                <span>${h.refName} (${new Date(h.timestamp).toLocaleTimeString()})</span>
                <div>
                    <button class="small" onclick="window.restoreHeld(${i})">Load</button>
                    <button class="small danger" onclick="window.deleteHeld(${i})">X</button>
                </div>
            </div>
        `).join('');
        document.getElementById('restore-modal').classList.remove('hidden');
    }

    window.restoreHeld = (index) => {
        const held = JSON.parse(localStorage.getItem('held_payments') || '[]');
        const data = held[index];
        if (data) {
            selectMember(data.memberId, data.memberName).then(() => {
                document.getElementById('camp-select').value = data.campId;
                document.getElementById('calc-adults').value = data.adults;
                document.getElementById('calc-kids').value = data.kids;
                document.getElementById('calc-site-type').value = data.siteType;
                document.getElementById('calc-concession').value = data.concession;
                document.getElementById('add-day-rate').checked = data.dayRate;
                document.getElementById('add-caravan-storage').checked = data.storage;
                document.getElementById('add-site-fee-months').value = data.siteFeeMonths;
                if(data.donation) document.getElementById('add-donation').value = data.donation;
                if(data.discount) document.getElementById('add-discount').value = data.discount;
                calculate();
            });
            held.splice(index, 1);
            localStorage.setItem('held_payments', JSON.stringify(held));
            checkHeldPayments();
        }
        document.getElementById('restore-modal').classList.add('hidden');
    };
    
    window.deleteHeld = (index) => {
        let held = JSON.parse(localStorage.getItem('held_payments') || '[]');
        held.splice(index, 1);
        localStorage.setItem('held_payments', JSON.stringify(held));
        showRestoreModal();
        checkHeldPayments();
    };
    
    document.getElementById('close-restore').onclick = () => document.getElementById('restore-modal').classList.add('hidden');

    // --- Submit ---
    document.getElementById('submit-payment').onclick = async () => {
        const btn = document.getElementById('submit-payment');
        
        // Double check balance here to prevent race conditions or inspect element hacks
        const total = parseFloat(container.dataset.total) || 0;
        const prepaid = parseFloat(document.getElementById('prepaid-input').value)||0;
        const cash = parseFloat(document.getElementById('pay-cash').value)||0;
        const chq = parseFloat(document.getElementById('pay-cheque').value)||0;
        const eft = parseFloat(document.getElementById('pay-eftpos').value)||0;
        const due = total - prepaid - (cash + chq + eft);

        if (Math.abs(due) >= 0.01) {
            alert(`Cannot submit payment. Balance is not zero (Due: $${due.toFixed(2)}). Please allocate remaining amount to a tender type.`);
            return;
        }

        btn.disabled = true; btn.textContent = 'Saving...';
        try {
            const memId = document.getElementById('edit-member-id').value;
            if(!memId) throw new Error("No member selected");
            
            const payload = {
                member_id: memId,
                camp_id: document.getElementById('camp-select').value,
                camp_fee: parseFloat(container.dataset.campFee),
                site_fee: parseFloat(container.dataset.siteFee),
                other_amount: parseFloat(container.dataset.otherAmount),
                total: parseFloat(container.dataset.total),
                prepaid_applied: parseFloat(document.getElementById('prepaid-input').value)||0,
                payment_date: document.getElementById('payment-date').value,
                notes: document.getElementById('notes').value,
                site_fee_paid_until: container.dataset.newPaidUntilIso,
                prepayment_ids: (memberPrepaymentRecords && memberPrepaymentRecords.length > 0) ? memberPrepaymentRecords.map(p=>p.id) : [],
                headcount: (parseInt(document.getElementById('calc-adults').value)||0) + (parseInt(document.getElementById('calc-kids').value)||0),
                site_type: document.getElementById('calc-site-type').value, // Add Site Type
                concession: document.getElementById('calc-concession').value, // Add Concession
                tenders: [
                    { method: 'EFTPOS', amount: parseFloat(document.getElementById('pay-eftpos').value)||0 },
                    { method: 'Cash', amount: parseFloat(document.getElementById('pay-cash').value)||0 },
                    { method: 'Cheque', amount: parseFloat(document.getElementById('pay-cheque').value)||0 }
                ]
            };
            
            if(document.getElementById('date-mode-toggle').value === 'days') {
                const days = Array.from(document.querySelectorAll('.day-check:checked')).map(c => c.dataset.date).join(', ');
                payload.notes += (payload.notes ? "\n" : "") + `Days: ${days}`;
            } else {
                payload.arrival_date = document.getElementById('arrival-date').value;
                payload.departure_date = document.getElementById('departure-date').value;
            }
            
            if (isRefundMode) {
                 payload.notes += "\n[REFUND]";
            }

            await API.post('/payments', payload);
            alert("Saved!");
            render(container); 
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } catch(e) { alert(e.message); }
        finally { btn.disabled = false; btn.textContent = 'Submit Payment'; }
    };
    
    // Setup initial cancel button handler, using optional chaining for safety
    const qaCancel = document.getElementById('qa-cancel');
    if (qaCancel) {
        qaCancel.onclick = () => document.getElementById('quick-add-modal').classList.add('hidden');
    }
}
