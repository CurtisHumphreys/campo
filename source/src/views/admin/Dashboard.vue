<template>
  <div class="p-4 sm:p-6 space-y-5 max-w-5xl mx-auto">

    <!-- Header -->
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <div class="section-label">Finance Overview</div>
        <h1 class="text-2xl font-bold text-ink-100">Dashboard</h1>
        <p v-if="summary.camp" class="text-sm text-ink-500 mt-0.5">
          {{ summary.camp.name }} | {{ fmtDate(summary.camp.start_date) }} to {{ fmtDate(summary.camp.end_date) }} | {{ summary.camp.status }}
        </p>
      </div>
      <div class="flex items-center gap-2">
        <select v-if="allCamps.length" v-model="selectedCampId" @change="loadAll"
          class="text-sm">
          <option v-for="c in allCamps" :key="c.id" :value="c.id">
            {{ c.name }} ({{ c.year }})
          </option>
        </select>
        <button @click="loadAll" :disabled="loading" class="btn btn-ghost btn-sm">
          {{ loading ? '…' : 'Refresh' }}
        </button>
      </div>
    </div>

    <LoadingSpinner v-if="loading && !summary.camp" :full="true" />

    <template v-if="summary.camp">

      <!-- Top 3 finance cards -->
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div class="card p-5">
          <div class="section-label mb-1">Total Taken</div>
          <div class="text-3xl font-bold text-ink-100">${{ fmt(summary.finance.total_taken) }}</div>
          <div class="text-xs text-ink-500 mt-1">{{ summary.finance.tx_count }} transaction{{ summary.finance.tx_count !== 1 ? 's' : '' }} recorded for this camp.</div>
        </div>
        <div class="card p-5">
          <div class="section-label mb-1">Camp Fees</div>
          <div class="text-3xl font-bold text-ink-100">${{ fmt(summary.finance.camp_fees) }}</div>
          <div class="text-xs text-ink-500 mt-1">Collected through Take Payments.</div>
        </div>
        <div class="card p-5">
          <div class="section-label mb-1">Site Fees</div>
          <div class="text-3xl font-bold text-ink-100">${{ fmt(summary.finance.site_fees) }}</div>
          <div class="text-xs text-ink-500 mt-1">EFTPOS, cash and bank transfer.</div>
        </div>
      </div>

      <!-- Pre-payments + In Camp Now -->
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">

        <!-- Pre-payments -->
        <div class="card p-5 space-y-3">
          <div class="flex items-center justify-between">
            <div>
              <div class="section-label">Total Pre-payments</div>
              <div class="text-2xl font-bold text-ink-100">${{ fmt(summary.prepayments.total_amount) }}</div>
            </div>
            <RouterLink to="/prepayments" class="btn btn-ghost btn-sm">Open Pre-payments</RouterLink>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div class="bg-surface-700 rounded-xl p-3">
              <div class="text-xs text-ink-500 mb-0.5">Applied to Payments</div>
              <div class="text-lg font-bold text-emerald-400">${{ fmt(summary.prepayments.applied) }}</div>
            </div>
            <div class="bg-surface-700 rounded-xl p-3">
              <div class="text-xs text-ink-500 mb-0.5">Remaining Balance</div>
              <div class="text-lg font-bold text-amber-400">${{ fmt(summary.prepayments.remaining) }}</div>
            </div>
          </div>
          <div class="text-xs text-ink-500">
            {{ summary.prepayments.matched }} matched | {{ summary.prepayments.unmatched }} unmatched
          </div>
        </div>

        <!-- In Camp Now -->
        <div class="card p-5 space-y-3">
          <div class="flex items-center justify-between">
            <div>
              <div class="section-label">Camp Snapshot</div>
              <div class="text-lg font-bold text-ink-100">In Camp Now</div>
            </div>
            <div class="text-right">
              <div class="text-xs text-ink-500">Total Headcount</div>
              <div class="text-2xl font-bold text-ember-400">{{ summary.in_camp.headcount }}</div>
            </div>
          </div>
          <p class="text-xs text-ink-500">Based on payment dates for the selected camp.</p>
          <div v-if="summary.in_camp.rows.length" class="overflow-auto max-h-40">
            <table class="w-full text-xs">
              <thead>
                <tr class="text-ink-500 border-b border-surface-700">
                  <th class="text-left pb-1 font-medium">Site</th>
                  <th class="text-left pb-1 font-medium">Name</th>
                  <th class="text-right pb-1 font-medium">Count</th>
                  <th class="text-right pb-1 font-medium">Until</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-surface-700">
                <tr v-for="row in summary.in_camp.rows" :key="row.household_name" class="text-ink-300">
                  <td class="py-1 text-ember-400 font-medium">{{ row.site_number || '—' }}</td>
                  <td class="py-1">{{ row.household_name }}</td>
                  <td class="py-1 text-right">{{ row.headcount }}</td>
                  <td class="py-1 text-right">{{ fmtDateShort(row.until) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-else class="text-xs text-ink-500 italic">No guests found in camp for today's date.</p>
        </div>
      </div>

      <!-- Reconciliation -->
      <div class="card p-5 space-y-4">
        <div class="flex items-center justify-between flex-wrap gap-2">
          <div>
            <div class="section-label">Detailed Reconciliation</div>
            <div class="text-lg font-bold text-ink-100">Reconciliation</div>
          </div>
          <label class="flex items-center gap-2 cursor-pointer text-sm text-ink-400">
            <input type="checkbox" v-model="todayOnly" @change="loadRecon" class="rounded" />
            Today Only
          </label>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <!-- Total Taken -->
          <div class="bg-surface-700 rounded-xl p-4 space-y-2">
            <div class="font-semibold text-ink-300 text-sm">Total Taken</div>
            <div class="text-2xl font-bold text-ink-100">${{ fmt(recon.total_taken) }}</div>
            <div class="space-y-1 text-xs">
              <div class="flex justify-between text-ink-400"><span>EFTPOS</span><span>${{ fmt(recon.tender_eftpos) }}</span></div>
              <div class="flex justify-between text-ink-400"><span>Cash</span><span>${{ fmt(recon.tender_cash) }}</span></div>
              <div class="flex justify-between text-ink-400"><span>Bank</span><span>${{ fmt(recon.tender_bank) }}</span></div>
              <div class="flex justify-between text-ink-400"><span>Pre-Paid</span><span>${{ fmt(recon.prepaid_applied) }}</span></div>
            </div>
            <div v-if="recon.tx_count === 0" class="text-xs text-ink-600 italic">No totals in this date range.</div>
          </div>

          <!-- Camp Fees -->
          <div class="bg-surface-700 rounded-xl p-4 space-y-2">
            <div class="font-semibold text-ink-300 text-sm">Camp Fees</div>
            <div class="text-2xl font-bold text-ink-100">${{ fmt(recon.camp_fees) }}</div>
            <div v-if="recon.tx_count === 0" class="text-xs text-ink-600 italic">No camp-fee takings in this date range.</div>
            <div v-else class="text-xs text-ink-500">Charged across {{ recon.tx_count }} transaction{{ recon.tx_count !== 1 ? 's' : '' }}.</div>
          </div>

          <!-- Site Fees -->
          <div class="bg-surface-700 rounded-xl p-4 space-y-2">
            <div class="font-semibold text-ink-300 text-sm">Site Fees</div>
            <div class="text-2xl font-bold text-ink-100">${{ fmt(recon.site_fees) }}</div>
            <div v-if="recon.tx_count === 0" class="text-xs text-ink-600 italic">No site-fee takings in this date range.</div>
          </div>
        </div>

        <div class="text-xs text-ink-500 text-right">Transactions <span class="font-medium text-ink-300">{{ recon.tx_count }}</span></div>
      </div>

      <!-- Daily Financial Takings chart -->
      <div v-if="chartData.dates.length" class="card p-5 space-y-3">
        <div>
          <div class="section-label">Camp Duration</div>
          <div class="text-lg font-bold text-ink-100">Daily Financial Takings</div>
        </div>
        <div class="flex gap-4 text-xs flex-wrap">
          <span class="flex items-center gap-1.5"><span class="w-3 h-0.5 bg-sky-400 inline-block"></span>Total Taken</span>
          <span class="flex items-center gap-1.5"><span class="w-3 h-0.5 bg-emerald-400 inline-block"></span>Camp Fees</span>
          <span class="flex items-center gap-1.5"><span class="w-3 h-0.5 bg-amber-400 inline-block"></span>Site Fees</span>
        </div>
        <div class="overflow-x-auto">
          <svg :viewBox="`0 0 ${CW} ${CH}`" class="w-full" style="min-width:320px;height:180px;">
            <!-- Y gridlines -->
            <template v-for="i in 4" :key="i">
              <line :x1="PAD" :y1="yPx(chartMaxY * i/4)" :x2="CW-10" :y2="yPx(chartMaxY * i/4)"
                stroke="#2a2825" stroke-width="1"/>
              <text :x="PAD-4" :y="yPx(chartMaxY * i/4)+4" text-anchor="end" class="chart-label">
                ${{ fmtK(chartMaxY * i/4) }}
              </text>
            </template>
            <!-- Lines -->
            <polyline :points="linePoints(chartData.total_taken)" fill="none" stroke="#38bdf8" stroke-width="2" stroke-linejoin="round"/>
            <polyline :points="linePoints(chartData.camp_fees)"   fill="none" stroke="#34d399" stroke-width="2" stroke-linejoin="round"/>
            <polyline :points="linePoints(chartData.site_fees)"   fill="none" stroke="#fbbf24" stroke-width="2" stroke-linejoin="round"/>
            <!-- X axis labels -->
            <template v-for="(date, i) in chartData.dates" :key="date">
              <text v-if="i % Math.max(1, Math.floor(chartData.dates.length/6)) === 0"
                :x="xPx(i)" :y="CH-2" text-anchor="middle" class="chart-label">
                {{ fmtDateAxis(date) }}
              </text>
            </template>
          </svg>
        </div>
      </div>

      <!-- Headcount chart -->
      <div v-if="chartData.dates.length" class="card p-5 space-y-3">
        <div>
          <div class="section-label">Camp Progress</div>
          <div class="text-lg font-bold text-ink-100">Headcount</div>
        </div>
        <div class="overflow-x-auto">
          <svg :viewBox="`0 0 ${CW} ${CH}`" class="w-full" style="min-width:320px;height:160px;">
            <template v-for="i in 3" :key="i">
              <line :x1="PAD" :y1="yPx(hcMax * i/3)" :x2="CW-10" :y2="yPx(hcMax * i/3)"
                stroke="#2a2825" stroke-width="1"/>
              <text :x="PAD-4" :y="yPx(hcMax * i/3)+4" text-anchor="end" class="chart-label">
                {{ Math.round(hcMax * i/3) }}
              </text>
            </template>
            <template v-for="(hc, i) in chartData.headcount" :key="i">
              <rect
                :x="xPx(i) - barW/2 + 1"
                :y="yPx(hc)"
                :width="Math.max(barW - 2, 2)"
                :height="CH - XPAD - yPx(hc)"
                fill="#6366f1" rx="2"/>
              <text v-if="i % Math.max(1, Math.floor(chartData.dates.length/6)) === 0"
                :x="xPx(i)" :y="CH-2" text-anchor="middle" class="chart-label">
                {{ fmtDateAxis(chartData.dates[i]) }}
              </text>
            </template>
          </svg>
        </div>
      </div>

    </template>

    <!-- No camp -->
    <div v-else-if="!loading" class="card p-8 flex flex-col items-center text-center gap-3">
      <span class="text-4xl">🏕️</span>
      <div class="font-semibold text-ink-200">No camps found</div>
      <RouterLink to="/camps" class="btn btn-primary btn-sm">Go to Camps</RouterLink>
    </div>

  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { api } from '@/api.js'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

// ── Chart constants ───────────────────────────────────────────────────────────
const CW = 600, CH = 150, PAD = 44, XPAD = 18

// ── State ─────────────────────────────────────────────────────────────────────
const loading        = ref(true)
const selectedCampId = ref(null)
const todayOnly      = ref(true)
const allCamps       = ref([])

const emptySummary = () => ({
  camp: null, all_camps: [],
  finance: { tx_count:0, total_taken:0, camp_fees:0, site_fees:0, total_headcount:0,
             tender_eftpos:0, tender_cash:0, tender_bank:0, prepaid_applied:0 },
  prepayments: { count:0, total_amount:0, applied:0, remaining:0, matched:0, unmatched:0 },
  in_camp: { headcount:0, rows:[] },
})
const emptyRecon = () => ({ tx_count:0, total_taken:0, camp_fees:0, site_fees:0,
  tender_eftpos:0, tender_cash:0, tender_bank:0, prepaid_applied:0 })
const emptyChart = () => ({ dates:[], total_taken:[], camp_fees:[], site_fees:[], headcount:[] })

const summary   = ref(emptySummary())
const recon     = ref(emptyRecon())
const chartData = ref(emptyChart())

// ── Chart helpers ─────────────────────────────────────────────────────────────
const chartMaxY = computed(() => {
  const vals = chartData.value.total_taken
  return vals.length ? Math.max(...vals) * 1.1 || 1 : 1
})
const hcMax = computed(() => {
  const vals = chartData.value.headcount
  return vals.length ? Math.max(...vals) * 1.15 || 1 : 1
})
const barW = computed(() => {
  const n = chartData.value.dates.length
  return n > 0 ? Math.max(4, (CW - PAD - 10) / n) : 10
})

function xPx(i) {
  const n = chartData.value.dates.length
  if (n <= 1) return PAD + (CW - PAD - 10) / 2
  return PAD + (i / (n - 1)) * (CW - PAD - 10)
}
function yPx(v) {
  return (CH - XPAD) - ((v / chartMaxY.value) * (CH - XPAD - 8)) + 4
}
function linePoints(vals) {
  return vals.map((v, i) => `${xPx(i).toFixed(1)},${yPx(v).toFixed(1)}`).join(' ')
}
function fmtK(v) {
  return v >= 1000 ? (v/1000).toFixed(0) + 'k' : v.toFixed(0)
}
function fmtDateAxis(s) {
  if (!s) return ''
  const [,m,d] = s.split('-')
  const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']
  return `${parseInt(d)} ${months[parseInt(m)-1]}`
}

// ── Data loading ──────────────────────────────────────────────────────────────
async function loadAll() {
  loading.value = true
  try {
    const s = await api.dashboardV2.summary(selectedCampId.value)
    summary.value = s
    if (s.all_camps?.length) {
      allCamps.value = s.all_camps
      if (!selectedCampId.value && s.camp) selectedCampId.value = s.camp.id
    }
    if (s.camp) {
      const cid = s.camp.id
      const [r, c] = await Promise.all([
        api.dashboardV2.reconciliation(cid, todayOnly.value ? today() : null),
        api.dashboardV2.chartData(cid),
      ])
      recon.value     = r
      chartData.value = c
    }
  } catch {}
  loading.value = false
}

async function loadRecon() {
  if (!summary.value.camp) return
  try {
    recon.value = await api.dashboardV2.reconciliation(
      summary.value.camp.id,
      todayOnly.value ? today() : null
    )
  } catch {}
}

// ── Formatting ────────────────────────────────────────────────────────────────
function today() { return new Date().toISOString().slice(0, 10) }
function fmt(v) { return (parseFloat(v) || 0).toLocaleString('en-AU', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
function fmtDate(s) {
  if (!s) return ''
  const [y, m, d] = s.split('-')
  const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']
  return `${parseInt(d)} ${months[parseInt(m)-1]} ${y}`
}
function fmtDateShort(s) {
  if (!s) return '—'
  const [, m, d] = s.split('-')
  const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']
  return `${parseInt(d)} ${months[parseInt(m)-1]}`
}

onMounted(loadAll)
</script>

<style scoped>
.chart-label { font-size: 9px; fill: #6b6459; font-family: ui-monospace, monospace; }
</style>
