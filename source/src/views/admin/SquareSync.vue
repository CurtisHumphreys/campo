<template>
  <div class="p-6 max-w-5xl mx-auto space-y-5">

    <!-- Header -->
    <div class="flex items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold text-ink-100">Square Sync</h1>
        <p class="text-sm text-ink-500 mt-0.5">Link Square customers to households and import payment history</p>
      </div>
      <button v-if="configured && !setupMode" @click="loadCustomers" :disabled="customersLoading"
        class="btn btn-secondary btn-sm">
        {{ customersLoading ? 'Loading…' : '↺ Refresh' }}
      </button>
    </div>

    <LoadingSpinner v-if="initLoading" :full="true" />

    <!-- ── SETUP / TOKEN ENTRY ──────────────────────────────────────────── -->
    <template v-if="!initLoading && (!configured || setupMode)">
      <div class="max-w-xl mx-auto space-y-4">

        <!-- Steps card -->
        <div class="card p-5 space-y-3">
          <div class="font-semibold text-ink-200">Where to find your Square Access Token</div>
          <ol class="space-y-2 text-sm text-ink-400">
            <li class="flex gap-3">
              <span class="w-5 h-5 rounded-full bg-ember-500/20 text-ember-400 text-xs font-bold flex items-center justify-center flex-none mt-0.5">1</span>
              Go to <a href="https://developer.squareup.com" target="_blank" class="text-ember-400 underline underline-offset-2">developer.squareup.com</a> and sign in
            </li>
            <li class="flex gap-3">
              <span class="w-5 h-5 rounded-full bg-ember-500/20 text-ember-400 text-xs font-bold flex items-center justify-center flex-none mt-0.5">2</span>
              Click <strong class="text-ink-300">Applications</strong> in the left sidebar and open your app
            </li>
            <li class="flex gap-3">
              <span class="w-5 h-5 rounded-full bg-ember-500/20 text-ember-400 text-xs font-bold flex items-center justify-center flex-none mt-0.5">3</span>
              Click the <strong class="text-ink-300">Credentials</strong> tab, then switch to <strong class="text-ink-300">Production</strong> at the top
            </li>
            <li class="flex gap-3">
              <span class="w-5 h-5 rounded-full bg-ember-500/20 text-ember-400 text-xs font-bold flex items-center justify-center flex-none mt-0.5">4</span>
              Click <strong class="text-ink-300">Show</strong> next to <em>Production Access Token</em>, then copy it
            </li>
          </ol>
        </div>

        <!-- Token entry card -->
        <div class="card p-5 space-y-4">
          <div class="font-semibold text-ink-200">
            {{ setupMode ? 'Update Access Token' : 'Enter Access Token' }}
          </div>

          <div class="space-y-2">
            <label class="text-xs text-ink-500 font-medium">Square Production Access Token</label>
            <div class="relative">
              <input
                v-model="tokenInput"
                :type="showToken ? 'text' : 'password'"
                placeholder="EAAAl..."
                class="w-full text-sm pr-10 font-mono"
                @keydown.enter="saveToken"
              />
              <button @click="showToken = !showToken"
                class="absolute right-3 top-1/2 -translate-y-1/2 text-ink-500 hover:text-ink-300 text-xs">
                {{ showToken ? 'Hide' : 'Show' }}
              </button>
            </div>
          </div>

          <div v-if="connectError" class="text-sm text-red-400 bg-red-500/10 rounded-lg px-3 py-2">
            {{ connectError }}
          </div>

          <div class="flex items-center gap-3">
            <button @click="saveToken" :disabled="!tokenInput.trim() || saving"
              class="btn btn-primary btn-sm flex-none">
              {{ saving ? 'Connecting…' : (setupMode ? 'Update Token' : 'Connect Square') }}
            </button>
            <button v-if="setupMode" @click="cancelSetup" class="btn btn-ghost btn-sm text-ink-400">
              Cancel
            </button>
          </div>
        </div>

      </div>
    </template>

    <!-- ── CONNECTED STATE ─────────────────────────────────────────────── -->
    <template v-if="!initLoading && configured && !setupMode">

      <!-- Token status bar -->
      <div class="card px-4 py-3 flex items-center gap-3">
        <span class="text-xs text-emerald-400 font-medium flex items-center gap-1.5">
          <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 inline-block"></span>
          Connected
        </span>
        <span class="font-mono text-xs text-ink-500 flex-1">{{ maskedToken }}</span>
        <button @click="enterSetupMode" class="btn btn-ghost btn-sm text-xs text-ink-400">
          Update Token
        </button>
      </div>

      <!-- Square API error (token saved but API call failed) -->
      <div v-if="apiError" class="card p-5 border border-red-500/30 bg-red-500/5 space-y-3">
        <div class="font-medium text-red-400">Could not connect to Square</div>
        <div class="text-sm text-ink-400">{{ apiError }}</div>
        <button @click="enterSetupMode" class="btn btn-sm btn-secondary">Update Token</button>
      </div>

      <template v-if="!apiError">
        <!-- Stats -->
        <div class="grid grid-cols-3 gap-3">
          <div class="card p-4 text-center">
            <div class="text-2xl font-bold text-ink-100">{{ customers.length }}</div>
            <div class="text-xs text-ink-500 mt-1">Square Customers</div>
          </div>
          <div class="card p-4 text-center">
            <div class="text-2xl font-bold text-emerald-400">{{ linkedCount }}</div>
            <div class="text-xs text-ink-500 mt-1">Linked</div>
          </div>
          <div class="card p-4 text-center">
            <div class="text-2xl font-bold text-amber-400">{{ unlinkedCount }}</div>
            <div class="text-xs text-ink-500 mt-1">Unlinked</div>
          </div>
        </div>

        <!-- Filter + Search -->
        <div class="flex items-center gap-3 flex-wrap">
          <div class="flex rounded-xl overflow-hidden border border-surface-600">
            <button v-for="f in filters" :key="f.value" @click="filter = f.value"
              class="px-4 py-2 text-sm font-medium transition-colors"
              :class="filter === f.value
                ? 'bg-ember-500/20 text-ember-400'
                : 'text-ink-400 hover:text-ink-200 hover:bg-surface-700'">
              {{ f.label }}
            </button>
          </div>
          <input v-model="search" type="text" placeholder="Search name, email or phone…"
            class="flex-1 min-w-48 text-sm" />
        </div>

        <LoadingSpinner v-if="customersLoading" />

        <!-- Empty -->
        <EmptyState v-else-if="!filteredCustomers.length"
          icon="🔍" title="No customers match" subtitle="Try a different filter or search." />

        <!-- Customer list -->
        <div v-else class="space-y-2">
          <div v-for="c in filteredCustomers" :key="c.id" class="card p-4 space-y-3">

            <div class="flex items-start gap-3">
              <!-- Avatar -->
              <div class="w-10 h-10 rounded-xl bg-ember-500/15 border border-ember-500/20
                          flex items-center justify-center flex-none">
                <span class="text-ember-400 font-bold text-sm">{{ initials(c.name || c.email) }}</span>
              </div>

              <!-- Info -->
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                  <span class="font-medium text-ink-200">{{ c.name || '(no name)' }}</span>
                  <span v-if="c.email" class="text-xs text-ink-500">{{ c.email }}</span>
                  <span v-if="c.phone" class="text-xs text-ink-600">{{ c.phone }}</span>
                </div>
                <div class="flex items-center gap-2 mt-1 flex-wrap">
                  <span v-if="c.subscription"
                    class="text-xs px-2 py-0.5 rounded-full font-medium"
                    :class="subStatusClass(c.subscription.status)">
                    {{ subStatusLabel(c.subscription.status) }}
                    <template v-if="c.subscription.amount !== null">
                      · ${{ c.subscription.amount.toFixed(2) }} {{ c.subscription.currency }}
                    </template>
                    <template v-if="c.subscription.charged_through">
                      · through {{ formatShortDate(c.subscription.charged_through) }}
                    </template>
                  </span>
                  <span v-else class="text-xs text-ink-600 italic">No subscription</span>
                </div>
              </div>

              <!-- Actions -->
              <div class="flex items-center gap-2 flex-none">
                <template v-if="c.household">
                  <span class="text-xs px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-400
                               border border-emerald-500/20 font-medium">
                    {{ c.household.name }}
                  </span>
                  <button @click="openCharges(c)" class="btn btn-sm btn-secondary text-xs">
                    Payments
                  </button>
                  <button @click="unlink(c)" class="btn btn-sm btn-ghost text-xs text-ink-500">
                    Unlink
                  </button>
                </template>
                <template v-else>
                  <button @click="toggleLink(c.id)" class="btn btn-sm btn-secondary text-xs">
                    {{ linkingId === c.id ? 'Cancel' : 'Link Household' }}
                  </button>
                </template>
              </div>
            </div>

            <!-- Inline household search -->
            <div v-if="linkingId === c.id" class="border-t border-surface-700 pt-3 space-y-2">
              <div class="text-xs text-ink-500 font-medium">Search households to link</div>
              <input v-model="linkSearch" ref="linkSearchInput" type="text"
                placeholder="Type a household name…" class="w-full text-sm"
                @keydown.escape="linkingId = null" />
              <div class="max-h-52 overflow-y-auto space-y-0.5">
                <div v-if="linkSearch.length > 0 && !filteredHouseholds.length"
                  class="text-xs text-ink-600 py-2 px-3">No matches</div>
                <button v-for="h in filteredHouseholds" :key="h.id"
                  @click="doLink(c.id, h.id)"
                  class="w-full text-left px-3 py-2 rounded-lg text-sm text-ink-300
                         hover:bg-surface-700 hover:text-ink-100 transition-colors">
                  {{ h.name }}
                </button>
                <div v-if="linkSearch.length === 0" class="text-xs text-ink-600 px-1 py-1">
                  Start typing to search {{ households.length }} households
                </div>
              </div>
            </div>

          </div>
        </div>
      </template>
    </template>

    <!-- ── PAYMENTS MODAL ──────────────────────────────────────────────── -->
    <AppModal v-model="chargesModal.open"
      :title="`Payments — ${chargesModal.customer?.name || chargesModal.customer?.email || ''}`">
      <div class="space-y-4">
        <div v-if="chargesModal.customer?.email" class="text-sm text-ink-500">
          {{ chargesModal.customer.email }}
        </div>

        <div class="flex items-center gap-3 p-3 rounded-xl bg-surface-800 border border-surface-700">
          <label class="text-sm text-ink-400 flex-none">Import to camp</label>
          <select v-model="chargesModal.campId" class="flex-1 text-sm">
            <option value="">Select a camp…</option>
            <option v-for="camp in camps" :key="camp.id" :value="camp.id">{{ camp.name }}</option>
          </select>
        </div>

        <div v-if="!chargesModal.campId" class="text-xs text-amber-400/80 px-1">
          Select a camp above before importing payments.
        </div>

        <LoadingSpinner v-if="chargesModal.loading" />

        <EmptyState v-else-if="!chargesModal.charges.length"
          icon="💳" title="No payments found"
          subtitle="No completed Square payments for this customer." />

        <div v-else class="space-y-2">
          <div v-for="charge in chargesModal.charges" :key="charge.id"
            class="flex items-center gap-3 p-3 rounded-xl bg-surface-800 border border-surface-700">
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2 flex-wrap">
                <span class="font-semibold text-ink-200 text-sm">
                  ${{ charge.amount.toFixed(2) }} {{ charge.currency }}
                </span>
                <span v-if="charge.note" class="text-xs text-ink-500 truncate">{{ charge.note }}</span>
              </div>
              <div class="text-xs text-ink-600 mt-0.5">{{ formatDate(charge.created) }}</div>
            </div>
            <span v-if="charge.imported"
              class="text-xs px-2 py-1 rounded-lg bg-emerald-500/10 text-emerald-400
                     border border-emerald-500/20 flex-none">
              Imported
            </span>
            <button v-else @click="importCharge(charge)"
              :disabled="!chargesModal.campId || charge.importing"
              class="btn btn-sm btn-secondary text-xs flex-none"
              :class="{ 'opacity-40 cursor-not-allowed': !chargesModal.campId }">
              {{ charge.importing ? '…' : 'Import' }}
            </button>
          </div>
        </div>
      </div>
    </AppModal>

  </div>
