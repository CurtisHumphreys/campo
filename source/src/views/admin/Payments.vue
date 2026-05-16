<template>
  <div class="p-6 max-w-3xl mx-auto space-y-5">

    <!-- Header -->
    <div class="flex items-center justify-between gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-bold text-ink-100">Take Payments</h1>
        <p class="text-sm text-ink-500 mt-0.5">Record a payment for a household</p>
      </div>
      <div class="flex items-center gap-2">
        <button @click="refundMode = !refundMode"
          :class="refundMode ? 'btn btn-sm bg-red-500/20 text-red-400 border border-red-500/30 hover:bg-red-500/30' : 'btn btn-ghost btn-sm text-ink-500'">
          {{ refundMode ? 'Refund Mode: ON' : 'Refund Mode: OFF' }}
        </button>
        <button v-if="context" @click="holdForm" class="btn btn-ghost btn-sm">Hold</button>
        <button @click="resetAll" class="btn btn-ghost btn-sm text-red-400 hover:text-red-300">Reset</button>
      </div>
    </div>

    <!-- Held form restore banner -->
    <div v-if="held"
      class="card p-4 border border-amber-500/30 bg-amber-500/5 flex items-center gap-3">
      <span class="text-lg">⏸️</span>
      <div class="flex-1 text-sm text-amber-300">
        Payment held for <strong>{{ held.householdName }}</strong>
      </div>
      <button @click="restoreHold" class="btn btn-sm bg-amber-500/20 text-amber-300 border border-amber-500/30 hover:bg-amber-500/30">
        Restore
      </button>
      <button @click="held = null" class="btn btn-ghost btn-sm text-ink-500">Discard</button>
    </div>

    <!-- No active camp warning -->
    <div v-if="campsLoaded && !selectedCampId"
      class="card p-5 border border-amber-500/30 bg-amber-500/5 flex items-start gap-3">
      <span class="text-xl">⚠️</span>
      <div>
        <div class="text-sm font-semibold text-amber-400">No active camp</div>
        <div class="text-sm text-ink-400 mt-0.5">
          Set a camp to <span class="text-amber-400 font-medium">Active</span> in
          <RouterLink to="/camps" class="underline text-ember-400 hover:text-ember-300">Camps</RouterLink>
          before taking payments.
        </div>
      </div>
    </div>

    <!-- ── Section 1: Camp & Household ──────────────────────────────────── -->
    <div class="card p-5 space-y-4">
      <div class="section-label">1. Camp &amp; Household</div>

      <div>
        <label class="field-label">Camp</label>
        <select v-model="selectedCampId" @change="onCampChange" class="w-full text-sm">
          <option :value="null" disabled>— select a camp —</option>
          <option v-for="c in camps" :key="c.id" :value="c.id">
            {{ c.name }}{{ c.status === 'active' ? ' ✓ Active' : '' }}
          </option>
        </select>
      </div>

      <div>
        <label class="field-label">Household</label>
        <div class="flex gap-2">
          <div class="relative flex-1">
            <input v-model="householdSearch" type="text"
              placeholder="Search household name or site number…"
              @input="onSearch" class="w-full" />
            <div v-if="searchLoading && !searchResults.length"
              class="absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none">
              <svg class="w-4 h-4 animate-spin text-ink-500" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
              </svg>
            </div>
            <div v-if="searchResults.length"
              class="absolute z-10 w-full mt-1 bg-surface-700 border border-surface-500
                     rounded-xl shadow-modal overflow-hidden">
              <button v-for="r in searchResults" :key="r.id"
                @click="selectHousehold(r)"
                class="w-full px-4 py-3 text-left hover:bg-surface-600 transition-colors
                       flex items-center gap-3 border-b border-surface-600 last:border-0">
                <div class="w-8 h-8 rounded-lg bg-surface-600 flex items-center justify-center
                            text-xs font-bold text-ember-400 flex-none">
                  {{ (r.name || '?')[0].toUpperCase() }}
                </div>
                <div>
                  <div class="text-sm font-medium text-ink-200">{{ r.name }}</div>
                  <div class="text-xs text-ink-500">
                    <span v-if="r.site_number">Site {{ r.site_number }}</span>
                    <span v-if="r.member_count"> · {{ r.member_count }} member{{ r.member_count != 1 ? 's' : '' }}</span>
                  </div>
                </div>
              </button>
            </div>
          </div>
          <button @click="showNewHH = true" class="btn btn-ghost btn-sm whitespace-nowrap">+ New</button>
        </div>
      </div>

      <!-- Selected household summary -->
      <template v-if="context">
        <div class="flex items-center gap-3 p-3 bg-surface-700 rounded-xl">
          <div class="w-10 h-10 rounded-xl bg-surface-600 flex items-center justify-center
                      text-sm font-bold text-ember-400 flex-none">
            {{ (context.household.name || '?')[0].toUpperCase() }}
          </div>
          <div class="flex-1 min-w-0">
            <div class="font-medium text-ink-200">{{ context.household.name }}</div>
            <div class="text-xs text-ink-500 flex gap-3 flex-wrap mt-0.5">
              <span v-if="context.site_number">Site {{ context.site_number }}</span>
              <span>{{ context.members.length }} member{{ context.members.length !== 1 ? 's' : '' }}</span>
              <span v-if="context.prepayment_balance > 0" class="text-emerald-400">
                ${{ context.prepayment_balance.toFixed(2) }} prepaid available
              </span>
              <span v-if="context.total_paid > 0" class="text-sky-400">
                ${{ context.total_paid.toFixed(2) }} paid this camp
              </span>
            </div>
          </div>
          <button @click="clearHousehold" class="btn btn-ghost p-1 text-ink-500">✕</button>
        </div>
        <div v-if="context.members.length" class="flex flex-wrap gap-1.5">
          <span v-for="m in context.members" :key="m.id"
            class="badge bg-surface-600 text-ink-400 text-xs">
            {{ m.name }}
            <span class="text-ink-600 ml-1 capitalize">{{ m.member_type }}</span>
          </span>
        </div>
      </template>
    </div>

    <!-- ── Section 2: Occupants & Rates ─────────────────────────────────── -->
    <div v-if="context" class="card p-5 space-y-4">
      <div class="flex items-center justify-between">
        <div class="section-label mb-0">2. Occupants &amp; Rates</div>
        <div v-if="rateSheets.length > 1" class="flex gap-1">
          <button v-for="sh in rateSheets" :key="sh"
            @click="selectedRateSheet = sh; loadRates()"
            :class="selectedRateSheet === sh ? 'btn btn-primary btn-xs' : 'btn btn-secondary btn-xs'">
            {{ sh }}
          </button>
        </div>
      </div>

      <!-- Adults + Kids counters -->
      <div class="flex gap-6 flex-wrap">
        <div class="text-center">
          <div class="text-xs text-ink-500 mb-1.5">Adults</div>
          <div class="flex items-center gap-2">
            <button @click="adultCount > 0 && adultCount--"
              class="w-7 h-7 rounded-lg bg-surface-600 text-ink-300 hover:bg-surface-500
                     text-sm font-bold leading-none transition-colors">−</button>
            <span class="w-6 text-center font-semibold text-ink-100">{{ adultCount }}</span>
            <button @click="adultCount++"
              class="w-7 h-7 rounded-lg bg-surface-600 text-ink-300 hover:bg-surface-500
                     text-sm font-bold leading-none transition-colors">+</button>
          </div>
        </div>
        <div class="text-center">
          <div class="text-xs text-ink-500 mb-1.5">Kids (5–13)</div>
          <div class="flex items-center gap-2">
            <button @click="kidCount > 0 && kidCount--"
              class="w-7 h-7 rounded-lg bg-surface-600 text-ink-300 hover:bg-surface-500
                     text-sm font-bold leading-none transition-colors">−</button>
            <span class="w-6 text-center font-semibold text-ink-100">{{ kidCount }}</span>
            <button @click="kidCount++"
              class="w-7 h-7 rounded-lg bg-surface-600 text-ink-300 hover:bg-surface-500
                     text-sm font-bold leading-none transition-colors">+</button>
          </div>
        </div>
      </div>

      <!-- Site type + concession + modifiers -->
      <div class="flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-36">
          <label class="field-label">Site Type</label>
          <select v-model="selectedSiteType" class="w-full text-sm">
            <option value="">— select —</option>
            <option v-for="st in SITE_TYPES" :key="st" :value="st">{{ st }}</option>
          </select>
        </div>
        <div class="min-w-36">
          <label class="field-label">Concession</label>
          <select v-model="isConcession" class="w-full text-sm">
            <option :value="false">No Concession</option>
            <option :value="true">Concession</option>
          </select>
        </div>
      </div>

      <div class="flex gap-5 flex-wrap">
        <label class="flex items-center gap-2 cursor-pointer text-sm text-ink-400">
          <input type="checkbox" v-model="isDayRate" class="w-4 h-4" />
          Day Rate
        </label>
        <label class="flex items-center gap-2 cursor-pointer text-sm text-ink-400">
          <input type="checkbox" v-model="isOffpeak" class="w-4 h-4" />
          Off-peak
        </label>
      </div>

      <!-- Rate breakdown (auto-calculated) -->
      <div v-if="campFeeCalc && campFeeCalc.lines.length"
        class="p-3 bg-surface-700/50 rounded-xl text-xs text-ink-500 space-y-1">
        <div v-for="line in campFeeCalc.lines" :key="line.label" class="flex justify-between">
          <span>{{ line.label }}</span>
          <span class="text-ink-300">{{ line.value }}</span>
        </div>
        <div v-if="campFeeCalc.capped" class="flex justify-between text-amber-400">
          <span>Family Cap applied</span>
          <span>${{ campFeeCalc.nightlyCap.toFixed(2) }}/night</span>
        </div>
        <div class="flex justify-between font-semibold text-ink-200 pt-1 border-t border-surface-600 mt-1">
          <span>Camp fee ({{ campFeeCalc.nights }} night{{ campFeeCalc.nights !== 1 ? 's' : '' }})</span>
          <span class="text-ember-400">${{ campFeeCalc.total.toFixed(2) }}</span>
        </div>
      </div>

      <div v-else-if="adultCount > 0 || kidCount > 0" class="text-xs text-ink-600">
        Select a site type and stay dates to calculate fee.
      </div>
    </div>

    <!-- ── Section 3: Stay Dates ─────────────────────────────────────────── -->
    <div v-if="context" class="card p-5 space-y-4">
      <div class="section-label">3. Stay Dates</div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="field-label">Arrival</label>
          <input v-model="form.arrival_date" type="date" class="w-full" />
        </div>
        <div>
          <label class="field-label">Departure</label>
          <input v-model="form.departure_date" type="date" class="w-full" />
        </div>
      </div>
      <div class="flex items-center gap-4 text-sm">
        <span v-if="nightsCount !== null" class="font-medium text-ink-200">
          {{ nightsCount }} night{{ nightsCount !== 1 ? 's' : '' }}
        </span>
        <div class="flex-1"></div>
        <label class="field-label mb-0 text-ink-500">Headcount</label>
        <input v-model="form.headcount" type="number" min="1" placeholder="# people"
          class="w-24 text-sm" />
      </div>
    </div>

    <!-- ── Section 4: Amounts ────────────────────────────────────────────── -->
    <div v-if="context" class="card p-5 space-y-4">
      <div class="section-label">4. Amounts</div>

      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div>
          <label class="field-label">Camp Fee</label>
          <div class="relative">
            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-500 text-sm pointer-events-none">$</span>
            <input v-model="form.camp_fee" type="number" step="0.01" min="0"
              class="pl-7 w-full" placeholder="0.00" />
          </div>
        </div>
        <div class="space-y-1.5">
          <label class="field-label">Site Fee</label>
          <div class="flex gap-2">
            <div class="relative flex-1">
              <span class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-500 text-sm pointer-events-none">$</span>
              <input v-model="form.site_fee" type="number" step="0.01" min="0"
                class="pl-7 w-full" placeholder="0.00" />
            </div>
            <select v-if="+form.site_fee > 0 && !refundMode" v-model="form.site_fee_months"
              class="text-sm w-20 shrink-0" title="Months to extend site rental">
              <option v-for="m in 24" :key="m" :value="m">{{ m }}m</option>
            </select>
          </div>
          <div v-if="+form.site_fee > 0 && !refundMode" class="text-xs text-ink-500 space-y-0.5">
            <div v-if="context?.site_fee_expires">
              Current: <span class="text-ink-300">{{ fmtExpiry(context.site_fee_expires) }}</span>
            </div>
            <div v-if="newSiteFeeExpiry">
              New expiry: <span class="text-emerald-400 font-medium">{{ fmtExpiry(newSiteFeeExpiry) }}</span>
            </div>
          </div>
        </div>
        <div>
          <label class="field-label">Other</label>
          <div class="relative">
            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-500 text-sm pointer-events-none">$</span>
            <input v-model="form.other_amount" type="number" step="0.01" min="0"
              class="pl-7 w-full" placeholder="0.00" />
          </div>
        </div>
      </div>

      <!-- Add / Discount -->
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="field-label">Add $</label>
          <div class="relative">
            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-500 text-sm pointer-events-none">$</span>
            <input v-model="form.add_amount" type="number" step="0.01" min="0"
              class="pl-7 w-full" placeholder="0.00" />
          </div>
        </div>
        <div>
          <label class="field-label">Discount $</label>
          <div class="relative">
            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-500 text-sm pointer-events-none">$</span>
            <input v-model="form.disc_amount" type="number" step="0.01" min="0"
              class="pl-7 w-full" placeholder="0.00" />
          </div>
        </div>
      </div>

      <!-- Apply prepayments -->
      <div v-if="context.prepayments.length">
        <div class="flex items-center justify-between mb-1.5">
          <label class="field-label mb-0">Apply Prepayments</label>
          <button @click="selectAllPrepayments" class="text-xs text-ember-400 hover:text-ember-300">Max</button>
        </div>
        <div class="space-y-1.5">
          <label v-for="p in context.prepayments" :key="p.id"
            class="flex items-center gap-3 p-2.5 bg-surface-700 rounded-lg cursor-pointer
                   hover:bg-surface-600 transition-colors">
            <input type="checkbox" :value="p.id" v-model="form.prepayment_ids"
              class="w-4 h-4 flex-none" />
            <div class="flex-1 min-w-0">
              <span class="text-sm text-ink-200">{{ p.name }}</span>
              <span v-if="p.reference" class="text-xs text-ink-500 ml-2">Ref: {{ p.reference }}</span>
            </div>
            <span class="text-sm font-medium text-emerald-400">${{ parseFloat(p.amount).toFixed(2) }}</span>
          </label>
        </div>
        <div v-if="prepaidApplied > 0" class="text-xs text-emerald-400 mt-1.5">
          Applying ${{ prepaidApplied.toFixed(2) }} prepaid credit
        </div>
      </div>
    </div>

    <!-- ── Section 5: Payment Methods ───────────────────────────────────── -->
    <div v-if="context" class="card p-5 space-y-4">
      <div class="flex items-center justify-between">
        <div class="section-label mb-0">5. Payment Method</div>
        <button @click="addTender" class="btn btn-ghost btn-sm text-xs">+ Add</button>
      </div>

      <div class="space-y-2">
        <div v-for="(t, i) in form.tenders" :key="i" class="flex gap-2 items-center">
          <select v-model="t.method" class="w-28 flex-none text-sm">
            <option value="eftpos">EFTPOS</option>
            <option value="cash">Cash</option>
            <option value="bank">Bank</option>
            <option value="other">Other</option>
          </select>
          <div class="relative w-28 flex-none">
            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-500 text-sm pointer-events-none">$</span>
            <input v-model="t.amount" type="number" step="0.01" min="0"
              class="pl-7 w-full" placeholder="0.00" />
          </div>
          <button @click="fillAll(i)"
            class="btn btn-ghost btn-sm text-xs text-ink-400 hover:text-ink-200 flex-none px-2">
            All
          </button>
          <input v-model="t.reference" type="text" class="flex-1 text-sm min-w-0"
            placeholder="Reference (optional)" />
          <button v-if="form.tenders.length > 1" @click="removeTender(i)"
            class="btn btn-ghost p-1 text-ink-500 flex-none">✕</button>
        </div>
      </div>

      <!-- Running total -->
      <div class="p-3 bg-surface-700 rounded-xl text-sm space-y-1.5">
        <div class="flex justify-between text-ink-400">
          <span>Subtotal</span>
          <span :class="refundMode ? 'text-red-400' : ''">
            {{ refundMode ? '−' : '' }}${{ subtotal.toFixed(2) }}
          </span>
        </div>
        <div v-if="addDisc !== 0" class="flex justify-between"
          :class="addDisc > 0 ? 'text-sky-400' : 'text-amber-400'">
          <span>{{ addDisc > 0 ? 'Addition' : 'Discount' }}</span>
          <span>{{ addDisc > 0 ? '+' : '−' }}${{ Math.abs(addDisc).toFixed(2) }}</span>
        </div>
        <div v-if="prepaidApplied > 0" class="flex justify-between text-emerald-400">
          <span>Prepaid applied</span>
          <span>−${{ prepaidApplied.toFixed(2) }}</span>
        </div>
        <div class="flex justify-between font-bold border-t border-surface-600 pt-1.5"
          :class="refundMode ? 'text-red-400' : 'text-ink-100'">
          <span>{{ refundMode ? 'Refund Amount' : 'Amount Due' }}</span>
          <span>{{ refundMode ? '−' : '' }}${{ totalDue.toFixed(2) }}</span>
        </div>
        <div class="flex justify-between"
          :class="tenderTotal >= totalDue && totalDue > 0 ? 'text-emerald-400' : 'text-ink-400'">
          <span>Tendered</span>
          <span>${{ tenderTotal.toFixed(2) }}</span>
        </div>
        <div v-if="tenderTotal > totalDue && totalDue > 0 && !refundMode"
          class="flex justify-between text-amber-400">
          <span>Change</span>
          <span>${{ (tenderTotal - totalDue).toFixed(2) }}</span>
        </div>
      </div>

      <div>
        <label class="field-label">Payment Date</label>
        <input v-model="form.payment_date" type="date" class="w-full" />
      </div>

      <div>
        <label class="field-label">Notes</label>
        <input v-model="form.notes" type="text" class="w-full" placeholder="Receipt ref, notes…" />
      </div>

      <button @click="recordPayment" :disabled="!canPay || saving"
        :class="refundMode ? 'btn w-full text-base py-3 bg-red-500/20 text-red-300 border border-red-500/30 hover:bg-red-500/30 disabled:opacity-40' : 'btn btn-primary w-full text-base py-3'">
        {{ saving ? 'Recording…' : (refundMode ? `Record −$${totalDue.toFixed(2)} Refund` : `Record $${totalDue.toFixed(2)} Payment`) }}
      </button>
    </div>

    <!-- ── Payment history ───────────────────────────────────────────────── -->
    <div v-if="context?.payments?.length" class="card p-5 space-y-3">
      <div class="section-label">Payment History This Camp</div>
      <div v-for="p in context.payments" :key="p.id"
        class="flex items-center gap-3 py-2 border-b border-surface-700 last:border-0">
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2 flex-wrap">
            <span class="text-sm font-medium" :class="+p.total < 0 ? 'text-red-400' : 'text-ink-100'">
              ${{ parseFloat(p.total).toFixed(2) }}
            </span>
            <span v-if="+p.tender_eftpos > 0" class="text-xs text-purple-400">EFTPOS ${{ parseFloat(p.tender_eftpos).toFixed(2) }}</span>
            <span v-if="+p.tender_cash  > 0" class="text-xs text-emerald-400">Cash ${{ parseFloat(p.tender_cash).toFixed(2) }}</span>
            <span v-if="+p.tender_bank  > 0" class="text-xs text-sky-400">Bank ${{ parseFloat(p.tender_bank).toFixed(2) }}</span>
            <span v-if="+p.prepaid_applied > 0" class="text-xs text-amber-400">Prepaid −${{ parseFloat(p.prepaid_applied).toFixed(2) }}</span>
          </div>
          <div class="text-xs text-ink-500 mt-0.5 flex gap-2 flex-wrap">
            <span>{{ formatDate(p.payment_date) }}</span>
            <span v-if="+p.camp_fee > 0">Camp ${{ parseFloat(p.camp_fee).toFixed(2) }}</span>
            <span v-if="+p.site_fee > 0">Site ${{ parseFloat(p.site_fee).toFixed(2) }}</span>
            <span v-if="p.notes" class="text-ink-600">{{ p.notes }}</span>
          </div>
        </div>
        <button @click="deletePayment(p)"
          class="btn btn-ghost p-1 text-ink-600 hover:text-red-400 flex-none">✕</button>
      </div>
    </div>

    <!-- ── New Household modal ──────────────────────────────────────────── -->
    <AppModal v-model="showNewHH" title="New Household">
      <div class="space-y-4">
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="field-label">Last Name *</label>
            <input v-model="newHH.last_name" type="text" placeholder="e.g. Smith" autofocus />
          </div>
          <div>
            <label class="field-label">First Name *</label>
            <input v-model="newHH.first_name" type="text" placeholder="e.g. John" />
          </div>
        </div>
        <div class="text-xs text-ink-500">
          Household will be named: <span class="text-ink-300">{{ hhPreview }}</span>
        </div>
        <div>
          <label class="field-label">Member Type</label>
          <select v-model="newHH.member_type">
            <option value="adult">Adult</option>
            <option value="youth">Youth</option>
            <option value="child">Child</option>
            <option value="infant">Infant</option>
          </select>
        </div>
      </div>
      <template #footer>
        <button @click="showNewHH = false" class="btn btn-ghost">Cancel</button>
        <button @click="createHousehold" :disabled="!newHH.last_name || !newHH.first_name || creatingHH"
          class="btn btn-primary">
          {{ creatingHH ? 'Creating…' : 'Create & Select' }}
        </button>
      </template>
    </AppModal>

  </div>
