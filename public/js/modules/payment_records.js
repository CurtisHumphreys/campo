import * as API from '../api.js';

let allPayments = [];

export async function render(container) {
    container.innerHTML = `
        <div class="header-actions">
            <h1>Payment Records</h1>
            <button id="export-btn" class="small secondary">Export CSV</button>
        </div>
        <div class="card">
            <input type="text" id="payment-search" placeholder="Search by Member Name..." class="search-input">
        </div>
        <div class="card" style="overflow-x: auto;">
            <table class="data-table" style="min-width: 1500px;">
                <thead>
                    <tr>
                        <th>Camp</th>
                        <th>Year</th>
                        <th>First Name</th>
                        <th>Last Name</th>
                        <th>Site Type</th>
                        <th>Site #</th>
                        <th>Arrive</th>
                        <th>Depart</th>
                        <th>Nights</th>
                        <th>Pre-paid</th>
                        <th>Camp Fee</th>
                        <th>Site Fee</th>
                        <th>Total</th>
                        <th>EFTPOS</th>
                        <th>Cash</th>
                        <th>Cheque</th>
                        <th>Other</th>
                        <th>Concession</th>
                        <th>Payment Date</th>
                        <th>Site Fee Paid Until</th>
                        <th>Headcount</th>
                        <th style="position:sticky; right:0; background:white; z-index:2;">Actions</th>
                    </tr>
                </thead>
                <tbody id="payments-list">
                    <tr><td colspan="22">Loading...</td></tr>
                </tbody>
            </table>
        </div>
        
        <!-- Edit Payment Modal -->
        <div id="payment-modal" class="modal hidden">
            <div class="modal-content">
                <h2 id="modal-title">Edit Payment</h2>
                <form id="payment-form">
                    <input type="hidden" id="payment-id">
                    
                    <div class="form-group highlight-text text-center">
                        <span id="edit-member-name"></span>
                    </div>

                    <div class="form-group">
                        <label>Date</label>
                        <input type="date" id="edit-payment-date" required>
                    </div>

                    <div class="row">
                        <div class="form-group half">
                            <label>Arrival</label>
                            <input type="date" id="edit-arrival-date">
                        </div>
                        <div class="form-group half">
                            <label>Departure</label>
                            <input type="date" id="edit-departure-date">
                        </div>
                    </div>

                    <div class="row">
                        <div class="form-group half">
                            <label>Camp Fee</label>
                            <input type="number" step="0.01" id="edit-camp-fee" required>
                        </div>
                        <div class="form-group half">
                            <label>Site Fee</label>
                            <input type="number" step="0.01" id="edit-site-fee" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Total</label>
                        <input type="number" step="0.01" id="edit-total" required>
                    </div>

                    <div class="form-group">
                        <label>Notes</label>
                        <textarea id="edit-notes" rows="3"></textarea>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="secondary" id="cancel-modal">Cancel</button>
                        <button type="submit">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    `;

    try {
        allPayments = await API.get('/payments');
        renderTable(allPayments);
    } catch (e) {
        document.getElementById('payments-list').innerHTML = `<tr><td colspan="22">Error loading payments</td></tr>`;
    }

    // Search
    const searchInput = document.getElementById('payment-search');
    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            const term = (e.target.value || '').toLowerCase().trim();
            const tokens = term ? term.split(/\s+/).filter(Boolean) : [];
            const filtered = allPayments.filter(p => {
                if (tokens.length === 0) return true;
                const fn = (p.first_name || '').toLowerCase();
                const ln = (p.last_name || '').toLowerCase();
                const camp = (p.camp_name || '').toLowerCase();
                const notes = (p.notes || '').toLowerCase();
                return tokens.every(t => fn.includes(t) || ln.includes(t) || camp.includes(t) || notes.includes(t));
            });
            renderTable(filtered);
        });
    }

    // Export Button
    document.getElementById('export-btn').addEventListener('click', () => {
        exportToCSV(allPayments);
    });

    // Modal Logic
    const modal = document.getElementById('payment-modal');
    const form = document.getElementById('payment-form');

    const cancelBtn = document.getElementById('cancel-modal');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', () => {
            modal.classList.add('hidden');
        });
    }

    window.editPayment = (id) => {
        const payment = allPayments.find(p => p.id == id);
        if (payment) {
            document.getElementById('payment-id').value = payment.id;
            const ln = payment.last_name || '';
            const fn = payment.first_name || '';
            document.getElementById('edit-member-name').textContent = `${ln}${ln && fn ? ', ' : ''}${fn}` || `Member #${payment.member_id}`;
            document.getElementById('edit-payment-date').value = payment.payment_date.split(' ')[0];
            document.getElementById('edit-arrival-date').value = payment.arrival_date || '';
            document.getElementById('edit-departure-date').value = payment.departure_date || '';
            document.getElementById('edit-camp-fee').value = payment.camp_fee;
            document.getElementById('edit-site-fee').value = payment.site_fee;
            document.getElementById('edit-total').value = payment.total;
            document.getElementById('edit-notes').value = payment.notes;

            modal.classList.remove('hidden');
        }
    };

    window.deletePayment = async (id) => {
        if (confirm('Are you sure you want to delete this payment record? This cannot be undone.')) {
            try {
                await API.post(`/payment/delete?id=${id}`, {});
                alert('Payment deleted.');
                allPayments = await API.get('/payments');
                renderTable(allPayments);
            } catch (e) {
                alert('Error: ' + e.message);
            }
        }
    };

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const id = document.getElementById('payment-id').value;
        const data = {
            payment_date: document.getElementById('edit-payment-date').value,
            arrival_date: document.getElementById('edit-arrival-date').value,
            departure_date: document.getElementById('edit-departure-date').value,
            camp_fee: document.getElementById('edit-camp-fee').value,
            site_fee: document.getElementById('edit-site-fee').value,
            total: document.getElementById('edit-total').value,
            notes: document.getElementById('edit-notes').value
        };

        try {
            await API.post(`/payment/update?id=${id}`, data);
            modal.classList.add('hidden');
            allPayments = await API.get('/payments');
            renderTable(allPayments);
            alert('Saved');
        } catch (err) {
            alert('Error: ' + err.message);
        }
    });
}