</template>

<script setup>
import { ref, computed, nextTick } from 'vue'
import { square as squareApi, camps as campsApi, households as householdsApi } from '@/api.js'
import AppModal from '@/components/AppModal.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import EmptyState from '@/components/EmptyState.vue'

// ── State ────────────────────────────────────────────────────────────────────
const initLoading      = ref(true)
const configured       = ref(false)
const maskedToken      = ref('')
const setupMode        = ref(false)
const tokenInput       = ref('')
const showToken        = ref(false)
const saving           = ref(false)
const connectError     = ref(null)

const customersLoading = ref(false)
const apiError         = ref(null)
const customers        = ref([])
const households       = ref([])
const camps            = ref([])

const filter     = ref('all')
const search     = ref('')
const linkingId  = ref(null)
const linkSearch      = ref('')
const linkSearchInput = ref(null)

const chargesModal = ref({
  open: false, customer: null, charges: [], campId: '', loading: false,
})

const filters = [
  { value: 'all',      label: 'All' },
  { value: 'unlinked', label: 'Unlinked' },
  { value: 'linked',   label: 'Linked' },
]

// ── Computed ─────────────────────────────────────────────────────────────────
const linkedCount   = computed(() => customers.value.filter(c => c.household).length)
const unlinkedCount = computed(() => customers.value.filter(c => !c.household).length)