</template>

<script setup>
import { ref, computed, watch, inject, onMounted, onBeforeUnmount } from 'vue'
import { RouterLink } from 'vue-router'
import { api } from '@/api.js'
import AppModal from '@/components/AppModal.vue'

const toast = inject('toast')

const SITE_TYPES = ['Unpowered Site', 'Powered Site', 'Dorms (KFC)', 'Family Room', 'Special Use']

const camps           = ref([])
const selectedCampId  = ref(null)
const campsLoaded     = ref(false)
const householdSearch = ref('')
const searchResults   = ref([])
const context         = ref(null)
const campRates         = ref([])
const rateSheets        = ref([])
const selectedRateSheet = ref('Standard')
const saving          = ref(false)
const searchLoading   = ref(false)
const refundMode      = ref(false)
const held            = ref(null)
const showNewHH       = ref(false)
const creatingHH      = ref(false)
const newHH           = ref({ first_name: '', last_name: '', member_type: 'adult' })

// Occupant & rate selectors
const adultCount       = ref(0)
const kidCount         = ref(0)
const isConcession     = ref(false)
const selectedSiteType = ref('')
const isDayRate        = ref(false)
const isOffpeak        = ref(false)

const blankForm = () => ({
  camp_fee: '', site_fee: '', other_amount: '',
  add_amount: '', disc_amount: '',
  site_fee_months: 12,
  prepayment_ids: [],
  tenders: [{ method: 'eftpos', amount: '', reference: '' }],
  arrival_date: '', departure_date: '', headcount: '',
  payment_date: new Date().toISOString().slice(0, 10),
  notes: ''
})
const form = ref(blankForm())
let searchTimer = null

