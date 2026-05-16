<template>
  <div class="p-6 max-w-5xl mx-auto space-y-5">
    <div class="flex items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold text-ink-100">Payment Records</h1>
        <p class="text-sm text-ink-500 mt-0.5">All payments taken for a camp</p>
      </div>
      <button v-if="selectedCampId && !loading" @click="exportCsv"
        :disabled="exporting"
        class="btn btn-ghost text-ink-300 btn-sm shrink-0">
        {{ exporting ? 'Exporting…' : '↓ Export CSV' }}
      </button>
    </div>

    <!-- Camp selector -->
    <div class="card p-4 flex items-center gap-3">
      <label class="text-sm text-ink-400 flex-none">Camp</label>
      <select v-model="selectedCampId" @change="load" class="flex-1 text-sm">
        <option v-for="c in camps" :key="c.id" :value="c.id">
          {{ c.name }}{{ c.status === 'active' ? ' (active)' : '' }}
        </option>
      </select>
    </div>

    <LoadingSpinner v-if="loading" :full="true" />

    <template v-else-if="selectedCampId">
      <!-- Summary -->
      <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
        <div class="card p-4 text-center">
          <div class="text-2xl font-bold text-ink-100">{{ summary.payment_count ?? 0 }}</div>
          <div class="text-xs text-ink-500 mt-1">Payments</div>
        </div>
        <div class="card p-4 text-center">
          <div class="text-xl font-bold text-ember-400">${{ fmt(summary.total_tendered) }}</div>
          <div class="text-xs text-ink-500 mt-1">Total Tendered</div>
        </div>
        <div class="card p-4 text-center">
          <div class="text-xl font-bold text-sky-400">${{ fmt(summary.total_eftpos) }}</div>
          <div class="text-xs text-ink-500 mt-1">EFTPOS</div>
        </div>
        <div class="card p-4 text-center">
          <div class="text-xl font-bold text-emerald-400">${{ fmt(summary.total_cash) }}</div>
          <div class="text-xs text-ink-500 mt-1">Cash</div>
        </div>
        <div class="card p-4 text-center">
          <div class="text-xl font-bold text-violet-400">${{ fmt(summary.total_bank) }}</div>
          <div class="text-xs text-ink-500 mt-1">Bank</div>
        </div>
      </div>

      <!-- Search -->
      <input v-model="search" type="text" placeholder="Search household…"
        class="w-full text-sm" @input="onSearch" />

      <EmptyState v-if="!payments.length" icon="💳" title="No payments"
        subtitle="No payments recorded for this camp." />

      <!-- List -->
      <div v-else class="space-y-2">
        <div v-for="p in payments" :key="p.id" class="card p-4 space-y-2">
          <!-- Household + date -->
          <div class="flex items-start gap-3">
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2 flex-wrap">
                <span class="font-medium text-ink-200">{{ p.household_name }}</span>
                <span v-if="p.site_number" class="badge bg-surface-600 text-ink-400">Site {{ p.site_number }}</span>
                <span v-if="p.headcount" class="badge bg-surface-600 text-ink-500">{{ p.headcount }} pax</span>
              </div>
              <div class="text-xs text-ink-500 mt-0.5 flex gap-3 flex-wrap">
                <span>{{ formatDate(p.payment_date) }}</span>
                <span v-if="p.arrival_date">Arr {{ formatDateShort(p.arrival_date) }}</span>
                <span v-if="p.departure_date">Dep {{ formatDateShort(p.departure_date) }}</span>
                <span v-if="p.notes" class="text-ink-400 italic">{{ p.notes }}</span>
              </div>
            </div>
            <div class="text-right flex-none">
              <div class="text-lg font-bold text-ink-100">${{ fmt(p.total) }}</div>
              <div class="text-xs text-ink-500">cash due</div>
            </div>
          </div>

          <!-- Fee breakdown -->
          <div class="flex gap-4 text-xs text-ink-500 flex-wrap">
            <span v-if="+p.camp_fee">Camp <span class="text-ink-300">${{ fmt(p.camp_fee) }}</span></span>
            <span v-if="+p.site_fee">Site <span class="text-ink-300">${{ fmt(p.site_fee) }}</span></span>
            <span v-if="+p.other_amount">Other <span class="text-ink-300">${{ fmt(p.other_amount) }}</span></span>
            <span v-if="+p.prepaid_applied">Prepaid <span class="text-emerald-400">-${{ fmt(p.prepaid_applied) }}</span></span>
          </div>

          <!-- Tender chips -->
          <div class="flex gap-2 flex-wrap">
            <span v-if="+p.tender_eftpos" class="badge bg-sky-500/15 text-sky-400">EFTPOS ${{ fmt(p.tender_eftpos) }}</span>
            <span v-if="+p.tender_cash"   class="badge bg-emerald-500/15 text-emerald-400">Cash ${{ fmt(p.tender_cash) }}</span>
            <span v-if="+p.tender_bank"   class="badge bg-violet-500/15 text-violet-400">Bank ${{ fmt(p.tender_bank) }}</span>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { api } from '@/api.js'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import EmptyState from '@/components/EmptyState.vue'

