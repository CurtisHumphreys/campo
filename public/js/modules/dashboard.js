import * as API from '../api.js';

// Global variable to hold the chart instance across renders
let headcountChart = null;

export async function render(container) {
    // DESTROY EXISTING CHART TO PREVENT CRASH
    // If we re-render the HTML, the old canvas is removed from DOM.
    // The old Chart.js instance loses its context but tries to resize, causing a loop.
    if (headcountChart) {
        headcountChart.destroy();
        headcountChart = null;
    }

    container.innerHTML = `
        <h1>Dashboard</h1>
        <div class="dashboard-controls" style="margin-bottom:1rem; display:flex; flex-wrap:wrap; align-items:center; gap:1rem;">
            <select id="dash-camp-select" style="padding:0.5rem; border-radius:0.5rem; border:1px solid #ddd;">
                <option value="">Loading...</option>
            </select>
            <button id="refresh-btn" class="small secondary">Refresh</button>
        </div>
        
        <div class="grid-2">
            <!-- Left Column: Stats & Financials -->
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
                    <h2>Reconciliation</h2>
                    <div style="font-size:0.9rem;">
                        <label style="margin-right:0.5rem;"><input type="checkbox" id="today-toggle" checked> Today Only</label>
                    </div>
                </div>
                
                <div id="date-range-inputs" class="hidden" style="margin-bottom:1rem; display:flex; gap:0.5rem;">
                    <input type="date" id="start-date" class="small-input">
                    <input type="date" id="end-date" class="small-input">
                </div>

                <div class="stat-row"><span>Total Taken:</span><strong id="total-taken">$0.00</strong></div>
                <div class="stat-row"><span>EFTPOS:</span><span id="total-eftpos">$0.00</span></div>
                <div class="stat-row"><span>Cash:</span><span id="total-cash">$0.00</span></div>
                <div class="stat-row"><span>Cheque:</span><span id="total-cheque">$0.00</span></div>
                <hr style="margin: 0.5rem 0; border:0; border-top:1px solid #eee;">
                <div class="stat-row"><span>Site Contribution:</span><span id="site-contribution">$0.00</span></div>
                <div class="stat-row"><span>Camp Fees:</span><span id="camp-fee">$0.00</span></div>
                <div class="stat-row"><span>Transactions:</span><span id="payment-count">0</span></div>
            </div>

            <!-- Right Column: Current Status -->
            <div class="card">
                <h2>In Camp Now</h2>
                <p class="text-muted" style="font-size:0.85rem; margin-bottom:0.5rem;">Based on payment dates for selected camp.</p>
                <div class="stat-row" style="background:#eff6ff; padding:0.5rem; border-radius:0.5rem; margin-bottom:1rem;">
                    <span>Total Headcount:</span>
                    <strong id="current-total-headcount" style="color:#2563eb; font-size:1.2rem;">0</strong>
                </div>
                <div style="max-height:300px; overflow-y:auto; border:1px solid #f1f5f9; border-radius:0.5rem;">
                    <table class="data-table" style="font-size:0.85rem;">
                        <thead>
                            <tr>
                                <th style="position:sticky; top:0; background:#f8fafc;">Site</th>
                                <th style="position:sticky; top:0; background:#f8fafc;">Name</th>
                                <th style="position:sticky; top:0; background:#f8fafc; text-align:center;">Count</th>
                                <th style="position:sticky; top:0; background:#f8fafc;">Until</th>
                            </tr>
                        </thead>
                        <tbody id="in-camp-list">
                            <tr><td colspan="4" style="text-align:center;">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Full Width Chart -->
        <div class="card" style="margin-top:1.5rem;">
            <h2>Camp Progress & Headcount</h2>
            <div class="chart-wrap" style="height:350px;">
                <canvas id="headcount-chart"></canvas>
            </div>
        </div>
    `;

    // Initialize Date Inputs
    const today = new Date();
    const localDate = today.toLocaleDateString('en-CA'); // YYYY-MM-DD
    const startInput = document.getElementById('start-date');
    const endInput = document.getElementById('end-date');
    if (startInput && endInput) {
        startInput.value = localDate;
        endInput.value = localDate;
    }

    // Toggle Logic
    const toggle = document.getElementById('today-toggle');
    const dateRangeDiv = document.getElementById('date-range-inputs');

    toggle.addEventListener('change', () => {
        if (toggle.checked) {
            dateRangeDiv.classList.add('hidden');
        } else {
            dateRangeDiv.classList.remove('hidden');
        }
        // Re-fetch financials with new date logic
        fetchFinancials();
    });

    startInput.addEventListener('change', fetchFinancials);
    endInput.addEventListener('change', fetchFinancials);

    // Load Camps for selector
    const camps = await API.get('/camps');
    const campSelect = document.getElementById('dash-camp-select');
    
    if (camps.length > 0) {
        campSelect.innerHTML = camps.map(c => `<option value="${c.id}">${c.name}</option>`).join('');
        // Trigger fetch for first camp
        const initialCampId = camps[0].id;
        fetchAndRender(initialCampId);
    } else {
        campSelect.innerHTML = '<option value="">No Camps</option>';
    }

    campSelect.addEventListener('change', (e) => fetchAndRender(e.target.value));
    document.getElementById('refresh-btn').addEventListener('click', () => {
        fetchAndRender(campSelect.value);
        fetchFinancials();
    });

    // Initial Financial Fetch
    fetchFinancials();

    async function fetchFinancials() {
        let params = '';
        if (!toggle.checked && startInput.value && endInput.value) {
            params = `?start=${startInput.value}&end=${endInput.value}`;
        }
        
        // Visual loading state
        document.getElementById('total-taken').textContent = '...';
        
        try {
            const summary = await API.get(`/payments/summary${params}`); 
            updateFinancialStats(summary);
        } catch (e) { 
            console.error('Financial fetch failed', e);
            document.getElementById('total-taken').textContent = '-';
        }
    }

    async function fetchAndRender(campId) {
        if (!campId) return;
        
        // Clear previous state
        document.getElementById('in-camp-list').innerHTML = '<tr><td colspan="4" style="text-align:center;">Loading...</td></tr>';
        
        try {
            // Fetch detailed dashboard stats
            const stats = await API.get(`/payments/dashboard-stats?camp_id=${campId}`);
            
            if (stats.error) {
                document.getElementById('in-camp-list').innerHTML = `<tr><td colspan="4" style="text-align:center; color:red;">${stats.error}</td></tr>`;
                return;
            }

            renderInCampList(stats.current_guests);
            updateChart(stats.chart);

        } catch (err) {
            console.error('Failed to fetch dashboard stats:', err);
            document.getElementById('in-camp-list').innerHTML = `<tr><td colspan="4" style="text-align:center; color:red;">Error loading data</td></tr>`;
        }
    }

    function formatMoney(amount) {
        const num = parseFloat(amount || 0);
        return '$' + num.toFixed(2);
    }

    function updateFinancialStats(data) {
        document.getElementById('total-taken').textContent = formatMoney(data.total_revenue);
        document.getElementById('total-eftpos').textContent = formatMoney(data.eftpos);
        document.getElementById('total-cash').textContent = formatMoney(data.cash);
        document.getElementById('total-cheque').textContent = formatMoney(data.cheque);
        document.getElementById('site-contribution').textContent = formatMoney(data.site_contribution_total);
        document.getElementById('camp-fee').textContent = formatMoney(data.camp_fee_total);
        document.getElementById('payment-count').textContent = data.payment_count || 0;
    }

    function renderInCampList(guests) {
        const tbody = document.getElementById('in-camp-list');
        const totalEl = document.getElementById('current-total-headcount');
        
        if (!guests || guests.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:1rem; color:#666;">No guests found in camp for today\'s date.</td></tr>';
            totalEl.textContent = '0';
            return;
        }

        // Calculate total headcount
        const total = guests.reduce((sum, g) => sum + (parseInt(g.headcount)||0), 0);
        totalEl.textContent = total;

        // Sort by Site
        guests.sort((a, b) => {
            const sA = a.site || 'Z'; 
            const sB = b.site || 'Z';
            return sA.localeCompare(sB, undefined, {numeric: true});
        });

        tbody.innerHTML = guests.map(g => {
            // Format Until date
            const until = new Date(g.until).toLocaleDateString('en-AU', {day:'numeric', month:'short'});
            return `
                <tr>
                    <td style="font-weight:bold;">${g.site}</td>
                    <td>${g.name}</td>
                    <td style="text-align:center;">${g.headcount}</td>
                    <td style="color:#666;">${until}</td>
                </tr>
            `;
        }).join('');
    }

    function updateChart(chartData) {
        const canvas = document.getElementById('headcount-chart');
        if (!canvas) return;
        
        // Destroy old chart
        if (headcountChart) {
            headcountChart.destroy();
            headcountChart = null;
        }

        const ctx = canvas.getContext('2d');

        // Check if we have data
        if (!chartData || !chartData.labels || chartData.labels.length === 0) {
            // Draw text on canvas saying "No Data" or handle UI
            return;
        }

        headcountChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: chartData.labels,
                datasets: [
                    {
                        label: 'Total Headcount',
                        data: chartData.headcount,
                        backgroundColor: 'rgba(37, 99, 235, 0.7)', // Primary Blue
                        borderRadius: 4,
                        order: 2,
                        yAxisID: 'y'
                    },
                    {
                        type: 'line',
                        label: 'Avg per Site',
                        data: chartData.average,
                        borderColor: '#10b981', // Emerald
                        backgroundColor: '#10b981',
                        borderWidth: 2,
                        pointRadius: 3,
                        tension: 0.3,
                        order: 1,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: { display: true, position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.parsed.y !== null) {
                                    label += context.parsed.y;
                                }
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: 'Total People' },
                        grid: { color: '#f1f5f9' }
                    },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        title: { display: true, text: 'Avg per Site' },
                        grid: { drawOnChartArea: false } 
                    },
                    x: {
                        grid: { display: false }
                    }
                }
            }
        });
    }
}