const hhPreview = computed(() => {
  if (!newHH.value.last_name && !newHH.value.first_name) return '—'
  const l = newHH.value.last_name.trim()
  const f = newHH.value.first_name.trim()
  if (l && f) return `${l} (${f})`
  return l || f
})

// ── Camps ──────────────────────────────────────────────────────────────────
onMounted(async () => {
  try {
    const res = await api.camps.list()
    camps.value = Array.isArray(res) ? res : (res.camps ?? [])
    const active = camps.value.find(c => c.status === 'active')
    selectedCampId.value = active?.id ?? null
    if (selectedCampId.value) await loadRates()
  } catch {}
  campsLoaded.value = true
})

async function onCampChange() {
  clearHousehold()
  await loadRates()
}

async function loadRates() {
  campRates.value  = []
  rateSheets.value = []
  if (!selectedCampId.value) return
  try {
    const res = await api.rates.list(selectedCampId.value, selectedRateSheet.value)
    campRates.value  = res.rates  ?? []
    rateSheets.value = res.sheets ?? []
  } catch {}
}

// ── Rate grid lookup ───────────────────────────────────────────────────────
const rateMap = computed(() => {
  const m = new Map()
  campRates.value.forEach(r => m.set(`${r.site_type}|${r.guest_type}`, parseFloat(r.amount) || 0))
  return m
})

