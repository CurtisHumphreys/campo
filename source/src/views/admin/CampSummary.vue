<template>
  <div class="p-6 max-w-5xl mx-auto space-y-5">

    <!-- Header -->
    <div class="flex items-center justify-between gap-4 flex-wrap">
      <div>
        <h1 class="text-2xl font-bold text-ink-100">Camp Summary</h1>
        <p class="text-sm text-ink-500 mt-0.5">Attendance and registration overview</p>
      </div>
      <div class="flex items-center gap-2">
        <select v-if="camps.length" v-model="selectedCampId" @change="load" class="text-sm">
          <option v-for="c in camps" :key="c.id" :value="c.id">
            {{ c.name }}{{ c.status === 'active' ? ' (active)' : '' }}
          </option>
        </select>
        <button @click="exportCsv" :disabled="!data || exporting"
          class="btn btn-ghost text-ink-300 btn-sm shrink-0">
          {{ exporting ? 'Exporting…' : '↓ CSV' }}
        </button>
      </div>
    </div>

    <LoadingSpinner v-if="loading" :full="true" />

    <template v-else-if="data">

      <!-- Camp info -->
      <div class="card p-4 flex flex-wrap gap-4 text-sm">
        <span class="font-semibold text-ink-200">{{ data.camp.name }}</span>
        <span v-if="data.camp.start_date" class="text-ink-400">
          {{ fmtDate(data.camp.start_date) }} – {{ fmtDate(data.camp.end_date) }}
        </span>
        <span class="badge" :class="data.camp.status === 'active' ? 'bg-emerald-500/15 text-emerald-400' : 'bg-surface-600 text-ink-500'">
          {{ data.camp.status }}
        </span>
      </div>

      <!-- Stats row -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div class="card p-4 text-center">
          <div class="text-3xl font-bold text-ink-100">{{ data.stats.households }}</div>
          <div class="text-xs text-ink-500 mt-1">Registrations</div>
        </div>
        <div class="card p-4 text-center">
          <div class="text-3xl font-bold text-ember-400">{{ data.stats.headcount }}</div>
          <div class="text-xs text-ink-500 mt-1">Total Headcount</div>
        </div>
        <div class="card p-4 text-center">
          <div class="text-3xl font-bold text-sky-400">{{ data.stats.sites_used }}</div>
          <div class="text-xs text-ink-500 mt-1">Sites Used</div>
        </div>
        <div class="card p-4 text-center">
          <div class="text-3xl font-bold text-violet-400">{{ data.stats.days ?? '—' }}</div>
          <div class="text-xs text-ink-500 mt-1">Camp Days</div>
        </div>
      </div>

      <!-- Site type breakdown -->
      <div v-if="data.by_site_type.length" class="card p-4 space-y-3">
        <div class="text-xs font-semibold uppercase tracking-wide text-ink-500">By Site Type</div>
        <div class="space-y-2">
          <div v-for="st in data.by_site_type" :key="st.site_type"
            class="flex items-center gap-3">
            <div class="text-sm text-ink-300 w-36 flex-none truncate">{{ st.site_type }}</div>
            <div class="flex-1 bg-surface-700 rounded-full h-2 overflow-hidden">
              <div class="h-2 rounded-full bg-ember-500"
                :style="{ width: data.stats.headcount ? (st.headcount / data.stats.headcount * 100) + '%' : '0%' }" />
            </div>
            <div class="text-xs text-ink-400 w-20 text-right">
              {{ st.households }} HH · {{ st.headcount }} pax
            </div>
          </div>
        </div>
      </div>

      <!-- Daily spread -->
      <div v-if="data.by_day.length" class="card p-4 space-y-3">
        <div class="text-xs font-semibold uppercase tracking-wide text-ink-500">Daily Arrivals &amp; Departures</div>
        <div class="overflow-x-auto">
          <table class="w-full text-xs">
            <thead>
              <tr class="text-ink-500 border-b border-surface-700">
                <th class="text-left py-1.5 pr-3 font-medium">Date</th>
                <th class="text-right py-1.5 px-3 font-medium text-emerald-400">Arrivals</th>
                <th class="text-right py-1.5 px-3 font-medium text-amber-400">Departures</th>
                <th class="text-right py-1.5 pl-3 font-medium text-ink-400">In Camp</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-surface-700/50">
              <tr v-for="day in data.by_day" :key="day.date"
                class="text-ink-300"
                :class="day.date === today ? 'bg-ember-500/5' : ''">
                <td class="py-1.5 pr-3">
                  {{ fmtDateShort(day.date) }}
                  <span v-if="day.date === today" class="badge bg-ember-500/15 text-ember-400 ml-1">Today</span>
                </td>
                <td class="py-1.5 px-3 text-right">
                  <span v-if="day.arrivals" class="text-emerald-400 font-medium">+{{ day.arrivals }}</span>
                  <span v-else class="text-ink-600">—</span>
                </td>
                <td class="py-1.5 px-3 text-right">
                  <span v-if="day.departures" class="text-amber-400 font-medium">-{{ day.departures }}</span>
                  <span v-else class="text-ink-600">—</span>
                </td>
                <td class="py-1.5 pl-3 text-right">{{ day.in_camp || '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Search + registrations list -->
      <div class="space-y-2">
        <input v-model="search" type="text" placeholder="Search household or site…" class="w-full text-sm" />

        <EmptyState v-if="!filtered.length" icon="🏕️" title="No registrations"
          subtitle="No payments recorded for this camp." />

        <div v-else class="card overflow-hidden">
          <table class="w-full text-sm">
            <thead class="bg-surface-700/50">
              <tr class="text-ink-500 text-xs">
                <th class="text-left p-3 font-medium cursor-pointer select-none" @click="sortBy('site_number')">
                  Site <span class="text-ink-600">{{ sortKey === 'site_number' ? (sortDir > 0 ? '↑' : '↓') : '' }}</span>
                </th>
                <th class="text-left p-3 font-medium cursor-pointer select-none" @click="sortBy('household_name')">
                  Household <span class="text-ink-600">{{ sortKey === 'household_name' ? (sortDir > 0 ? '↑' : '↓') : '' }}</span>
                </th>
                <th class="text-right p-3 font-medium">Pax</th>
                <th class="text-right p-3 font-medium hidden sm:table-cell cursor-pointer select-none" @click="sortBy('arrival_date')">
                  Arrival <span class="text-ink-600">{{ sortKey === 'arrival_date' ? (sortDir > 0 ? '↑' : '↓') : '' }}</span>
                </th>
                <th class="text-right p-3 font-medium hidden sm:table-cell">Departure</th>
                <th class="text-left p-3 font-medium hidden md:table-cell">Notes</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-surface-700/30">
              <tr v-for="r in sorted" :key="r.household_name + r.site_number"
                class="text-ink-300 hover:bg-surface-700/30">
                <td class="p-3">
                  <span v-if="r.site_number" class="font-mono text-ember-400 font-semibold">{{ r.site_number }}</span>
                  <span v-else class="text-ink-600 text-xs">—</span>
                  <div v-if="r.site_type" class="text-xs text-ink-600 truncate">{{ r.site_type }}</div>
                </td>
                <td class="p-3 font-medium text-ink-200">{{ r.household_name }}</td>
                <td class="p-3 text-right">{{ r.headcount || '—' }}</td>
                <td class="p-3 text-right hidden sm:table-cell text-xs">{{ fmtDateShort(r.arrival_date) || '—' }}</td>
                <td class="p-3 text-right hidden sm:table-cell text-xs">{{ fmtDateShort(r.departure_date) || '—' }}</td>
                <td class="p-3 hidden md:table-cell text-xs text-ink-500 max-w-xs truncate">{{ r.notes || '' }}</td>
              </tr>
            </tbody>
          </table>
          <div class="px-3 py-2 text-xs text-ink-500 border-t border-surface-700/50">
            {{ filtered.length }} registration{{ filtered.length !== 1 ? 's' : '' }} · {{ filteredHeadcount }} pax
          </div>
        </div>
      </div>

    </template>

    <EmptyState v-else-if="!loading" icon="🏕️" title="No camps found" subtitle="Create a camp first." />

  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { api } from '@/api.js'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import EmptyState from '@/components/EmptyState.vue'

const loading        = ref(true)
const exporting      = ref(false)
const camps          = ref([])
const selectedCampId = ref(null)
const data           = ref(null)
const search         = ref('')
const sortKey        = ref('site_number')
const sortDir        = ref(1)
const today          = new Date().toISOString().slice(0, 10)

function fmtDate(d) {
  if (!d) return ''
  return new Date(d).toLocaleDateString('en-AU', { day: 'numeric', month: 'short', year: 'numeric' })
}
function fmtDateShort(d) {
  if (!d) return ''
  return new Date(d).toLocaleDateString('en-AU', { day: 'numeric', month: 'short' })
}

const filtered = computed(() => {
  if (!data.value) return []
  const q = search.value.toLowerCase()
  if (!q) return data.value.registrations
  return data.value.registrations.filter(r =>
    (r.household_name || '').toLowerCase().includes(q) ||
    (r.site_number || '').toString().includes(q) ||
    (r.site_type || '').toLowerCase().includes(q)
  )
})

const filteredHeadcount = computed(() => filtered.value.reduce((s, r) => s + (r.headcount || 0), 0))

const sorted = computed(() => {
  return [...filtered.value].sort((a, b) => {
    let av = a[sortKey.value] ?? ''
    let bv = b[sortKey.value] ?? ''
    if (sortKey.value === 'site_number') { av = +(av) || 9999; bv = +(bv) || 9999 }
    return av < bv ? -sortDir.value : av > bv ? sortDir.value : 0
  })
})

function sortBy(key) {
  if (sortKey.value === key) sortDir.value *= -1
  else { sortKey.value = key; sortDir.value = 1 }
}

async function loadCamps() {
  const res = await api.camps.list()
  camps.value = res
  const active = res.find(c => c.status === 'active')
  selectedCampId.value = active?.id ?? res[0]?.id ?? null
}

async function load() {
  if (!selectedCampId.value) { loading.value = false; return }
  loading.value = true
  try { data.value = await api.camps.summary(selectedCampId.value) }
  catch { data.value = null }
  loading.value = false
}

function exportCsv() {
  if (!data.value) return
  exporting.value = true
  try {
    const campName = data.value.camp.name
    const headers = ['Site','Site Type','Household','Headcount','Arrival','Departure','Notes']
    const rows = data.value.registrations.map(r => [
      r.site_number ?? '', r.site_type ?? '', r.household_name ?? '',
      r.headcount ?? '', r.arrival_date ?? '', r.departure_date ?? '', r.notes ?? ''
    ])
    const csv = [headers, ...rows]
      .map(r => r.map(v => `"${String(v).replace(/"/g, '""')}"`).join(','))
      .join('\n')
    const blob = new Blob([csv], { type: 'text/csv' })
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob)
    a.download = `summary-${campName.replace(/\s+/g, '-').toLowerCase()}.csv`
    a.click()
    URL.revokeObjectURL(a.href)
  } finally { exporting.value = false }
}

onMounted(async () => {
  await loadCamps()
  await load()
})
</script>
