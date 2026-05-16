<template>
  <div class="p-6 space-y-6">

    <div class="flex items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold text-ink-100">Rates</h1>
        <p class="text-sm text-ink-500 mt-0.5">Camp fee schedule</p>
      </div>
    </div>

    <!-- Camp + Sheet selectors -->
    <div class="card p-4 flex flex-wrap items-center gap-4">
      <div class="flex items-center gap-3 flex-1 min-w-48">
        <label class="text-sm text-ink-400 flex-none">Camp</label>
        <select v-model="selectedCampId" @change="onCampChange" class="flex-1 text-sm">
          <option v-for="c in camps" :key="c.id" :value="c.id">
            {{ c.name }}{{ c.status === 'active' ? ' (active)' : '' }}
          </option>
        </select>
      </div>
      <div v-if="sheets.length > 1" class="flex items-center gap-2">
        <label class="text-sm text-ink-400 flex-none">Sheet</label>
        <div class="flex gap-1.5">
          <button v-for="sh in sheets" :key="sh"
            @click="selectedSheet = sh; load()"
            :class="selectedSheet === sh ? 'btn btn-primary btn-sm' : 'btn btn-secondary btn-sm'">
            {{ sh }}
          </button>
        </div>
      </div>
      <button @click="openAddRate" class="btn btn-ghost btn-sm ml-auto" :disabled="!selectedCampId">
        + Custom Rate
      </button>
    </div>

    <LoadingSpinner v-if="loading" :full="true" />

    <template v-else-if="rateGrid.size">

      <!-- Main rates grid -->
      <div class="card overflow-x-auto">
        <table class="w-full text-sm border-collapse">
          <thead>
            <tr class="border-b border-surface-700">
              <th class="text-left p-3 font-semibold text-ink-300 w-36 bg-surface-800 sticky left-0 z-10">
                Site Type
              </th>
              <th v-for="gt in MAIN_GUEST_TYPES" :key="gt.key"
                class="text-center p-3 font-semibold text-ink-400 text-xs whitespace-nowrap min-w-24">
                {{ gt.label }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="st in SITE_TYPES" :key="st"
              class="border-b border-surface-700/50 hover:bg-surface-700/20 transition-colors">
              <td class="p-3 font-semibold text-ink-200 bg-surface-800/50 sticky left-0">{{ st }}</td>
              <td v-for="gt in MAIN_GUEST_TYPES" :key="gt.key" class="p-1.5 text-center">
                <RateCell
                  :rate="getRate(st, gt.key)"
                  @save="(val) => saveCell(st, gt.key, gt.memberType, gt.period, val)"
                />
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Site Fee section -->
      <div class="card p-5">
        <h3 class="text-sm font-semibold text-ink-300 mb-3">Site Fee (Annual)</h3>
        <div class="flex gap-6">
          <div v-for="gt in SITE_FEE_TYPES" :key="gt.key" class="flex items-center gap-3">
            <span class="text-sm text-ink-400 w-28">{{ gt.label }}</span>
            <RateCell
              :rate="getRate('Site Fee', gt.key)"
              @save="(val) => saveCell('Site Fee', gt.key, 'adult', 'full', val)"
            />
          </div>
        </div>
      </div>

    </template>

    <EmptyState v-else-if="!loading" icon="🏷️" title="No rates for this camp"
      subtitle="Rates will appear here once added." />

    <!-- Custom rate modal (for non-grid rates) -->
    <AppModal v-model="showModal" title="Add Custom Rate">
      <form @submit.prevent="saveCustom" class="space-y-4">
        <div>
          <label class="field-label">Rate Sheet</label>
          <input v-model="customForm.sheet" type="text" list="sheet-opts" placeholder="Standard" />
          <datalist id="sheet-opts">
            <option v-for="sh in sheets" :key="sh" :value="sh" />
          </datalist>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="field-label">Site Type</label>
            <input v-model="customForm.site_type" type="text" placeholder="e.g. Unpowered Site" />
          </div>
          <div>
            <label class="field-label">Guest Type</label>
            <input v-model="customForm.guest_type" type="text" placeholder="e.g. Adult Single" />
          </div>
        </div>
        <div>
          <label class="field-label">Label</label>
          <input v-model="customForm.label" type="text" placeholder="e.g. Unpowered — Adult Single" />
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="field-label">Member Type</label>
            <select v-model="customForm.member_type">
              <option value="adult">Adult</option>
              <option value="youth">Youth</option>
              <option value="child">Child</option>
              <option value="infant">Infant</option>
            </select>
          </div>
          <div>
            <label class="field-label">Period</label>
            <select v-model="customForm.period">
              <option value="full">Full Camp</option>
              <option value="on_peak">On Peak</option>
              <option value="off_peak">Off Peak</option>
            </select>
          </div>
        </div>
        <div>
          <label class="field-label">Amount *</label>
          <div class="relative">
            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-500 text-sm">$</span>
            <input v-model="customForm.amount" type="number" step="0.01" min="0" required class="pl-7" />
          </div>
        </div>
      </form>
      <template #footer>
        <button @click="showModal = false" class="btn btn-ghost">Cancel</button>
        <button @click="saveCustom" :disabled="saving" class="btn btn-primary">
          {{ saving ? 'Saving…' : 'Save' }}
        </button>
      </template>
    </AppModal>

  </div>
</template>

<script setup>
import { ref, computed, inject, onMounted, defineComponent, h } from 'vue'
import { api } from '@/api.js'
import AppModal from '@/components/AppModal.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import EmptyState from '@/components/EmptyState.vue'

const toast = inject('toast')

// ── Grid definitions ──────────────────────────────────────────────────────────
const SITE_TYPES = ['Unpowered Site', 'Powered Site', 'Dorms (KFC)', 'Family Room', 'Special Use', 'Day Trip']

const MAIN_GUEST_TYPES = [
  { key: 'Adult Single',      label: 'Adult Single (+14yrs)', memberType: 'adult', period: 'full' },
  { key: 'Adult Couple',      label: 'Adult Couple',          memberType: 'adult', period: 'full' },
  { key: 'Concession Single', label: 'Concession Single',     memberType: 'adult', period: 'full' },
  { key: 'Concession Couple', label: 'Concession Couple',     memberType: 'adult', period: 'full' },
  { key: 'Child',             label: 'Child (5–13)',          memberType: 'child', period: 'full' },
  { key: 'Family Cap',        label: 'Family Cap',            memberType: 'adult', period: 'full' },
  { key: 'Offpeak',           label: 'Offpeak',               memberType: 'adult', period: 'off_peak' },
  { key: 'Offpeak Concession',label: 'Offpeak Concession',    memberType: 'adult', period: 'off_peak' },
]

const SITE_FEE_TYPES = [
  { key: 'Standard',   label: 'Standard',   memberType: 'adult', period: 'full' },
  { key: 'Concession', label: 'Concession', memberType: 'adult', period: 'full' },
]

// ── State ─────────────────────────────────────────────────────────────────────
const loading        = ref(true)
const saving         = ref(false)
const showModal      = ref(false)
const camps          = ref([])
const rates          = ref([])
const sheets         = ref([])
const selectedCampId = ref(null)
const selectedSheet  = ref('Standard')

// Map keyed by "site_type|guest_type" → rate row
const rateGrid = computed(() => {
  const m = new Map()
  rates.value.forEach(r => m.set(`${r.site_type}|${r.guest_type}`, r))
  return m
})

function getRate(siteType, guestType) {
  return rateGrid.value.get(`${siteType}|${guestType}`) ?? null
}

// ── Load ──────────────────────────────────────────────────────────────────────
async function loadCamps() {
  try {
    const res = await api.camps.list()
    camps.value = res
    const active = res.find(c => c.status === 'active')
    selectedCampId.value = active?.id ?? res[0]?.id ?? null
  } catch {}
}

async function load() {
  if (!selectedCampId.value) { loading.value = false; return }
  loading.value = true
  try {
    const res = await api.rates.list(selectedCampId.value, selectedSheet.value !== '__all' ? selectedSheet.value : undefined)
    rates.value  = res.rates  ?? []
    sheets.value = res.sheets ?? []
    if (!sheets.value.includes(selectedSheet.value)) {
      selectedSheet.value = sheets.value[0] ?? 'Standard'
    }
  } catch {}
  loading.value = false
}

function onCampChange() { load() }

// ── Cell save ─────────────────────────────────────────────────────────────────
async function saveCell(siteType, guestType, memberType, period, amount) {
  const existing = getRate(siteType, guestType)
  const label    = `${siteType} — ${guestType}`
  try {
    if (existing) {
      await api.rates.update(existing.id, {
        sheet: selectedSheet.value, site_type: siteType, guest_type: guestType,
        label, member_type: memberType, period, amount
      })
    } else {
      await api.rates.create({
        camp_id: selectedCampId.value, sheet: selectedSheet.value,
        site_type: siteType, guest_type: guestType,
        label, member_type: memberType, period, amount
      })
    }
    await load()
  } catch {
    toast?.add('Save failed', 'error')
  }
}

// ── Custom rate modal ─────────────────────────────────────────────────────────
const customForm = ref({ sheet: 'Standard', site_type: '', guest_type: '', label: '', member_type: 'adult', period: 'full', amount: '' })

function openAddRate() {
  customForm.value = { sheet: selectedSheet.value, site_type: '', guest_type: '', label: '', member_type: 'adult', period: 'full', amount: '' }
  showModal.value  = true
}

async function saveCustom() {
  saving.value = true
  try {
    await api.rates.create({ ...customForm.value, camp_id: selectedCampId.value })
    toast?.add('Rate added', 'success')
    showModal.value = false
    await load()
  } catch (e) {
    toast?.add(e?.data?.message || 'Save failed', 'error')
  } finally {
    saving.value = false
  }
}

onMounted(async () => { await loadCamps(); await load() })
</script>

<!-- Inline editable rate cell -->
<script>
import { defineComponent, ref, h } from 'vue'
export const RateCell = defineComponent({
  name: 'RateCell',
  props: { rate: { default: null } },
  emits: ['save'],
  setup(props, { emit }) {
    const editing  = ref(false)
    const inputVal = ref('')

    function startEdit() {
      inputVal.value = props.rate ? parseFloat(props.rate.amount).toFixed(2) : ''
      editing.value  = true
    }

    function commit() {
      editing.value = false
      const v = parseFloat(inputVal.value)
      if (!isNaN(v) && v >= 0) emit('save', v)
    }

    function onKeydown(e) {
      if (e.key === 'Enter')  { e.preventDefault(); commit() }
      if (e.key === 'Escape') { editing.value = false }
    }

    return () => {
      if (editing.value) {
        return h('div', { class: 'flex justify-center' },
          h('input', {
            class: 'w-20 text-center text-sm bg-surface-700 border border-ember-500 rounded px-1 py-0.5 text-ink-100 focus:outline-none',
            value: inputVal.value,
            autofocus: true,
            onInput: (e) => { inputVal.value = e.target.value },
            onBlur: commit,
            onKeydown,
          })
        )
      }
      const amount = props.rate ? parseFloat(props.rate.amount) : null
      return h('button', {
        class: 'w-full text-center rounded py-1 px-2 transition-colors ' +
               (amount !== null
                 ? 'text-ink-200 hover:bg-surface-600 cursor-pointer font-medium'
                 : 'text-ink-700 hover:bg-surface-700 cursor-pointer text-xs'),
        onClick: startEdit,
        title: 'Click to edit',
      }, amount !== null ? `$${amount.toFixed(2)}` : '—')
    }
  }
})
</script>
