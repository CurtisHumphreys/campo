<template>
  <div class="p-6 max-w-5xl mx-auto space-y-5">

    <div class="flex items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold text-ink-100">Site Allocation</h1>
        <p class="text-sm text-ink-500 mt-0.5">Assign households to sites</p>
      </div>
    </div>

    <LoadingSpinner v-if="loading" :full="true" />

    <template v-else>
      <!-- Stats -->
      <div class="grid grid-cols-3 gap-3">
        <div class="card p-4 text-center">
          <div class="text-2xl font-bold text-ink-100">{{ allocations.length }}</div>
          <div class="text-xs text-ink-500 mt-1">Total Sites</div>
        </div>
        <div class="card p-4 text-center">
          <div class="text-2xl font-bold text-ember-400">{{ assigned }}</div>
          <div class="text-xs text-ink-500 mt-1">Assigned</div>
        </div>
        <div class="card p-4 text-center">
          <div class="text-2xl font-bold text-ink-400">{{ allocations.length - assigned }}</div>
          <div class="text-xs text-ink-500 mt-1">Vacant</div>
        </div>
      </div>

      <!-- Unassigned households panel -->
      <div v-if="unassignedHouseholds.length" class="card p-4 border border-amber-500/20 bg-amber-500/5">
        <div class="text-sm font-medium text-amber-400 mb-2">
          {{ unassignedHouseholds.length }} household{{ unassignedHouseholds.length !== 1 ? 's' : '' }} without a site
        </div>
        <div class="text-xs text-ink-500 flex flex-wrap gap-2">
          <button
            v-for="h in unassignedHouseholds" :key="h.id"
            @click="openAssignFromHousehold(h)"
            class="bg-surface-700 hover:bg-surface-600 hover:text-ink-100 px-2 py-0.5 rounded-lg transition-colors text-left">
            {{ h.name }} ({{ h.member_count }})
          </button>
        </div>
      </div>

      <EmptyState v-if="!allocations.length" icon="🏠" title="No sites configured"
        subtitle="Add sites in the Sites module first." />

      <template v-else>
        <!-- Search + filter bar -->
        <div class="flex flex-wrap gap-2 items-center">
          <input
            v-model="searchQuery"
            type="search"
            placeholder="Search site or household…"
            class="flex-1 min-w-[180px] text-sm" />
          <div class="flex gap-1">
            <button
              v-for="f in statusFilters" :key="f.value"
              @click="statusFilter = f.value"
              :class="['btn btn-sm', statusFilter === f.value ? 'btn-primary' : 'btn-ghost text-ink-400']">
              {{ f.label }}
              <span class="ml-1 text-xs opacity-70">{{ f.count }}</span>
            </button>
          </div>
        </div>

        <!-- Site grid -->
        <div v-if="filteredAllocations.length" class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
          <div v-for="a in filteredAllocations" :key="a.site_id"
            class="card p-4 cursor-pointer transition-colors"
            :class="a.household_id ? 'border border-ember-500/20 hover:bg-surface-700' : 'hover:bg-surface-700'"
            @click="openAssignFromSite(a)">
            <div class="flex items-start justify-between gap-2 mb-2">
              <div class="font-bold text-ink-100 text-lg leading-none">{{ a.site_number }}</div>
              <span :class="typeBadge(a.site_type)">{{ a.site_type || 'General' }}</span>
            </div>
            <div v-if="a.household_id" class="space-y-1">
              <div class="text-sm font-medium text-ember-300 truncate">{{ a.household_name }}</div>
              <div class="text-xs text-ink-500">
                {{ a.member_count }} member{{ a.member_count !== 1 ? 's' : '' }}
                <span v-if="a.allocation_notes" class="ml-2 text-ink-600">· {{ a.allocation_notes }}</span>
              </div>
            </div>
            <div v-else class="text-sm text-ink-500 italic">Unassigned</div>
            <div class="flex items-center justify-between mt-2">
              <div class="flex items-center gap-2 text-xs text-ink-600">
                <span>👥 {{ a.capacity }}</span>
                <span v-if="a.power" class="text-amber-500">⚡</span>
              </div>
              <span v-if="a.household_id" :class="feeStatusClass(a.site_fee_expires)" class="text-xs font-medium px-1.5 py-0.5 rounded">
                {{ feeStatusLabel(a.site_fee_expires) }}
              </span>
            </div>
          </div>
        </div>
        <EmptyState v-else icon="🔍" title="No matches" subtitle="Try a different search or filter." />
      </template>
    </template>

    <!-- Site modal -->
    <AppModal v-model="showModal" :title="modalTitle" width="sm:max-w-2xl">
      <div class="space-y-5">

        <!-- Assignment section -->
        <div class="space-y-4">
          <!-- Site-first mode -->
          <template v-if="modalMode === 'site'">
            <div class="flex items-center gap-2 flex-wrap">
              <span :class="typeBadge(form.site_type)">{{ form.site_type || 'General' }}</span>
              <span class="text-xs text-ink-500">👥 {{ form.capacity }}</span>
              <span v-if="form.power" class="text-xs text-amber-400">⚡ Power</span>
              <span v-if="form.household_id" :class="feeStatusClass(form.site_fee_expires)" class="text-xs font-medium px-1.5 py-0.5 rounded">
                {{ feeStatusLabel(form.site_fee_expires) }}
              </span>
            </div>
            <div>
              <label class="field-label">Household</label>
              <select v-model="form.household_id" class="w-full" @change="onHouseholdChange">
                <option :value="null">— Unassigned —</option>
                <option v-for="h in availableHouseholdsForSite" :key="h.id" :value="h.id">
                  {{ h.name }} ({{ h.member_count }} member{{ h.member_count !== 1 ? 's' : '' }})
                </option>
              </select>
            </div>
          </template>

          <!-- Household-first mode -->
          <template v-else>
            <div class="text-sm text-ink-300">
              Assign <span class="font-semibold text-ink-100">{{ form.household_name }}</span> to a site
            </div>
            <div>
              <label class="field-label">Site</label>
              <select v-model="form.site_id" class="w-full">
                <option :value="null">— Select a site —</option>
                <option v-for="s in vacantSites" :key="s.site_id" :value="s.site_id">
                  {{ s.site_number }} — {{ s.site_type || 'General' }}{{ s.power ? ' ⚡' : '' }} (cap {{ s.capacity }})
                </option>
              </select>
            </div>
          </template>

          <div>
            <label class="field-label">Notes</label>
            <input v-model="form.allocation_notes" type="text" placeholder="Optional notes for this assignment" />
          </div>
        </div>

        <!-- Payment history -->
        <template v-if="form.household_id">
          <div class="border-t border-surface-600 pt-4">
            <div class="text-sm font-medium text-ink-300 mb-3">Payment History</div>

            <div v-if="historyLoading" class="text-sm text-ink-500 py-2">Loading…</div>

            <div v-else-if="!paymentHistory.length" class="text-sm text-ink-600 italic">
              No payments recorded for this household.
            </div>

            <template v-else>
              <!-- Date range filter -->
              <div class="flex gap-2 items-center mb-3">
                <input v-model="histDateFrom" type="date" class="text-xs py-1 px-2 flex-1 min-w-0" />
                <span class="text-ink-600 text-xs flex-none">→</span>
                <input v-model="histDateTo" type="date" class="text-xs py-1 px-2 flex-1 min-w-0" />
                <button v-if="histDateFrom || histDateTo" @click="histDateFrom = ''; histDateTo = ''"
                  class="text-xs text-ink-500 hover:text-ink-300 flex-none px-1">✕</button>
              </div>

              <div v-if="!filteredHistory.length" class="text-sm text-ink-600 italic py-1">
                No payments in this date range.
              </div>

              <div v-else class="relative">
                <div class="space-y-2 max-h-72 overflow-y-auto pr-1">
                  <div v-for="p in filteredHistory" :key="p.id"
                    class="bg-surface-800 rounded-lg p-3 text-xs space-y-1.5">
                    <!-- Camp + date -->
                    <div class="flex items-center justify-between gap-2">
                      <span class="font-semibold text-ink-200">{{ p.camp_name }}</span>
                      <span class="text-ink-500">{{ fmtDate(p.payment_date) }}</span>
                    </div>
                    <!-- Fee breakdown -->
                    <div class="flex flex-wrap gap-x-4 gap-y-0.5 text-ink-400">
                      <span v-if="p.camp_fee">Camp fee: <span class="text-ink-200">${{ p.camp_fee.toFixed(2) }}</span></span>
                      <span v-if="p.site_fee">Site fee: <span class="text-ink-200">${{ p.site_fee.toFixed(2) }}</span></span>
                      <span v-if="p.other_amount">Other: <span class="text-ink-200">${{ p.other_amount.toFixed(2) }}</span></span>
                      <span v-if="p.prepaid_applied">Prepaid: <span class="text-emerald-400">−${{ p.prepaid_applied.toFixed(2) }}</span></span>
                      <span v-if="p.headcount">{{ p.headcount }} pax</span>
                    </div>
                    <!-- Tenders + total -->
                    <div class="flex items-center justify-between gap-2">
                      <div class="flex gap-2 text-ink-500">
                        <span v-if="p.tender_eftpos">EFTPOS ${{ p.tender_eftpos.toFixed(2) }}</span>
                        <span v-if="p.tender_cash">Cash ${{ p.tender_cash.toFixed(2) }}</span>
                        <span v-if="p.tender_bank">Bank ${{ p.tender_bank.toFixed(2) }}</span>
                      </div>
                      <span class="font-semibold text-ink-100">Total ${{ p.total.toFixed(2) }}</span>
                    </div>
                    <div v-if="p.notes" class="text-ink-600 italic">{{ p.notes }}</div>
                  </div>
                </div>
                <div class="absolute bottom-0 left-0 right-0 h-8 bg-gradient-to-t from-surface-800 to-transparent pointer-events-none"></div>
              </div>
            </template>
          </div>
        </template>

      </div>
      <template #footer>
        <button v-if="modalMode === 'site' && form.allocation_id" @click="doUnassign"
          class="btn btn-ghost text-red-400 hover:text-red-300 mr-auto">Unassign</button>
        <button @click="showModal = false" class="btn btn-ghost">Cancel</button>
        <button @click="save" :disabled="saving" class="btn btn-primary">
          {{ saving ? 'Saving…' : 'Save' }}
        </button>
      </template>
    </AppModal>

  </div>