const filteredCustomers = computed(() => {
  let list = customers.value
  if (filter.value === 'unlinked') list = list.filter(c => !c.household)
  if (filter.value === 'linked')   list = list.filter(c =>  c.household)
  const q = search.value.trim().toLowerCase()
  if (q) list = list.filter(c =>
    (c.name || '').toLowerCase().includes(q) ||
    (c.email || '').toLowerCase().includes(q) ||
    (c.phone || '').toLowerCase().includes(q) ||
    (c.household?.name || '').toLowerCase().includes(q)
  )
  return list
})

const filteredHouseholds = computed(() => {
  const q = linkSearch.value.trim().toLowerCase()
  if (!q) return []
  return households.value.filter(h => h.name.toLowerCase().includes(q)).slice(0, 10)
})

// ── Lifecycle ─────────────────────────────────────────────────────────────────
async function init() {
  initLoading.value = true
  try {
    const cfg = await squareApi.config()
    configured.value = cfg.configured
    maskedToken.value = cfg.masked || ''
    if (cfg.configured) {
      loadCustomers()
      loadSupport()
    }
  } finally {
    initLoading.value = false
  }
}

async function loadCustomers() {
  customersLoading.value = true
  apiError.value = null
  try {
    const res = await squareApi.customers()
    customers.value = res.customers || []
  } catch (e) {
    apiError.value = e?.data?.error || 'Could not connect to Square. Your token may be incorrect or expired.'
  } finally {
    customersLoading.value = false
  }
}