function getRate(siteType, guestType) {
  return rateMap.value.get(`${siteType}|${guestType}`) ?? 0
}

// ── Auto camp fee calculation ──────────────────────────────────────────────
const nightsCount = computed(() => {
  if (!form.value.arrival_date || !form.value.departure_date) return null
  const diff = (new Date(form.value.departure_date) - new Date(form.value.arrival_date)) / 86400000
  return diff > 0 ? diff : null
})

const campFeeCalc = computed(() => {
  const adults = adultCount.value
  const kids   = kidCount.value
  if (adults === 0 && kids === 0) return null

  const siteType = isDayRate.value ? 'Day Trip' : selectedSiteType.value
  if (!siteType) return null

  const nights = nightsCount.value ?? 0
  const lines  = []
  let nightlyTotal = 0

  if (isOffpeak.value) {
    // Off-peak: flat per-person rate, no couple distinction
    const rate = getRate(siteType, isConcession.value ? 'Offpeak Concession' : 'Offpeak')
    if (adults > 0) {
      lines.push({ label: `${adults} adult${adults > 1 ? 's' : ''} (offpeak${isConcession.value ? ' concession' : ''})`, value: `${adults} × $${rate.toFixed(2)}/night` })
      nightlyTotal += adults * rate
    }
  } else if (adults > 0) {
    if (adults >= 2) {
      const coupleRate = getRate(siteType, isConcession.value ? 'Concession Couple' : 'Adult Couple')
      nightlyTotal += coupleRate
      lines.push({ label: `Couple (${isConcession.value ? 'concession' : 'adult'})`, value: `$${coupleRate.toFixed(2)}/night` })
      if (adults > 2) {
        const singleRate = getRate(siteType, isConcession.value ? 'Concession Single' : 'Adult Single')
        nightlyTotal += (adults - 2) * singleRate
        lines.push({ label: `${adults - 2} extra adult${adults - 2 > 1 ? 's' : ''}`, value: `${adults - 2} × $${singleRate.toFixed(2)}/night` })
      }
    } else {
      const singleRate = getRate(siteType, isConcession.value ? 'Concession Single' : 'Adult Single')
      nightlyTotal += singleRate
      lines.push({ label: `1 adult (${isConcession.value ? 'concession single' : 'single'})`, value: `$${singleRate.toFixed(2)}/night` })
    }
  }

  if (kids > 0) {
    const kidRate = getRate(siteType, 'Child')
    nightlyTotal += kids * kidRate
    lines.push({ label: `${kids} child${kids > 1 ? 'ren' : ''} (5–13)`, value: `${kids} × $${kidRate.toFixed(2)}/night` })
  }

  const familyCap = getRate(siteType, 'Family Cap')
  const capped = familyCap > 0 && nightlyTotal > familyCap
  if (capped) nightlyTotal = familyCap

  return { lines, capped, nightlyCap: familyCap, nightlyTotal, nights, total: nightlyTotal * nights }
})