</template>

<script setup>
import { ref, computed, inject, onMounted, watch } from 'vue'
import { api } from '@/api.js'
import AppModal from '@/components/AppModal.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import EmptyState from '@/components/EmptyState.vue'

const toast = inject('toast')

const loading              = ref(true)
const saving               = ref(false)
const showModal            = ref(false)
const modalMode            = ref('site')
const allocations          = ref([])
const unassignedHouseholds = ref([])
const searchQuery          = ref('')
const statusFilter         = ref('all')
const paymentHistory       = ref([])
const historyLoading       = ref(false)
const histDateFrom         = ref('')
const histDateTo           = ref('')

const form = ref({
  site_id: null, site_number: '', site_type: '', power: false, capacity: 0,
  allocation_id: null, household_id: null, household_name: '', allocation_notes: '',
  site_fee_expires: null
})

const assigned = computed(() => allocations.value.filter(a => a.household_id).length)
const vacantSites = computed(() => allocations.value.filter(a => !a.household_id))

const statusFilters = computed(() => [
  { value: 'all',      label: 'All',      count: allocations.value.length },
  { value: 'assigned', label: 'Assigned', count: assigned.value },
  { value: 'vacant',   label: 'Vacant',   count: allocations.value.length - assigned.value },
])

const filteredAllocations = computed(() => {
  let list = allocations.value
  if (statusFilter.value === 'assigned') list = list.filter(a => a.household_id)
  if (statusFilter.value === 'vacant')   list = list.filter(a => !a.household_id)
  const q = searchQuery.value.trim().toLowerCase()
  if (q) list = list.filter(a =>
    a.site_number?.toString().toLowerCase().includes(q) ||
    a.household_name?.toLowerCase().includes(q)
  )
  return list
})