async function loadSupport() {
  const [campsRes, hhRes] = await Promise.all([campsApi.list(), householdsApi.list()])
  camps.value      = campsRes || []
  households.value = (hhRes?.households ?? hhRes) || []
}

// ── Token management ──────────────────────────────────────────────────────────
function enterSetupMode() {
  tokenInput.value  = ''
  showToken.value   = false
  connectError.value = null
  setupMode.value   = true
}

function cancelSetup() {
  setupMode.value    = false
  connectError.value = null
  tokenInput.value   = ''
}

async function saveToken() {
  const tok = tokenInput.value.trim()
  if (!tok) return
  saving.value       = true
  connectError.value = null
  try {
    await squareApi.saveConfig(tok)
    configured.value  = true
    setupMode.value   = false
    tokenInput.value  = ''
    // Re-fetch masked token and reload customers
    const cfg = await squareApi.config()
    maskedToken.value = cfg.masked || ''
    loadSupport()
    await loadCustomers()
  } catch (e) {
    connectError.value = e?.data?.error || 'Failed to save token. Please try again.'
  } finally {
    saving.value = false
  }
}

// ── Linking ───────────────────────────────────────────────────────────────────
function toggleLink(customerId) {
  if (linkingId.value === customerId) {
    linkingId.value  = null
    linkSearch.value = ''
  } else {
    linkingId.value  = customerId
    linkSearch.value = ''
    nextTick(() => linkSearchInput.value?.focus?.() ?? linkSearchInput.value?.[0]?.focus?.())
  }
}