// Auto-fill camp fee when calculation changes
watch(campFeeCalc, (calc) => {
  if (calc && calc.total > 0) {
    form.value.camp_fee = calc.total.toFixed(2)
  }
})

// ── Hold / Reset ───────────────────────────────────────────────────────────
function holdForm() {
  if (!context.value) return
  held.value = {
    householdName:     context.value.household.name,
    context:           context.value,
    form:              JSON.parse(JSON.stringify(form.value)),
    adultCount:        adultCount.value,
    kidCount:          kidCount.value,
    isConcession:      isConcession.value,
    selectedSiteType:  selectedSiteType.value,
    isDayRate:         isDayRate.value,
    isOffpeak:         isOffpeak.value,
    householdSearch:   householdSearch.value,
  }
  clearHousehold()
  toast?.add(`Held for ${held.value.householdName}`, 'success')
}

function restoreHold() {
  if (!held.value) return
  context.value        = held.value.context
  form.value           = held.value.form
  adultCount.value     = held.value.adultCount
  kidCount.value       = held.value.kidCount
  isConcession.value   = held.value.isConcession
  selectedSiteType.value = held.value.selectedSiteType
  isDayRate.value      = held.value.isDayRate
  isOffpeak.value      = held.value.isOffpeak
  householdSearch.value = held.value.householdSearch
  held.value = null
}