const loading        = ref(true)
const exporting      = ref(false)
const camps          = ref([])
const selectedCampId = ref(null)
const payments       = ref([])
const summary        = ref({})
const search         = ref('')

let searchTimer = null

function fmt(v) { return (parseFloat(v) || 0).toFixed(2) }

function formatDate(d) {
  if (!d) return ''
  return new Date(d).toLocaleString('en-AU', {
    day: 'numeric', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit'
  })
}
function formatDateShort(d) {
  if (!d) return ''
  return new Date(d).toLocaleDateString('en-AU', { day: 'numeric', month: 'short' })
}

async function loadCamps() {
  const res = await api.camps.list()
  camps.value = res
  const active = res.find(c => c.status === 'active')
  selectedCampId.value = active?.id ?? res[0]?.id ?? null
}

async function load() {
  if (!selectedCampId.value) return
  loading.value = true
  try {
    const [recs, sum] = await Promise.all([
      api.payments.list({ camp_id: selectedCampId.value, search: search.value }),
      api.payments.summary(selectedCampId.value),
    ])
    payments.value = recs
    summary.value  = sum
  } catch {}
  loading.value = false
}

function onSearch() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(load, 300)
}

async function exportCsv() {
  exporting.value = true
  try {
    const all = await api.payments.list({ camp_id: selectedCampId.value, search: '' })
    const campName = camps.value.find(c => c.id === selectedCampId.value)?.name ?? 'camp'
    const headers = ['Date','Household','Site','Headcount','Camp Fee','Site Fee','Other','Prepaid Applied','Total','EFTPOS','Cash','Bank','Arrival','Departure','Notes']
    const rows = all.map(p => [
      formatDate(p.payment_date), p.household_name, p.site_number ?? '', p.headcount ?? '',
      fmt(p.camp_fee), fmt(p.site_fee), fmt(p.other_amount), fmt(p.prepaid_applied), fmt(p.total),
      fmt(p.tender_eftpos), fmt(p.tender_cash), fmt(p.tender_bank),
      p.arrival_date ?? '', p.departure_date ?? '', p.notes ?? ''
    ])
    const csv = [headers, ...rows].map(r => r.map(v => `"${String(v).replace(/"/g, '""')}"`).join(',')).join('\n')
    const blob = new Blob([csv], { type: 'text/csv' })
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob)
    a.download = `payments-${campName.replace(/\s+/g, '-').toLowerCase()}.csv`
    a.click()
    URL.revokeObjectURL(a.href)
  } catch { /* silent */ }
  finally { exporting.value = false }
}

onMounted(async () => {
  await loadCamps()
  await load()
})
</script>
