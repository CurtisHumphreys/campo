<template>
  <div class="p-6 max-w-4xl mx-auto space-y-5">

    <div class="flex items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold text-ink-100">Prepayments</h1>
        <p class="text-sm text-ink-500 mt-0.5">Payments received before camp</p>
      </div>
      <div class="flex gap-2">
        <button v-if="summary.unmatched > 0 && !matchReview" @click="openMatchReview"
          class="btn btn-sm btn-secondary">
          Review {{ summary.unmatched }} Unmatched
        </button>
        <button v-if="matchReview" @click="tryBack" class="btn btn-sm btn-ghost text-ink-400">
          ← Back
        </button>
        <button v-if="!matchReview" @click="openNew" class="btn btn-primary btn-sm" :disabled="!selectedCampId">+ Add</button>
      </div>
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
      <div v-if="summary.total" class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div class="card p-4 text-center">
          <div class="text-2xl font-bold text-ink-100">{{ summary.total }}</div>
          <div class="text-xs text-ink-500 mt-1">Prepayments</div>
        </div>
        <div class="card p-4 text-center">
          <div class="text-2xl font-bold text-emerald-400">${{ summary.total_amount?.toFixed(2) }}</div>
          <div class="text-xs text-ink-500 mt-1">Total</div>
        </div>
        <div class="card p-4 text-center">
          <div class="text-2xl font-bold text-ink-100">{{ summary.matched }}</div>
          <div class="text-xs text-ink-500 mt-1">Matched</div>
        </div>
        <div class="card p-4 text-center">
          <div class="text-2xl font-bold text-amber-400">{{ summary.unmatched }}</div>
          <div class="text-xs text-ink-500 mt-1">Unmatched</div>
        </div>
      </div>

      <!-- Match Review panel -->
      <template v-if="matchReview">
        <div class="flex items-center gap-3">
          <span class="text-sm text-ink-400">{{ reviewItems.length }} unmatched remaining</span>
          <LoadingSpinner v-if="reviewLoading" class="w-4 h-4" />
        </div>
        <EmptyState v-if="!reviewLoading && !reviewItems.length" icon="✅" title="All matched"
          subtitle="Every prepayment is matched to a household." />
        <div v-else class="space-y-3">
          <div v-for="item in reviewItems" :key="item.id" class="card p-4 space-y-3">
            <div class="flex items-start justify-between gap-3">
              <div>
                <div class="font-medium text-ink-200">{{ item.name }}</div>
                <div class="text-xs text-ink-500 flex gap-3 mt-0.5 flex-wrap">
                  <span v-if="item.paid_at">{{ formatDate(item.paid_at) }}</span>
                  <span :class="methodBadge(item.method)">{{ methodLabel(item.method) }}</span>
                  <span v-if="item.reference">Ref: {{ item.reference }}</span>
                </div>
              </div>
              <div class="text-right flex-none">
                <div class="font-bold text-ink-100">${{ item.amount.toFixed(2) }}</div>
              </div>
            </div>

            <!-- Suggestions -->
            <div v-if="item.suggestions.length" class="flex flex-wrap gap-2">
              <button v-for="s in item.suggestions" :key="s.id"
                @click="applyMatch(item, s.id)"
                class="btn btn-sm btn-secondary text-xs">
                {{ s.name }}
                <span class="text-ink-500 ml-1">{{ s.score }}%</span>
              </button>
            </div>
            <div v-else class="text-xs text-ink-500 italic">No close matches found</div>

            <!-- Actions -->
            <div class="flex items-center gap-2 pt-1 border-t border-surface-700">
              <select class="text-xs flex-1" @change="e => { applyMatch(item, +e.target.value || null); e.target.value = '' }">
                <option value="">Other household…</option>
                <option v-for="h in households" :key="h.id" :value="h.id">{{ h.name }}</option>
              </select>
              <button @click="openEdit(item); matchReview = false" class="btn btn-ghost btn-sm text-xs text-ink-400">Edit</button>
              <button @click="dismissReviewItem(item.id)" class="btn btn-ghost btn-sm text-xs text-ink-500">Skip</button>
            </div>
          </div>
        </div>
      </template>

      <!-- Normal list -->
      <template v-else>
        <!-- Filter -->
        <div class="flex gap-2">
          <button v-for="f in filters" :key="f.value"
            @click="activeFilter = f.value; load()"
            class="btn btn-sm"
            :class="activeFilter === f.value ? 'btn-primary' : 'btn-secondary'">
            {{ f.label }}
          </button>
        </div>

        <EmptyState v-if="!prepayments.length" icon="💰" title="No prepayments"
          :subtitle="activeFilter ? 'None matching this filter.' : 'Add the first prepayment for this camp.'" />

        <!-- List -->
        <div v-else class="space-y-2">
          <div v-for="p in prepayments" :key="p.id"
            class="card p-4 flex items-center gap-4 cursor-pointer hover:bg-surface-700 transition-colors"
            @click="openEdit(p)">
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2 flex-wrap">
                <span class="font-medium text-ink-200">{{ p.household_name || p.name }}</span>
                <span v-if="p.household_name && p.household_name !== p.name"
                  class="text-xs text-ink-500">({{ p.name }})</span>
                <span :class="p.household_id ? 'badge bg-emerald-500/15 text-emerald-400' : 'badge bg-amber-500/15 text-amber-400'">
                  {{ p.household_id ? 'Matched' : 'Unmatched' }}
                </span>
                <span v-if="p.source !== 'manual'" class="badge bg-surface-600 text-ink-500 text-xs">
                  {{ p.source }}
                </span>
              </div>
              <div class="text-xs text-ink-500 flex gap-3 mt-0.5 flex-wrap">
                <span v-if="p.paid_at">{{ formatDate(p.paid_at) }}</span>
                <span :class="methodBadge(p.method)">{{ methodLabel(p.method) }}</span>
                <span v-if="p.reference">Ref: {{ p.reference }}</span>
              </div>
            </div>
            <div class="text-right flex-none">
              <div class="font-bold text-ink-100 text-lg">${{ parseFloat(p.amount).toFixed(2) }}</div>
            </div>
          </div>
        </div>
      </template>
    </template>

    <EmptyState v-else icon="🏕️" title="No camps found" subtitle="Create a camp first." />

    <!-- Prepayment modal -->
    <AppModal v-model="showModal" :title="editing ? 'Edit Prepayment' : 'Add Prepayment'">
      <form @submit.prevent="save" class="space-y-4">
        <div>
          <label class="field-label">Name *</label>
          <input v-model="form.name" type="text" required placeholder="Name as it appeared on payment" />
        </div>
        <div>
          <label class="field-label">Match to Household</label>
          <select v-model="form.household_id" class="w-full">
            <option value="">— Unmatched —</option>
            <option v-for="h in households" :key="h.id" :value="h.id">{{ h.name }}</option>
          </select>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="field-label">Amount *</label>
            <div class="relative">
              <span class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-500 text-sm">$</span>
              <input v-model="form.amount" type="number" step="0.01" min="0" required class="pl-7" />
            </div>
          </div>
          <div>
            <label class="field-label">Method</label>
            <select v-model="form.method">
              <option value="bank_transfer">Bank Transfer</option>
              <option value="cash">Cash</option>
              <option value="card">Card</option>
              <option value="other">Other</option>
            </select>
          </div>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="field-label">Date Paid</label>
            <input v-model="form.paid_at" type="date" />
          </div>
          <div>
            <label class="field-label">Reference</label>
            <input v-model="form.reference" type="text" placeholder="Bank ref / receipt no." />
          </div>
        </div>
        <div>
          <label class="field-label">Notes</label>
          <textarea v-model="form.notes" rows="2" class="resize-none" />
        </div>
      </form>
      <template #footer>
        <button v-if="editing" @click="doDelete"
          class="btn btn-ghost text-red-400 hover:text-red-300 mr-auto">Delete</button>
        <button @click="showModal = false" class="btn btn-ghost">Cancel</button>
        <button @click="save" :disabled="saving" class="btn btn-primary">
          {{ saving ? 'Saving…' : 'Save' }}
        </button>
      </template>
    </AppModal>

  </div>