function resetAll() {
  clearHousehold()
  refundMode.value = false
  held.value       = null
}

// ── Household search ───────────────────────────────────────────────────────
function onSearch() {
  clearTimeout(searchTimer)
  if (!householdSearch.value.trim()) { searchResults.value = []; searchLoading.value = false; return }
  searchLoading.value = true
  searchTimer = setTimeout(async () => {
    try {
      searchResults.value = await api.get('/households/search', {
        camp_id: selectedCampId.value, q: householdSearch.value
      })
    } catch {}
    searchLoading.value = false
  }, 250)
}

async function selectHousehold(h) {
  if (!selectedCampId.value) {
    toast?.add('Select a camp first', 'error')
    return
  }
  searchResults.value   = []
  householdSearch.value = h.name
  try {
    context.value = await api.get('/household/payment-context', {
      household_id: h.id, camp_id: selectedCampId.value
    })
    form.value = blankForm()
    prefillOccupants()
  } catch (e) {
    householdSearch.value = ''
    toast?.add(e?.data?.error || 'Could not load household data', 'error')
  }
}

function prefillOccupants() {
  if (!context.value?.members) return
  adultCount.value = context.value.members.filter(m => ['adult', 'youth'].includes(m.member_type)).length
  kidCount.value   = context.value.members.filter(m => m.member_type === 'child').length
}