function renderTable(payments) {
    const tbody = document.getElementById('payments-list');
    if (!tbody) return;

    if (payments.length === 0) {
        tbody.innerHTML = '<tr><td colspan="22">No payments found</td></tr>';
        return;
    }

    tbody.innerHTML = payments.map(p => {
        const arr = p.arrival_date ? new Date(p.arrival_date).toLocaleDateString('en-AU') : '';
        const dep = p.departure_date ? new Date(p.departure_date).toLocaleDateString('en-AU') : '';
        
        let nights = '';
        if (p.arrival_date && p.departure_date) {
            const d1 = new Date(p.arrival_date);
            const d2 = new Date(p.departure_date);
            nights = Math.max(0, Math.round((d2 - d1) / (1000 * 60 * 60 * 24)));
        }

        return `
        <tr>
            <td>${p.camp_name || '-'}</td>
            <td>${p.camp_year || '-'}</td> 
            <td>${p.first_name || '-'}</td>
            <td>${p.last_name || '-'}</td>
            <td>${p.site_type || '-'}</td> <!-- Display Site Type -->
            <td>${p.site_number || '-'}</td>
            <td>${arr}</td>
            <td>${dep}</td>
            <td>${nights}</td>
            <td>$${parseFloat(p.prepaid_applied||0).toFixed(2)}</td>
            <td>$${parseFloat(p.camp_fee||0).toFixed(2)}</td>
            <td>$${parseFloat(p.site_fee||0).toFixed(2)}</td>
            <td><strong>$${parseFloat(p.total||0).toFixed(2)}</strong></td>
            <td>$${parseFloat(p.tender_eftpos||0).toFixed(2)}</td> <!-- EFTPOS -->
            <td>$${parseFloat(p.tender_cash||0).toFixed(2)}</td>   <!-- Cash -->
            <td>$${parseFloat(p.tender_cheque||0).toFixed(2)}</td> <!-- Cheque -->
            <td>$${parseFloat(p.other_amount||0).toFixed(2)}</td>
            <td>-</td> <!-- Concession status -->
            <td>${new Date(p.payment_date).toLocaleDateString('en-AU')}</td>
            <td>-</td> <!-- Site Fee Paid Until -->
            <td>${p.headcount || 0}</td>
            <td style="position:sticky; right:0; background:white; z-index:2; box-shadow:-2px 0 5px rgba(0,0,0,0.05);">
                <button class="small secondary" onclick="editPayment(${p.id})">Edit</button>
                <button class="small danger" onclick="deletePayment(${p.id})">Del</button>
            </td>
        </tr>
    `}).join('');
}

function exportToCSV(data) {
    if (!data || !data.length) return alert("No data to export");

    const headers = [
        "Camp", "Year", "First Name", "Last Name", "Site Type", "Site Number", 
        "Arrive", "Depart", "Total Nights", "Pre-paid", "Camp Fees", "Site Fees", 
        "Total", "Eftpos", "Cash", "Cheque", "Other", "Concession", 
        "Payment Date", "Site Fee Year Paid", "Headcount"
    ];

    const csvRows = [headers.join(",")];

    data.forEach(p => {
        // Calculate Nights
        let nights = '';
        if (p.arrival_date && p.departure_date) {
            const d1 = new Date(p.arrival_date);
            const d2 = new Date(p.departure_date);
            nights = Math.max(0, Math.round((d2 - d1) / (1000 * 60 * 60 * 24)));
        }

        // Format dates
        const arr = p.arrival_date ? new Date(p.arrival_date).toLocaleDateString('en-AU') : '';
        const dep = p.departure_date ? new Date(p.departure_date).toLocaleDateString('en-AU') : '';
        const payDate = p.payment_date ? new Date(p.payment_date).toLocaleDateString('en-AU') : '';

        // Safe helpers
        const safe = (str) => `"${(str || '').replace(/"/g, '""')}"`;
        const money = (val) => parseFloat(val || 0).toFixed(2);

        const row = [
            safe(p.camp_name),
            safe(p.camp_year),
            safe(p.first_name),
            safe(p.last_name),
            safe(p.site_type), // Export Site Type
            safe(p.site_number),
            safe(arr),
            safe(dep),
            nights,
            money(p.prepaid_applied),
            money(p.camp_fee),
            money(p.site_fee),
            money(p.total),
            money(p.tender_eftpos), // Export EFTPOS
            money(p.tender_cash),   // Export Cash
            money(p.tender_cheque), // Export Cheque
            money(p.other_amount),
            safe(""), // Concession
            safe(payDate),
            safe(""), // Site Fee Year
            p.headcount || 0
        ];
        csvRows.push(row.join(","));
    });

    const csvString = csvRows.join("\n");
    const blob = new Blob([csvString], { type: "text/csv" });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `payments_export_${new Date().toISOString().slice(0,10)}.csv`;
    a.click();
    window.URL.revokeObjectURL(url);
}