</template>

<script setup>
import { ref, inject, onMounted } from 'vue'
import { api } from '@/api.js'
import AppModal from '@/components/AppModal.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import EmptyState from '@/components/EmptyState.vue'

const toast = inject('toast')

const loading        = ref(true)
const saving         = ref(false)
const showModal      = ref(false)
const editing        = ref(null)
const camps          = ref([])
const prepayments    = ref([])
const households     = ref([])
const summary        = ref({})
const selectedCampId = ref(null)
const activeFilter   = ref('')
const matchReview    = ref(false)
const reviewItems    = ref([])
const reviewLoading  = ref(false)

const filters = [
  { value: '',           label: 'All' },
  { value: 'matched',   label: 'Matched' },
  { value: 'unmatched', label: 'Unmatched' },
]

const blank = () => ({
  name: '', household_id: '', amount: '', method: 'bank_transfer',
  reference: '', paid_at: '', notes: ''
})
const form = ref(blank())

async function loadCamps() {
  try {
    const res = await api.camps.list()
    camps.value = res
    const active = res.find(c => c.status === 'active')
    selectedCampId.value = active?.id ?? res[0]?.id ?? null
  } catch {}
}

async function loadHouseholds() {
  try { households.value = await api.households.list() } catch {}
}

async function load() {
  if (!selectedCampId.value) { loading.value = false; return }
  loading.value = true
  try {
    const res = await api.prepayments.list({ camp_id: selectedCampId.value, filter: activeFilter.value })
    prepayments.value = res.prepayments ?? []
    summary.value     = res.summary     ?? {}
  } catch {}
  loading.value = false
}