function clearHousehold() {
  context.value          = null
  householdSearch.value  = ''
  searchResults.value    = []
  form.value             = blankForm()
  adultCount.value       = 0
  kidCount.value         = 0
  isConcession.value     = false
  selectedSiteType.value = ''
  isDayRate.value        = false
  isOffpeak.value        = false
}

// ── New Household ──────────────────────────────────────────────────────────
async function createHousehold() {
  if (!newHH.value.first_name || !newHH.value.last_name) return
  creatingHH.value = true
  try {
    const hhName = `${newHH.value.last_name.trim()} (${newHH.value.first_name.trim()})`
    const hhRes  = await api.households.create({ name: hhName })
    const hhId   = hhRes.id
    await api.members.create({
      first_name:   newHH.value.first_name.trim(),
      last_name:    newHH.value.last_name.trim(),
      household_id: hhId,
      member_type:  newHH.value.member_type,
    })
    const mockH = { id: hhId, name: hhName, site_number: null, member_count: 1 }
    await selectHousehold(mockH)
    showNewHH.value  = false
    newHH.value      = { first_name: '', last_name: '', member_type: 'adult' }
    toast?.add(`Created household ${hhName}`, 'success')
  } catch (e) {
    toast?.add(e?.data?.message || 'Create failed', 'error')
  }
  creatingHH.value = false
}

// ── Totals ─────────────────────────────────────────────────────────────────
const subtotal = computed(() =>
  (parseFloat(form.value.camp_fee)     || 0) +
  (parseFloat(form.value.site_fee)     || 0) +
  (parseFloat(form.value.other_amount) || 0)
)

const addDisc = computed(() =>
  (parseFloat(form.value.add_amount)  || 0) -
  (parseFloat(form.value.disc_amount) || 0)
)

const prepaidApplied = computed(() => {
  if (!context.value) return 0
  return context.value.prepayments
    .filter(p => form.value.prepayment_ids.includes(p.id))
    .reduce((s, p) => s + parseFloat(p.amount), 0)
})

const totalDue    = computed(() => Math.max(0, subtotal.value + addDisc.value - prepaidApplied.value))
const tenderTotal = computed(() => form.value.tenders.reduce((s, t) => s + (parseFloat(t.amount) || 0), 0))
const canPay      = computed(() => context.value && selectedCampId.value && (totalDue.value > 0 || subtotal.value > 0))