const modalTitle = computed(() =>
  modalMode.value === 'site' ? `Site ${form.value.site_number}` : `Assign Household`
)

const availableHouseholdsForSite = computed(() => {
  const currentId = form.value.household_id
  return [
    ...unassignedHouseholds.value,
    ...(currentId ? allocations.value
      .filter(a => a.household_id === currentId)
      .map(a => ({ id: a.household_id, name: a.household_name, member_count: a.member_count })) : [])
  ]
})

const filteredHistory = computed(() => {
  let list = paymentHistory.value
  if (histDateFrom.value) list = list.filter(p => (p.payment_date ?? '') >= histDateFrom.value)
  if (histDateTo.value)   list = list.filter(p => (p.payment_date ?? '') <= histDateTo.value)
  return list
})

async function loadHistory(householdId) {
  if (!householdId) { paymentHistory.value = []; return }
  historyLoading.value = true
  try {
    const res = await api.households.paymentHistory(householdId)
    paymentHistory.value = res.payments ?? []
  } catch {
    paymentHistory.value = []
  } finally {
    historyLoading.value = false
  }
}

async function load() {
  loading.value = true
  try {
    const res = await api.siteAllocations.list()
    allocations.value          = res.allocations          ?? []
    unassignedHouseholds.value = res.unassigned_households ?? []
  } catch {}
  loading.value = false
}