async function openMatchReview() {
  matchReview.value = true
  reviewLoading.value = true
  try {
    const res = await api.prepayments.review(selectedCampId.value)
    reviewItems.value = res.items ?? []
  } catch { toast?.add('Failed to load review', 'error') }
  finally { reviewLoading.value = false }
}

async function applyMatch(item, householdId) {
  try {
    await api.prepayments.match(item.id, householdId)
    reviewItems.value = reviewItems.value.filter(r => r.id !== item.id)
    summary.value = { ...summary.value, unmatched: Math.max(0, (summary.value.unmatched || 1) - 1), matched: (summary.value.matched || 0) + 1 }
    toast?.add('Matched', 'success')
  } catch { toast?.add('Match failed', 'error') }
}

function tryBack() {
  if (reviewItems.value.length > 0 && !confirm(`Leave review? ${reviewItems.value.length} unmatched prepayment${reviewItems.value.length !== 1 ? 's' : ''} will remain unmatched.`)) return
  matchReview.value = false
}

function dismissReviewItem(id) {
  reviewItems.value = reviewItems.value.filter(r => r.id !== id)
}

function openNew() {
  editing.value = null
  form.value = blank()
  showModal.value = true
}

function openEdit(p) {
  editing.value = p
  form.value = { ...blank(), ...p, household_id: p.household_id ?? '' }
  showModal.value = true
}

async function save() {
  saving.value = true
  try {
    const payload = {
      ...form.value,
      camp_id:      selectedCampId.value,
      household_id: form.value.household_id || null,
      paid_at:      form.value.paid_at || null,
    }
    if (editing.value) {
      await api.prepayments.update(editing.value.id, payload)
      toast?.add('Prepayment updated', 'success')
    } else {
      await api.prepayments.create(payload)
      toast?.add('Prepayment added', 'success')
    }
    showModal.value = false
    await load()
  } catch (e) {
    toast?.add(e?.data?.message || 'Save failed', 'error')
  } finally {
    saving.value = false
  }
}

async function doDelete() {
  if (!confirm('Delete this prepayment?')) return
  try {
    await api.prepayments.delete(editing.value.id)
    toast?.add('Deleted', 'success')
    showModal.value = false
    await load()
  } catch {
    toast?.add('Delete failed', 'error')
  }
}

function formatDate(d) {
  if (!d) return ''
  const [y, m, day] = d.split('-')
  return `${parseInt(day)} ${['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][parseInt(m)-1]} ${y}`
}

function methodLabel(m) {
  return { bank_transfer: 'Bank Transfer', cash: 'Cash', card: 'Card', other: 'Other' }[m] || m
}

function methodBadge(m) {
  return { bank_transfer: 'text-sky-400', cash: 'text-emerald-400', card: 'text-purple-400' }[m] || 'text-ink-400'
}

onMounted(async () => {
  await Promise.all([loadCamps(), loadHouseholds()])
  await load()
})
</script>