async function doLink(squareCustomerId, householdId) {
  await squareApi.link(squareCustomerId, householdId)
  const customer  = customers.value.find(c => c.id === squareCustomerId)
  const household = households.value.find(h => h.id === householdId)
  if (customer && household) customer.household = { id: household.id, name: household.name }
  linkingId.value  = null
  linkSearch.value = ''
}

async function unlink(customer) {
  await squareApi.unlink(customer.household.id)
  customer.household = null
}

// ── Charges ───────────────────────────────────────────────────────────────────
async function openCharges(customer) {
  chargesModal.value = { open: true, customer, charges: [], campId: '', loading: true }
  try {
    const res = await squareApi.charges(customer.id)
    chargesModal.value.charges = res.charges || []
  } finally {
    chargesModal.value.loading = false
  }
}

async function importCharge(charge) {
  if (!chargesModal.value.campId) return
  charge.importing = true
  try {
    await squareApi.importCharge({
      household_id:      chargesModal.value.customer.household.id,
      camp_id:           chargesModal.value.campId,
      square_payment_id: charge.id,
      amount:            charge.amount,
      note:              charge.note,
      created:           charge.created,
    })
    charge.imported = true
  } finally {
    charge.importing = false
  }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function initials(str) {
  if (!str) return '?'
  const parts = str.trim().split(/\s+/)
  if (parts.length >= 2) return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
  return str.slice(0, 2).toUpperCase()
}

function formatDate(isoString) {
  if (!isoString) return ''
  return new Date(isoString).toLocaleDateString('en-AU', {
    day: 'numeric', month: 'short', year: 'numeric',
  })
}

function formatShortDate(dateStr) {
  if (!dateStr) return ''
  const [y, m, d] = dateStr.split('-')
  return `${d}/${m}/${y}`
}

function subStatusLabel(status) {
  return { active: 'Active', canceled: 'Cancelled', deactivated: 'Deactivated', paused: 'Paused', pending: 'Pending' }[status] ?? status
}

function subStatusClass(status) {
  return {
    active:      'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20',
    pending:     'bg-blue-500/10 text-blue-400 border border-blue-500/20',
    paused:      'bg-amber-500/10 text-amber-400 border border-amber-500/20',
    canceled:    'bg-surface-700 text-ink-500 border border-surface-600',
    deactivated: 'bg-surface-700 text-ink-500 border border-surface-600',
  }[status] ?? 'bg-surface-700 text-ink-500 border border-surface-600'
}

init()
</script>