function openAssignFromSite(a) {
  modalMode.value = 'site'
  form.value = {
    site_id:          a.site_id,
    site_number:      a.site_number,
    site_type:        a.site_type,
    power:            a.power,
    capacity:         a.capacity,
    allocation_id:    a.allocation_id,
    household_id:     a.household_id,
    household_name:   a.household_name ?? '',
    allocation_notes: a.allocation_notes ?? '',
    site_fee_expires: a.site_fee_expires ?? null,
  }
  paymentHistory.value = []
  histDateFrom.value = ''
  histDateTo.value   = ''
  showModal.value = true
  if (a.household_id) loadHistory(a.household_id)
}

function openAssignFromHousehold(h) {
  modalMode.value = 'household'
  form.value = {
    site_id:          null,
    site_number:      '',
    site_type:        '',
    power:            false,
    capacity:         0,
    allocation_id:    null,
    household_id:     h.id,
    household_name:   h.name,
    allocation_notes: '',
  }
  paymentHistory.value = []
  histDateFrom.value = ''
  histDateTo.value   = ''
  showModal.value = true
  loadHistory(h.id)
}

function onHouseholdChange() {
  loadHistory(form.value.household_id)
}

async function save() {
  saving.value = true
  try {
    if (modalMode.value === 'household') {
      if (!form.value.site_id) {
        toast?.add('Please select a site', 'error')
        saving.value = false
        return
      }
      await api.siteAllocations.create({
        site_id:      form.value.site_id,
        household_id: form.value.household_id,
        notes:        form.value.allocation_notes,
      })
      toast?.add('Household assigned', 'success')
    } else if (form.value.allocation_id && form.value.household_id) {
      await api.siteAllocations.update(form.value.allocation_id, {
        household_id: form.value.household_id,
        notes:        form.value.allocation_notes,
      })
      toast?.add('Allocation updated', 'success')
    } else if (!form.value.allocation_id && form.value.household_id) {
      await api.siteAllocations.create({
        site_id:      form.value.site_id,
        household_id: form.value.household_id,
        notes:        form.value.allocation_notes,
      })
      toast?.add('Household assigned', 'success')
    } else if (form.value.allocation_id && !form.value.household_id) {
      await api.siteAllocations.delete(form.value.allocation_id)
      toast?.add('Site unassigned', 'success')
    }
    showModal.value = false
    await load()
  } catch (e) {
    toast?.add(e?.data?.message || 'Save failed', 'error')
  } finally {
    saving.value = false
  }
}

async function doUnassign() {
  if (!confirm(`Remove this allocation?`)) return
  saving.value = true
  try {
    await api.siteAllocations.delete(form.value.allocation_id)
    toast?.add('Site unassigned', 'success')
    showModal.value = false
    await load()
  } catch {
    toast?.add('Failed to unassign', 'error')
  } finally {
    saving.value = false
  }
}

function fmtDate(d) {
  if (!d) return ''
  return new Date(d).toLocaleString('en-AU', {
    day: 'numeric', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit'
  })
}

function feeStatusLabel(expires) {
  if (!expires) return 'No date'
  const today = new Date(); today.setHours(0,0,0,0)
  const exp = new Date(expires); exp.setHours(0,0,0,0)
  const days = Math.round((exp - today) / 86400000)
  if (days < 0)  return `Overdue ${Math.abs(days)}d`
  if (days === 0) return 'Due today'
  return `Paid · ${days}d`
}

function feeStatusClass(expires) {
  if (!expires) return 'bg-surface-700 text-ink-500'
  const today = new Date(); today.setHours(0,0,0,0)
  const exp = new Date(expires); exp.setHours(0,0,0,0)
  const days = Math.round((exp - today) / 86400000)
  if (days < 0)   return 'bg-red-500/20 text-red-400'
  if (days <= 30) return 'bg-amber-500/20 text-amber-400'
  return 'bg-emerald-500/20 text-emerald-400'
}

function typeBadge(type) {
  return {
    caravan: 'badge bg-sky-500/20 text-sky-400',
    tent:    'badge bg-emerald-500/20 text-emerald-400',
    cabin:   'badge bg-amber-500/20 text-amber-400',
    powered: 'badge bg-purple-500/20 text-purple-400',
  }[type] || 'badge bg-surface-600 text-ink-400'
}

onMounted(load)
</script>
