import * as API from '../api.js';

// Global variable to hold the chart instance across renders
let headcountChart = null;
let financialChart = null;

export async function render(container) {
    // DESTROY EXISTING CHARTS TO PREVENT CRASH
    if (headcountChart) {
        headcountChart.destroy();
        headcountChart = null;
    }
    if (financialChart) {
        financialChart.destroy();
        financialChart = null;
    }

    // Removed inline background styles to allow CSS dark mode overrides
    container.innerHTML = `
        <h1>Dashboard</h1>
        <div class="dashboard-controls" style="margin-bottom:1rem; display:flex; flex-wrap:wrap; align-items:center; gap:1rem;">
            <select id="dash-camp-select" style="padding:0.5rem; border-radius:0.5rem; border:1px solid var(--border); background:var(--surface); color:var(--text-main);">
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

                <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:1rem; text-align:center;">
                    <!-- Overall Total -->
                    <div class="dashboard-summary-box" id="box-total">
                        <h4 style="margin:0 0 0.5rem 0; font-size:0.9rem;">Total Taken</h4>
                        <strong id="total-revenue" style="display:block; font-size:1.2rem; margin-bottom:0.5rem;">$0.00</strong>
                        <div style="font-size:0.75rem; text-align:left; padding-bottom:0.5rem; border-bottom:1px dashed var(--border);">
                            <div>EFTPOS: <span id="total-eftpos">$0.00</span></div>
                            <div>Cash: <span id="total-cash">$0.00</span></div>
                            <div>Cheque: <span id="total-cheque">$0.00</span></div>
                            <div>Pre-Paid: <span id="total-prepaid">$0.00</span></div>
                        </div>
                        <div id="total-daily-breakdown" style="font-size:0.7rem; text-align:left; margin-top:0.5rem; max-height:150px; overflow-y:auto;"></div>
                    </div>
                    
                    <!-- Camp Fees -->
                    <div class="dashboard-summary-box" id="box-camp">
                        <h4 style="margin:0 0 0.5rem 0; font-size:0.9rem;">Camp Fees</h4>
                        <strong id="camp-revenue" style="display:block; font-size:1.2rem; margin-bottom:0.5rem;">$0.00</strong>
                        <div style="font-size:0.75rem; text-align:left; padding-bottom:0.5rem; border-bottom:1px dashed var(--border);">
                            <div>EFTPOS: <span id="camp-eftpos">$0.00</span></div>
                            <div>Cash: <span id="camp-cash">$0.00</span></div>
                            <div>Cheque: <span id="camp-cheque">$0.00</span></div>
                            <div>Pre-Paid: <span id="camp-prepaid">$0.00</span></div>
                        </div>
                        <div id="camp-daily-breakdown" style="font-size:0.7rem; text-align:left; margin-top:0.5rem; max-height:150px; overflow-y:auto;"></div>
                    </div>
                    
                    <!-- Site Fees -->
                    <div class="dashboard-summary-box" id="box-site">
                        <h4 style="margin:0 0 0.5rem 0; font-size:0.9rem;">Site Fees</h4>
                        <strong id="site-revenue" style="display:block; font-size:1.2rem; margin-bottom:0.5rem;">$0.00</strong>
                        <div style="font-size:0.75rem; text-align:left; padding-bottom:0.5rem; border-bottom:1px dashed var(--border);">
                            <div>EFTPOS: <span id="site-eftpos">$0.00</span></div>
                            <div>Cash: <span id="site-cash">$0.00</span></div>
                            <div>Cheque: <span id="site-cheque">$0.00</span></div>
                            <div>Pre-Paid: <span id="site-prepaid">$0.00</span></div>
                        </div>
                         <div id="site-daily-breakdown" style="font-size:0.7rem; text-align:left; margin-top:0.5rem; max-height:150px; overflow-y:auto;"></div>
                    </div>
                </div>
                
                <div style="text-align:right; margin-top:1rem; font-size:0.8rem; color:var(--text-muted);">
                    Transactions: <span id="payment-count">0</span>
                </div>
            </div>

            <!-- Right Column: Current Status -->
            <div class="card">
                <h2>In Camp Now</h2>
                <p class="text-muted" style="font-size:0.85rem; margin-bottom:0.5rem; color:var(--text-muted);">Based on payment dates for selected camp.</p>
                <div class="stat-row" style="padding:0.5rem; border-radius:0.5rem; margin-bottom:1rem; border:1px solid var(--border);">
                    <span>Total Headcount:</span>
                    <strong id="current-total-headcount" style="color:var(--primary); font-size:1.2rem;">0</strong>
                </div>
                <div style="max-height:300px; overflow-y:auto; border:1px solid var(--border); border-radius:0.5rem;">
                    <table class="data-table" style="font-size:0.85rem;">
                        <thead>
                            <tr>
                                <th style="position:sticky; top:0; background:var(--background-soft);">Site</th>
                                <th style="position:sticky; top:0; background:var(--background-soft);">Name</th>
                                <th style="position:sticky; top:0; background:var(--background-soft); text-align:center;">Count</th>
                                <th style="position:sticky; top:0; background:var(--background-soft);">Until</th>
                            </tr>
                        </thead>
                        <tbody id="in-camp-list">
                            <tr><td colspan="4" style="text-align:center;">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Financial Chart -->
        <div class="card" style="margin-top:1.5rem;">
            <h2>Daily Financial Takings (Camp Duration)</h2>
            <div class="chart-wrap" style="height:350px;">
                <canvas id="financial-chart"></canvas>
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

    // Apply inline styles specifically for light mode, relying on CSS class overrides for dark mode
    // (See style.css updates for .dashboard-summary-box)
    document.getElementById('box-total').style.backgroundColor = '#eff6ff';
    document.getElementById('box-camp').style.backgroundColor = '#ecfdf5';
    document.getElementById('box-site').style.backgroundColor = '#fff7ed';
    
    // For Dark mode compatibility, we should remove these inline styles if dark mode is active? 
    // Actually, best to set them via class in style.css, but since I am editing JS here I will just rely on CSS !important overrides.

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
        document.getElementById('total-revenue').textContent = '...';
        document.getElementById('total-daily-breakdown').innerHTML = '';
        document.getElementById('camp-daily-breakdown').innerHTML = '';
        document.getElementById('site-daily-breakdown').innerHTML = '';

        try {
            const data = await API.get(`/payments/summary${params}`); 
            
            // Total Column
            updateSection('total', data.total);
            // Camp Fees Column
            updateSection('camp', data.camp_fees);
            // Site Fees Column
            updateSection('site', data.site_fees);

            document.getElementById('payment-count').textContent = data.total.count || 0;
            
            // Render Daily Breakdown
            if (data.daily && Object.keys(data.daily).length > 0) {
                renderDailyBreakdown(data.daily);
            }

        } catch (e) { 
            console.error('Financial fetch failed', e);
        }
    }

    function updateSection(prefix, data) {
        // data contains total, eftpos, cash, cheque, prepaid
        document.getElementById(`${prefix}-revenue`).textContent = formatMoney(data.total || data.revenue); // Total key varies
        document.getElementById(`${prefix}-eftpos`).textContent = formatMoney(data.eftpos);
        document.getElementById(`${prefix}-cash`).textContent = formatMoney(data.cash);
        document.getElementById(`${prefix}-cheque`).textContent = formatMoney(data.cheque);
        document.getElementById(`${prefix}-prepaid`).textContent = formatMoney(data.prepaid);
    }
    
    function renderDailyBreakdown(dailyData) {
        const totalBox = document.getElementById('total-daily-breakdown');
        const campBox = document.getElementById('camp-daily-breakdown');
        const siteBox = document.getElementById('site-daily-breakdown');
        
        let totalHtml = '';
        let campHtml = '';
        let siteHtml = '';
        
        for (const [date, stats] of Object.entries(dailyData)) {
            const dateStr = new Date(date).toLocaleDateString('en-AU', { day:'numeric', month:'numeric', year:'2-digit'});
            
            const makeBlock = (title, s) => `
                <div style="margin-top:0.5rem; padding-top:0.25rem; border-top:1px solid var(--border);">
                    <strong>${dateStr}</strong><br>
                    <strong>${formatMoney(s.total || s.revenue)}</strong><br>
                    EFTPOS: ${formatMoney(s.eftpos)}<br>
                    Cash: ${formatMoney(s.cash)}<br>
                    Cheque: ${formatMoney(s.cheque)}<br>
                    Pre-Paid: ${formatMoney(s.prepaid)}
                </div>
            `;
            
            if (stats.total && (stats.total.revenue > 0 || stats.total.total > 0)) totalHtml += makeBlock('Total', stats.total);
            if (stats.camp && stats.camp.total > 0) campHtml += makeBlock('Camp', stats.camp);
            if (stats.site && stats.site.total > 0) siteHtml += makeBlock('Site', stats.site);
        }
        
        totalBox.innerHTML = totalHtml;
        campBox.innerHTML = campHtml;
        siteBox.innerHTML = siteHtml;
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
            updateHeadcountChart(stats.chart);
            updateFinancialChart(stats.financial_chart);

        } catch (err) {
            console.error('Failed to fetch dashboard stats:', err);
            document.getElementById('in-camp-list').innerHTML = `<tr><td colspan="4" style="text-align:center; color:red;">Error loading data</td></tr>`;
        }
    }

    function formatMoney(amount) {
        const num = parseFloat(amount || 0);
        return '$' + num.toFixed(2);
    }

    function renderInCampList(guests) {
        const tbody = document.getElementById('in-camp-list');
        const totalEl = document.getElementById('current-total-headcount');
        
        if (!guests || guests.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:1rem; color:var(--text-muted);">No guests found in camp for today\'s date.</td></tr>';
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
                    <td style="color:var(--text-muted);">${until}</td>
                </tr>
            `;
        }).join('');
    }
    
    function updateFinancialChart(data) {
        const canvas = document.getElementById('financial-chart');
        if (!canvas) return;
        if (financialChart) financialChart.destroy();
        
        if (!data || !data.labels || data.labels.length === 0) return;
        
        const isDark = document.documentElement.classList.contains('dark-mode');
        const textColor = isDark ? '#cbd5e1' : '#64748b';
        const gridColor = isDark ? '#334155' : '#e2e8f0';

        const ctx = canvas.getContext('2d');
        financialChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: [
                    {
                        label: 'Total Taken',
                        data: data.total,
                        borderColor: '#2563eb', // Blue
                        backgroundColor: 'rgba(37, 99, 235, 0.1)',
                        borderWidth: 2,
                        tension: 0.3,
                        fill: true
                    },
                    {
                        label: 'Camp Fees',
                        data: data.camp,
                        borderColor: '#059669', // Green
                        backgroundColor: 'rgba(5, 150, 105, 0.05)',
                        borderWidth: 2,
                        tension: 0.3
                    },
                    {
                        label: 'Site Fees',
                        data: data.site,
                        borderColor: '#ea580c', // Orange
                        backgroundColor: 'rgba(234, 88, 12, 0.05)',
                        borderWidth: 2,
                        tension: 0.3
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { 
                        beginAtZero: true, 
                        title: { display: true, text: 'Amount ($)', color: textColor },
                        grid: { color: gridColor },
                        ticks: { color: textColor }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: textColor }
                    }
                },
                plugins: {
                    legend: { labels: { color: textColor } }
                }
            }
        });
    }

    function updateHeadcountChart(chartData) {
        const canvas = document.getElementById('headcount-chart');
        if (!canvas) return;
        
        // Destroy old chart
        if (headcountChart) {
            headcountChart.destroy();
            headcountChart = null;
        }

        const ctx = canvas.getContext('2d');
        const isDark = document.documentElement.classList.contains('dark-mode');
        const textColor = isDark ? '#cbd5e1' : '#64748b';
        const gridColor = isDark ? '#334155' : '#e2e8f0';

        // Check if we have data
        if (!chartData || !chartData.labels || chartData.labels.length === 0) {
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
                    legend: { display: true, position: 'top', labels: { color: textColor } },
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
                        title: { display: true, text: 'Total People', color: textColor },
                        grid: { color: gridColor },
                        ticks: { color: textColor }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: textColor }
                    }
                }
            }
        });
    }
}