const newSiteFeeExpiry = computed(() => {
  const months = parseInt(form.value.site_fee_months) || 0
  if (!months || !(parseFloat(form.value.site_fee) > 0) || refundMode.value) return null
  const today = new Date(); today.setHours(0, 0, 0, 0)
  const exp   = context.value?.site_fee_expires
  const base  = exp ? new Date(exp + 'T00:00:00') : today
  const start = base > today ? base : today
  const result = new Date(start)
  result.setMonth(result.getMonth() + months)
  return result.toISOString().slice(0, 10)
})

function fmtExpiry(d) {
  if (!d) return ''
  return new Date(d + 'T00:00:00').toLocaleDateString('en-AU', { day: 'numeric', month: 'short', year: 'numeric' })
}

function selectAllPrepayments() {
  if (!context.value) return
  form.value.prepayment_ids = context.value.prepayments.map(p => p.id)
}

// ── Tender helpers ─────────────────────────────────────────────────────────
function addTender()     { form.value.tenders.push({ method: 'cash', amount: '', reference: '' }) }
function removeTender(i) { form.value.tenders.splice(i, 1) }
function fillAll(i) {
  const others = form.value.tenders.reduce((s, t, idx) => idx === i ? s : s + (parseFloat(t.amount) || 0), 0)
  form.value.tenders[i].amount = Math.max(0, totalDue.value - others).toFixed(2)
}

// ── Submit ─────────────────────────────────────────────────────────────────
async function recordPayment() {
  saving.value = true
  const sign   = refundMode.value ? -1 : 1
  try {
    await api.payments.create({
      household_id:    context.value.household.id,
      camp_id:         selectedCampId.value,
      camp_fee:        sign * (parseFloat(form.value.camp_fee)     || 0),
      site_fee:        sign * (parseFloat(form.value.site_fee)     || 0),
      other_amount:    sign * ((parseFloat(form.value.other_amount) || 0) + addDisc.value),
      prepaid_applied: prepaidApplied.value,
      site_fee_months: !refundMode.value && parseFloat(form.value.site_fee) > 0 ? (parseInt(form.value.site_fee_months) || 0) : 0,
      prepayment_ids:  form.value.prepayment_ids,
      tenders:         form.value.tenders.filter(t => parseFloat(t.amount) > 0),
      arrival_date:    form.value.arrival_date   || null,
      departure_date:  form.value.departure_date || null,
      headcount:       form.value.headcount !== '' ? parseInt(form.value.headcount) : null,
      payment_date:    form.value.payment_date   || null,
      notes:           form.value.notes,
      is_refund:       refundMode.value,
    })
    toast?.add(refundMode.value ? 'Refund recorded' : 'Payment recorded', 'success')
    const updated = await api.get('/household/payment-context', {
      household_id: context.value.household.id, camp_id: selectedCampId.value
    })
    context.value = updated
    form.value    = blankForm()
    prefillOccupants()
  } catch (e) {
    toast?.add(e?.data?.message || 'Payment failed', 'error')
  } finally {
    saving.value = false
  }
}

const pendingDeletes = new Map()

onBeforeUnmount(() => {
  pendingDeletes.forEach(({ timer }) => clearTimeout(timer))
  pendingDeletes.forEach(async ({ payment }) => {
    try { await api.payments.delete(payment.id) } catch {}
  })
  pendingDeletes.clear()
})

function deletePayment(p) {
  const householdId = context.value?.household?.id
  if (context.value?.payments) {
    context.value.payments = context.value.payments.filter(x => x.id !== p.id)
  }

  const timer = setTimeout(async () => {
    pendingDeletes.delete(p.id)
    try {
      await api.payments.delete(p.id)
      if (context.value?.household?.id === householdId && householdId) {
        const updated = await api.get('/household/payment-context', {
          household_id: householdId, camp_id: selectedCampId.value
        })
        context.value = updated
      }
    } catch {
      toast?.add('Failed to delete payment', 'error')
    }
  }, 6000)

  pendingDeletes.set(p.id, { payment: p, timer })

  toast?.add('Payment removed', 'info', 6000, {
    label: 'Undo',
    fn() {
      const entry = pendingDeletes.get(p.id)
      if (!entry) return
      clearTimeout(entry.timer)
      pendingDeletes.delete(p.id)
      if (context.value?.payments) {
        context.value.payments = [...context.value.payments, p]
          .sort((a, b) => new Date(b.payment_date) - new Date(a.payment_date))
      }
    }
  })
}

function formatDate(d) {
  if (!d) return ''
  const dt = new Date(d)
  return `${dt.getDate()} ${['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][dt.getMonth()]} ${dt.getFullYear()}`
}
</script>
