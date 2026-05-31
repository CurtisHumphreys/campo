<template>
  <div class="p-6 max-w-5xl mx-auto space-y-5">

    <!-- Header -->
    <div class="flex items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold text-ink-100">Sites</h1>
        <p class="text-sm text-ink-500 mt-0.5">{{ viewSubtitle }}</p>
      </div>
      <button v-if="activeView === 'sites'" @click="openNew" class="btn btn-primary btn-sm">+ Add Site</button>
    </div>

    <!-- Tabs -->
    <div class="flex gap-1 border-b border-surface-600">
      <button v-for="t in viewTabs" :key="t.value"
        @click="switchView(t.value)"
        :class="['btn btn-sm rounded-b-none', activeView === t.value ? 'btn-primary' : 'btn-ghost text-ink-400']">
        {{ t.label }}
        <span v-if="t.count" class="ml-1.5 bg-ember-500 text-surface-900 text-xs font-bold rounded-full min-w-4 h-4 px-1 inline-flex items-center justify-center">{{ t.count }}</span>
      </button>
    </div>

    <!-- ── WAITLIST VIEW ───────────────────────────────────────────────────── -->
    <template v-if="activeView === 'waitlist'">
      <LoadingSpinner v-if="wlLoading" :full="true" />
      <EmptyState v-else-if="!waitlistItems.length" icon="📋" title="No waitlist entries"
        subtitle="Entries submitted via the camp intranet will appear here." />
      <div v-else class="space-y-2">
        <!-- Toolbar: counts + status filter + sort -->
        <div class="flex items-center gap-2 flex-wrap text-sm text-ink-500">
          <span>{{ filteredWaitlist.length }}<span class="text-ink-700">/</span>{{ waitlistItems.length }}</span>
          <span class="text-ink-700">·</span>
          <span>Active: <b class="text-ink-200">{{ waitlistActiveCount }}</b></span>
          <div class="ml-auto flex items-center gap-2">
            <!-- Sort -->
            <div class="flex gap-1 border border-surface-600 rounded-lg p-0.5">
              <button @click="wlSortBy = 'rank'"
                :class="['btn btn-sm px-2.5', wlSortBy === 'rank' ? 'btn-primary' : 'btn-ghost text-ink-400']">
                Rank
              </button>
              <button @click="wlSortBy = 'date'"
                :class="['btn btn-sm px-2.5', wlSortBy === 'date' ? 'btn-primary' : 'btn-ghost text-ink-400']">
                Date
              </button>
            </div>
            <!-- Status filter -->
            <div class="flex gap-1 border border-surface-600 rounded-lg p-0.5">
              <button v-for="s in wlStatusFilters" :key="s"
                @click="wlStatusFilter = s"
                :class="['btn btn-sm px-2.5', wlStatusFilter === s ? 'btn-primary' : 'btn-ghost text-ink-400']">{{ s }}</button>
            </div>
          </div>
        </div>

        <!-- Entries -->
        <div v-for="(item, idx) in filteredWaitlist" :key="item.id"
          class="card overflow-hidden">
          <!-- Summary row -->
          <div class="p-4 flex items-start gap-3 cursor-pointer"
            @click="expandedWlId = expandedWlId === item.id ? null : item.id">

            <!-- Rank number -->
            <div class="shrink-0 w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold"
              :class="rankBubble(item)">
              {{ rankMap[item.id] }}
            </div>

            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2 flex-wrap">
                <span class="font-semibold text-ink-100">{{ item.first_name }} {{ item.last_name }}</span>
                <span :class="wlPriorityBadge(item.priority)">{{ item.priority }}</span>
                <span v-if="item.priority_source === 'Manual'" class="text-[10px] text-ink-600">(manual)</span>
                <span :class="wlStatusBadge(item.status)">{{ item.status }}</span>
              </div>
              <div class="text-sm text-ink-400 mt-0.5 flex flex-wrap gap-x-4 gap-y-0.5">
                <span>{{ item.site_type }} · {{ item.adults }}A{{ item.kids ? ` ${item.kids}K` : '' }}</span>
                <span v-if="item.days_waiting">{{ item.days_waiting }}d waiting</span>
                <span v-if="item.intended_days">{{ item.intended_days }} intended days</span>
                <span v-if="item.phone" class="text-ink-500">{{ item.phone }}</span>
              </div>
              <div v-if="item.special_considerations" class="text-xs text-amber-400 mt-0.5 truncate">
                ⚠ {{ item.special_considerations }}
              </div>
            </div>
            <div class="text-ink-600 text-sm shrink-0 mt-1.5">{{ expandedWlId === item.id ? '▲' : '▼' }}</div>
          </div>

          <!-- Expanded detail + edit -->
          <div v-if="expandedWlId === item.id"
            class="border-t border-surface-600 bg-surface-800 p-4 space-y-4">
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-x-6 gap-y-2 text-sm">
              <div v-if="item.home_assembly"><span class="text-ink-500">Assembly:</span> <span class="text-ink-200">{{ item.home_assembly }}</span></div>
              <div><span class="text-ink-500">Overflow:</span> <span class="text-ink-200">{{ item.overflow_willing }}</span></div>
              <div><span class="text-ink-500">Subscription:</span> <span class="text-ink-200">{{ item.subscription_willing }}</span></div>
              <div><span class="text-ink-500">Score:</span> <span class="text-ink-200">{{ item.score }}</span></div>
              <div><span class="text-ink-500">Global rank:</span> <span class="text-ink-200">#{{ rankMap[item.id] }} of {{ waitlistItems.length }}</span></div>
              <div v-if="item.created_at"><span class="text-ink-500">Submitted:</span> <span class="text-ink-200">{{ fmtDate(item.created_at) }}</span></div>
            </div>
            <div v-if="item.special_considerations" class="text-xs text-amber-400 bg-amber-500/10 rounded-lg px-3 py-2">⚠ {{ item.special_considerations }}</div>
            <div v-if="item.additional_comments" class="text-sm text-ink-400 bg-surface-700 rounded-lg px-3 py-2">{{ item.additional_comments }}</div>

            <!-- Edit controls -->
            <div class="flex flex-wrap gap-3 items-end pt-1">
              <div>
                <label class="field-label">Status</label>
                <select :value="item.status" @change="patchWl(item, 'status', $event.target.value)" class="text-sm">
                  <option v-for="s in wlStatuses" :key="s">{{ s }}</option>
                </select>
              </div>
              <div>
                <label class="field-label">Priority override</label>
                <select :value="item.priority_override || ''" @change="patchWl(item, 'priority_override', $event.target.value)" class="text-sm">
                  <option value="">Auto ({{ item.auto_priority }})</option>
                  <option v-for="p in wlPriorities" :key="p">{{ p }}</option>
                </select>
              </div>
              <button @click="deleteWlItem(item)" class="btn btn-ghost text-red-400 hover:text-red-300 btn-sm ml-auto">Delete</button>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- ── UNALLOCATED VIEW ───────────────────────────────────────────────── -->
    <template v-else-if="activeView === 'unallocated'">
      <div class="flex gap-2 items-center">
        <input v-model="unallocSearch" type="text" placeholder="Search household name…" class="flex-1 text-sm" />
        <span class="text-sm text-ink-500 shrink-0">{{ filteredUnalloc.length }} household{{ filteredUnalloc.length !== 1 ? 's' : '' }}</span>
      </div>
      <EmptyState v-if="!unassignedHouseholds.length" icon="✅" title="All households allocated"
        subtitle="Every household has at least one site." />
      <EmptyState v-else-if="!filteredUnalloc.length" icon="🔍" title="No matches" subtitle="Try a different search." />
      <div v-else class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
        <div v-for="h in filteredUnalloc" :key="h.id" class="card p-4 flex flex-col gap-2">
          <div class="font-semibold text-ink-100 truncate">{{ h.name }}</div>
          <div class="text-xs text-ink-500">👥 {{ h.member_count }} member{{ h.member_count !== 1 ? 's' : '' }}</div>
          <button @click="openAssignSite(h)" class="btn btn-primary btn-sm mt-auto">Assign to site →</button>
        </div>
      </div>
    </template>

    <!-- ── SITES VIEW ─────────────────────────────────────────────────────── -->
    <template v-else>

    <!-- Camp selector + search + type filter -->
    <div class="flex gap-2 flex-wrap items-center">
      <select v-model="selectedCampId" @change="load" class="text-sm w-52">
        <option :value="null">All camps</option>
        <option v-for="c in camps" :key="c.id" :value="c.id">{{ c.name }}</option>
      </select>
      <input v-model="search" type="text" placeholder="Search site # or notes…"
        class="flex-1 min-w-40 text-sm" @input="onSearch" />
      <select v-model="filterType" @change="load" class="text-sm w-36">
        <option value="">All types</option>
        <option value="caravan">Caravan</option>
        <option value="tent">Tent</option>
        <option value="cabin">Cabin</option>
        <option value="powered">Powered</option>
        <option value="general">General</option>
      </select>
    </div>

    <!-- Allocation filter tabs + summary -->
    <div class="flex items-center justify-between gap-4 flex-wrap">
      <div class="flex gap-1">
        <button v-for="f in allocFilters" :key="f.value"
          @click="filterAlloc = f.value; filterFee = 'all'; load()"
          :class="['btn btn-sm', filterAlloc === f.value ? 'btn-primary' : 'btn-ghost text-ink-400']">
          {{ f.label }}
        </button>
      </div>
      <div class="text-sm text-ink-500" v-if="summary">
        <template v-if="filterAlloc === 'inactive'">
          Inactive: <span class="text-ink-200 font-medium">{{ summary.total }}</span>
        </template>
        <template v-else>
          Total: <span class="text-ink-200 font-medium">{{ summary.total }}</span>
          <span class="mx-2 text-ink-700">|</span>
          Allocated: <span class="text-ink-200 font-medium">{{ summary.allocated }}</span>
          <span class="mx-2 text-ink-700">|</span>
          Free: <span class="text-emerald-400 font-medium">{{ summary.available }}</span>
        </template>
      </div>
    </div>

    <!-- Fee expiry filter tabs (only when viewing allocated active sites) -->
    <div v-if="filterAlloc !== 'available' && filterAlloc !== 'inactive'" class="flex gap-1 flex-wrap">
      <button v-for="f in feeFilters" :key="f.value"
        @click="filterFee = f.value; load()"
        :class="['btn btn-sm', filterFee === f.value ? 'btn-primary' : 'btn-ghost text-ink-400']">
        {{ f.label }}
        <span v-if="f.summaryKey && summary?.[f.summaryKey] !== undefined"
          class="ml-1 opacity-70 tabular-nums">{{ summary[f.summaryKey] }}</span>
      </button>
    </div>

    <LoadingSpinner v-if="loading" :full="true" />

    <EmptyState v-else-if="!sites.length" icon="🏠"
      :title="filterAlloc === 'inactive' ? 'No inactive sites' : 'No sites found'"
      :subtitle="filterAlloc === 'inactive' ? 'No sites have been marked as inactive.' : 'Add the physical sites available at camp.'" />

    <!-- Site grid -->
    <div v-else class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
      <div v-for="s in sites" :key="s.id"
        :class="[
          'card p-4 cursor-pointer hover:bg-surface-700 transition-colors flex flex-col gap-2',
          !s.is_active && 'opacity-50',
          s.fee_expiry_status === 'overdue_6m' ? 'ring-1 ring-red-500/50' :
          s.fee_expiry_status === 'overdue'    ? 'ring-1 ring-amber-500/40' : ''
        ]"
        @click="openDetail(s)">

        <!-- Row 1: site number + status badge -->
        <div class="flex items-start justify-between gap-2">
          <div class="font-bold text-ember-400 text-2xl leading-none">{{ s.site_number }}</div>
          <span :class="allocBadge(s)">{{ allocLabel(s) }}</span>
        </div>

        <!-- Row 2: type + power -->
        <div class="text-xs text-ink-500 flex items-center gap-2">
          <span :class="typeBadge(s.site_type)">{{ s.site_type || 'General' }}</span>
          <span v-if="+s.power" class="text-amber-400">⚡ Power</span>
          <span>👥 {{ s.capacity }}</span>
        </div>

        <!-- Row 3: household or empty -->
        <div v-if="s.household_name"
          class="text-sm font-semibold text-ink-100 leading-tight truncate">
          {{ s.household_name }}
        </div>
        <div v-else class="text-sm text-ink-600 italic">Empty</div>

        <!-- Row 4: nights + fee expiry (only if allocated) -->
        <div v-if="s.allocation_id" class="flex items-center justify-between gap-2 mt-auto pt-1 border-t border-surface-700">
          <span class="text-xs text-ink-500">
            <span v-if="s.camp_nights !== null" class="text-ink-300 font-medium">{{ s.camp_nights }} night{{ s.camp_nights !== 1 ? 's' : '' }}</span>
            <span v-else class="text-ink-600">No payment</span>
          </span>
          <span :class="feeExpiryBadge(s)">{{ feeExpiryLabel(s) }}</span>
        </div>

        <div v-if="s.notes" class="text-xs text-ink-600 truncate">{{ s.notes }}</div>
      </div>
    </div>

    <!-- ── Detail modal ──────────────────────────────────────────────────── -->
    <AppModal v-model="showDetail" :title="`Site ${detailData?.site?.site_number ?? ''}`" width="sm:max-w-2xl">
      <div v-if="detailLoading" class="py-8 text-center text-ink-500">Loading…</div>
      <div v-else-if="detailData" class="space-y-5">

        <!-- Site meta row -->
        <div class="flex items-center gap-3 flex-wrap">
          <span :class="typeBadge(detailData.site.site_type)">{{ detailData.site.site_type || 'General' }}</span>
          <span v-if="detailData.site.power" class="text-xs text-amber-400">⚡ Power</span>
          <span class="text-xs text-ink-500">👥 Capacity {{ detailData.site.capacity }}</span>
          <span v-if="detailData.site.notes" class="text-xs text-ink-500">{{ detailData.site.notes }}</span>
        </div>

        <!-- Allocation management -->
        <div class="space-y-3">
          <div class="flex items-center justify-between">
            <h3 class="text-sm font-semibold text-ink-300 uppercase tracking-wide">Household</h3>
            <span v-if="detailData.alloc" class="badge bg-sky-500/20 text-sky-300">Allocated</span>
            <span v-else class="badge bg-emerald-500/20 text-emerald-400">Empty</span>
          </div>

          <!-- Current household + members -->
          <div v-if="detailData.alloc" class="space-y-2">
            <div class="flex items-center justify-between gap-2">
              <div class="text-base font-bold text-ink-100">{{ detailData.alloc.household_name }}</div>
              <button @click="unassignCurrent" :disabled="allocSaving"
                class="btn btn-ghost btn-sm text-red-400 hover:text-red-300 shrink-0">Unassign</button>
            </div>
            <div class="grid grid-cols-2 gap-2">
              <div v-for="m in detailData.members" :key="m.id"
                class="flex items-center gap-2 bg-surface-800 rounded-lg px-3 py-2">
                <span :class="memberTypeBadge(m.member_type)" class="shrink-0">{{ memberTypeLabel(m.member_type) }}</span>
                <span class="text-sm text-ink-200 truncate">{{ m.first_name }} {{ m.last_name }}</span>
              </div>
            </div>
          </div>
          <div v-else class="text-sm text-ink-500 italic">No household assigned to this site.</div>

          <!-- Household picker -->
          <div class="pt-1 space-y-2">
            <label class="field-label">{{ detailData.alloc ? 'Change household' : 'Assign household' }}</label>
            <input v-model="hhSearch" type="text" placeholder="Search household name…" class="text-sm w-full" />
            <div class="max-h-52 overflow-y-auto rounded-lg border border-surface-600 divide-y divide-surface-700">
              <button v-for="h in filteredHouseholds" :key="h.id"
                @click="selectHousehold(h)"
                :class="['w-full text-left px-3 py-2 flex items-center justify-between gap-2 transition-colors',
                  pendingHousehold?.id === h.id ? 'bg-ember-500/15'
                    : h.id === currentHouseholdId ? 'bg-surface-800' : 'hover:bg-surface-700']">
                <span class="min-w-0 truncate">
                  <span class="text-sm text-ink-200">{{ h.name }}</span>
                  <span class="text-xs text-ink-600 ml-1">({{ h.member_count }})</span>
                  <span v-if="h.id === currentHouseholdId" class="text-[10px] text-ember-400 ml-1">· current</span>
                </span>
                <span v-if="h.sites.length" class="badge bg-surface-600 text-ink-400 shrink-0 text-[10px]">
                  Site {{ h.sites.join(', ') }}
                </span>
                <span v-else class="text-[10px] text-emerald-400 shrink-0">Unallocated</span>
              </button>
              <div v-if="!filteredHouseholds.length" class="px-3 py-3 text-sm text-ink-600 italic">No households match.</div>
            </div>

            <!-- Allocation notes -->
            <div v-if="pendingChanged || detailData.alloc">
              <label class="field-label">Allocation notes</label>
              <input v-model="allocNotes" type="text" placeholder="Optional notes for this allocation" class="text-sm w-full" />
            </div>

            <!-- Conflict resolution: household already on another site -->
            <div v-if="pendingConflict" class="card p-3 border border-amber-500/30 bg-amber-500/5 space-y-2">
              <div class="text-sm text-amber-400">
                <span class="font-semibold">{{ pendingHousehold.name }}</span> is already allocated to
                Site {{ pendingConflict.join(', ') }}.
              </div>
              <div class="flex flex-wrap gap-2">
                <button @click="commitAllocation('transfer')" :disabled="allocSaving" class="btn btn-primary btn-sm">
                  Transfer to Site {{ detailData.site.site_number }}
                </button>
                <button @click="commitAllocation('both')" :disabled="allocSaving"
                  class="btn btn-ghost btn-sm border border-surface-500">Keep on both sites</button>
                <button @click="pendingHousehold = null" :disabled="allocSaving"
                  class="btn btn-ghost btn-sm text-ink-400">Cancel</button>
              </div>
            </div>

            <!-- Plain assign (no conflict) -->
            <div v-else-if="pendingChanged" class="flex justify-end">
              <button @click="commitAllocation('')" :disabled="allocSaving" class="btn btn-primary btn-sm">
                {{ allocSaving ? 'Saving…' : (detailData.alloc ? `Change to ${pendingHousehold.name}` : `Assign ${pendingHousehold.name}`) }}
              </button>
            </div>
          </div>
        </div>

        <!-- Payment history -->
        <div v-if="detailData.alloc">
          <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-semibold text-ink-300 uppercase tracking-wide">Payment History</h3>
            <select v-if="detailData.payments.length" v-model="detailCampFilter" class="text-xs py-1 px-2">
              <option value="">All camps</option>
              <option v-for="c in detailCamps" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
          </div>
          <div v-if="!filteredDetailPayments.length" class="text-sm text-ink-600 italic">No payments recorded.</div>
          <div v-else class="space-y-2 max-h-72 overflow-y-auto pr-1">
            <div v-for="p in filteredDetailPayments" :key="p.id"
              class="bg-surface-800 rounded-lg px-4 py-3 space-y-2">
              <div class="flex items-start justify-between gap-2">
                <div>
                  <div class="text-sm font-semibold text-ink-100">${{ fmt(p.total) }}</div>
                  <div class="text-xs text-ember-400 font-medium">{{ p.camp_name }}</div>
                  <div class="text-xs text-ink-500">{{ fmtDate(p.payment_date) }}</div>
                </div>
                <div class="text-right text-xs text-ink-400 space-y-0.5">
                  <div v-if="p.camp_fee > 0">Camp fee: ${{ fmt(p.camp_fee) }}</div>
                  <div v-if="p.site_fee > 0">Site fee: ${{ fmt(p.site_fee) }}</div>
                  <div v-if="p.other_amount > 0">Other: ${{ fmt(p.other_amount) }}</div>
                  <div v-if="p.prepaid_applied > 0">Prepaid: −${{ fmt(p.prepaid_applied) }}</div>
                  <div v-if="p.arrival_date">{{ p.arrival_date }} → {{ p.departure_date }}</div>
                </div>
              </div>
              <div class="flex gap-2 flex-wrap text-xs">
                <span v-if="p.tender_eftpos > 0" class="text-sky-400">EFTPOS ${{ fmt(p.tender_eftpos) }}</span>
                <span v-if="p.tender_cash > 0" class="text-emerald-400">Cash ${{ fmt(p.tender_cash) }}</span>
                <span v-if="p.tender_bank > 0" class="text-purple-400">Bank ${{ fmt(p.tender_bank) }}</span>
              </div>
              <div v-if="p.notes" class="text-xs text-ink-500">{{ p.notes }}</div>
            </div>
          </div>
        </div>

      </div>
      <template #footer>
        <button v-if="detailData?.site" @click="openEditFromDetail"
          class="btn btn-ghost text-ink-300">Edit Site</button>
        <button @click="showDetail = false" class="btn btn-primary ml-auto">Close</button>
      </template>
    </AppModal>

    <!-- ── Edit modal ────────────────────────────────────────────────────── -->
    <AppModal v-model="showModal" :title="editing ? `Edit Site ${form.site_number}` : 'Add Site'">
      <form @submit.prevent="save" class="space-y-4">
        <div>
          <label class="field-label">Site Number *</label>
          <input v-model="form.site_number" type="text" required placeholder="e.g. 1, 2A, Cabin 3" />
        </div>
        <div>
          <label class="field-label">Type</label>
          <select v-model="form.site_type">
            <option value="">General</option>
            <option value="caravan">Caravan</option>
            <option value="tent">Tent</option>
            <option value="cabin">Cabin</option>
            <option value="powered">Powered</option>
          </select>
        </div>
        <div>
          <label class="field-label">Capacity (people)</label>
          <input v-model.number="form.capacity" type="number" min="1" max="30" />
        </div>
        <div class="flex items-center gap-3">
          <input v-model="form.power" type="checkbox" id="chk-power" class="w-4 h-4 accent-ember-500" />
          <label for="chk-power" class="text-sm text-ink-300">Has power connection</label>
        </div>
        <div class="flex items-center gap-3">
          <input v-model="form.is_active" type="checkbox" id="chk-active" class="w-4 h-4 accent-ember-500" />
          <label for="chk-active" class="text-sm text-ink-300">Active site <span class="text-ink-600">(uncheck to mark as inactive / decommissioned)</span></label>
        </div>
        <div>
          <label class="field-label">Notes</label>
          <textarea v-model="form.notes" rows="2" class="resize-none"
            placeholder="Any relevant details about this site…" />
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

    </template><!-- end sites view -->

    <!-- ── Assign-to-site modal (from Unallocated tab) ────────────────────── -->
    <AppModal v-model="showAssignSite" :title="`Assign ${assignCtx?.household_name ?? ''}`" width="sm:max-w-lg">
      <div class="space-y-3">
        <input v-model="siteSearch" type="text" placeholder="Search site # or type…" class="text-sm w-full" />
        <div class="max-h-72 overflow-y-auto rounded-lg border border-surface-600 divide-y divide-surface-700">
          <button v-for="s in filteredVacantSites" :key="s.site_id"
            @click="assignToSite(s)" :disabled="allocSaving"
            class="w-full text-left px-3 py-2 flex items-center justify-between gap-2 hover:bg-surface-700 transition-colors">
            <span class="font-semibold text-ink-100">{{ s.site_number }}</span>
            <span class="text-xs text-ink-500 flex items-center gap-2">
              <span :class="typeBadge(s.site_type)">{{ s.site_type || 'General' }}</span>
              <span v-if="s.power" class="text-amber-400">⚡</span>
              <span>👥 {{ s.capacity }}</span>
            </span>
          </button>
          <div v-if="!filteredVacantSites.length" class="px-3 py-3 text-sm text-ink-600 italic">No vacant sites match.</div>
        </div>
      </div>
      <template #footer>
        <button @click="showAssignSite = false" class="btn btn-ghost ml-auto">Close</button>
      </template>
    </AppModal>

  </div>
</template>

<script setup>
import { ref, computed, inject, onMounted } from 'vue'
import { api } from '@/api.js'
import AppModal from '@/components/AppModal.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import EmptyState from '@/components/EmptyState.vue'

const toast = inject('toast')

const loading          = ref(true)
const saving           = ref(false)
const showModal        = ref(false)
const showDetail       = ref(false)
const detailLoading    = ref(false)
const detailData       = ref(null)
const detailCampFilter = ref('')
const editing          = ref(null)
const sites            = ref([])
const summary          = ref(null)
const search           = ref('')
const filterType     = ref('')
const filterAlloc    = ref('all')
const filterFee      = ref('all')
const camps          = ref([])
const selectedCampId = ref(null)

// ── Waitlist state ────────────────────────────────────────────────────────────
const activeView       = ref('sites')
const wlLoading        = ref(false)
const waitlistItems    = ref([])
const expandedWlId     = ref(null)
const wlStatusFilter   = ref('All')
const wlSortBy         = ref('rank')   // 'rank' | 'date'
const wlStatuses       = ['Active', 'Contacted', 'Allocated', 'Archived']
const wlPriorities     = ['High', 'Medium', 'Low']
const wlStatusFilters  = ['All', 'Active', 'Contacted', 'Allocated', 'Archived']

// ── Allocation state (merged from the former Allocation page) ───────────────────
const allocRows            = ref([])    // every site → household row (perpetual)
const unassignedHouseholds = ref([])    // households with no site
const hhSearch             = ref('')    // household picker search (site detail)
const pendingHousehold     = ref(null)  // staged household selection in site detail
const allocNotes           = ref('')    // notes for the allocation being edited
const allocSaving          = ref(false)
const unallocSearch        = ref('')    // Unallocated tab search
const showAssignSite       = ref(false) // Unallocated → pick-a-site modal
const assignCtx            = ref(null)  // { household_id, household_name }
const siteSearch           = ref('')    // vacant-site picker search

const viewTabs = computed(() => [
  { value: 'sites',       label: 'Sites',       count: 0 },
  { value: 'unallocated', label: 'Unallocated', count: unassignedHouseholds.value.length },
  { value: 'waitlist',    label: 'Waitlist',    count: waitlistActiveCount.value },
])
const viewSubtitle = computed(() => ({
  sites:       'Physical site registry',
  unallocated: 'Households without a site',
  waitlist:    'Site allocation waitlist',
}[activeView.value] ?? ''))

// Deduped household list (with the site numbers each one occupies) for the picker
const allHouseholds = computed(() => {
  const map = new Map()
  for (const a of allocRows.value) {
    if (!a.household_id) continue
    if (!map.has(a.household_id))
      map.set(a.household_id, { id: a.household_id, name: a.household_name, member_count: a.member_count, sites: [] })
    map.get(a.household_id).sites.push(a.site_number)
  }
  for (const u of unassignedHouseholds.value) {
    if (!map.has(u.id))
      map.set(u.id, { id: u.id, name: u.name, member_count: u.member_count, sites: [] })
  }
  return [...map.values()].sort((a, b) => String(a.name).localeCompare(String(b.name)))
})
const filteredHouseholds = computed(() => {
  const q = hhSearch.value.trim().toLowerCase()
  return q ? allHouseholds.value.filter(h => String(h.name).toLowerCase().includes(q)) : allHouseholds.value
})
const filteredUnalloc = computed(() => {
  const q = unallocSearch.value.trim().toLowerCase()
  return q ? unassignedHouseholds.value.filter(h => String(h.name).toLowerCase().includes(q)) : unassignedHouseholds.value
})
const vacantSites = computed(() => allocRows.value.filter(a => !a.household_id))
const filteredVacantSites = computed(() => {
  const q = siteSearch.value.trim().toLowerCase()
  if (!q) return vacantSites.value
  return vacantSites.value.filter(s =>
    String(s.site_number).toLowerCase().includes(q) || String(s.site_type ?? '').toLowerCase().includes(q))
})

const currentSiteNumber  = computed(() => detailData.value?.site?.site_number)
const currentHouseholdId = computed(() => detailData.value?.alloc?.household_id ?? null)
const pendingChanged     = computed(() =>
  !!pendingHousehold.value && pendingHousehold.value.id !== currentHouseholdId.value)
// Other sites the staged household already occupies (→ requires user resolution)
const pendingConflict = computed(() => {
  if (!pendingChanged.value) return null
  const others = (pendingHousehold.value.sites || [])
    .filter(sn => String(sn) !== String(currentSiteNumber.value))
  return others.length ? others : null
})

const detailCamps = computed(() => {
  if (!detailData.value?.payments) return []
  const seen = new Map()
  detailData.value.payments.forEach(p => { if (!seen.has(p.camp_id)) seen.set(p.camp_id, { id: p.camp_id, name: p.camp_name }) })
  return [...seen.values()]
})

const filteredDetailPayments = computed(() => {
  if (!detailData.value?.payments) return []
  if (!detailCampFilter.value) return detailData.value.payments
  return detailData.value.payments.filter(p => p.camp_id === detailCampFilter.value)
})

const waitlistActiveCount = computed(() => waitlistItems.value.filter(i => i.status === 'Active').length)

// Rank is always score-based across the full list, regardless of current filter/sort
const rankMap = computed(() => {
  const sorted = [...waitlistItems.value].sort((a, b) =>
    b.score - a.score || new Date(a.created_at) - new Date(b.created_at)
  )
  const map = {}
  sorted.forEach((item, i) => { map[item.id] = i + 1 })
  return map
})

const filteredWaitlist = computed(() => {
  const items = wlStatusFilter.value === 'All' ? waitlistItems.value
    : waitlistItems.value.filter(i => i.status === wlStatusFilter.value)
  return [...items].sort((a, b) => {
    if (wlSortBy.value === 'date') return new Date(a.created_at) - new Date(b.created_at)
    return b.score - a.score || new Date(a.created_at) - new Date(b.created_at)
  })
})

function wlPriorityBadge(p) {
  return { High: 'badge bg-red-500/20 text-red-400', Medium: 'badge bg-amber-500/20 text-amber-400', Low: 'badge bg-surface-600 text-ink-500' }[p] || 'badge bg-surface-600 text-ink-500'
}
function wlStatusBadge(s) {
  return { Active: 'badge bg-sky-500/20 text-sky-300', Contacted: 'badge bg-purple-500/20 text-purple-300', Allocated: 'badge bg-emerald-500/20 text-emerald-400', Archived: 'badge bg-surface-600 text-ink-500' }[s] || 'badge bg-surface-600 text-ink-500'
}
function rankBubble(item) {
  const r = rankMap.value[item.id]
  if (r <= 3)  return 'bg-ember-500/20 text-ember-400'
  if (r <= 8)  return 'bg-amber-500/15 text-amber-400'
  return 'bg-surface-600 text-ink-400'
}

async function loadWaitlist() {
  wlLoading.value = true
  try { waitlistItems.value = await api.waitlist.list() } catch {}
  wlLoading.value = false
}

async function patchWl(item, field, value) {
  try {
    const res = await api.waitlist.update(item.id, { [field]: value })
    if (res.item) {
      const idx = waitlistItems.value.findIndex(i => i.id === item.id)
      if (idx !== -1) waitlistItems.value[idx] = res.item
    }
  } catch { toast?.add('Update failed', 'error') }
}

async function deleteWlItem(item) {
  if (!confirm(`Remove ${item.first_name} ${item.last_name} from the waitlist?`)) return
  try {
    await api.waitlist.delete(item.id)
    waitlistItems.value = waitlistItems.value.filter(i => i.id !== item.id)
    if (expandedWlId.value === item.id) expandedWlId.value = null
    toast?.add('Removed from waitlist', 'success')
  } catch { toast?.add('Delete failed', 'error') }
}

const allocFilters = [
  { value: 'all',       label: 'All' },
  { value: 'allocated', label: 'Allocated' },
  { value: 'available', label: 'Available' },
  { value: 'inactive',  label: 'Inactive' },
]

const feeFilters = [
  { value: 'all',        label: 'Fee: All',      summaryKey: null },
  { value: 'current',    label: 'Fee OK',         summaryKey: 'fee_current' },
  { value: 'overdue',    label: 'Overdue',        summaryKey: 'fee_overdue' },
  { value: 'overdue_6m', label: '6m+ Overdue',    summaryKey: 'fee_overdue_6m' },
  { value: 'unknown',    label: 'Unknown',        summaryKey: 'fee_unknown' },
]

let searchTimer = null
const blank = () => ({ site_number: '', site_type: '', power: false, capacity: 6, notes: '', is_active: true })
const form = ref(blank())

// ── Badges ────────────────────────────────────────────────────────────────────
function typeBadge(type) {
  return {
    caravan: 'badge bg-sky-500/20 text-sky-400',
    tent:    'badge bg-emerald-500/20 text-emerald-400',
    cabin:   'badge bg-amber-500/20 text-amber-400',
    powered: 'badge bg-purple-500/20 text-purple-400',
  }[type] || 'badge bg-surface-600 text-ink-400'
}
function allocBadge(s)  {
  if (!s.is_active) return 'badge bg-surface-600 text-ink-600'
  return s.allocation_id ? 'badge bg-sky-500/20 text-sky-300' : 'badge bg-emerald-500/20 text-emerald-400'
}
function allocLabel(s)  {
  if (!s.is_active) return 'Inactive'
  return s.allocation_id ? 'Allocated' : 'Available'
}

function feeExpiryBadge(s) {
  const st = s.fee_expiry_status
  if (st === 'current')    return 'badge bg-emerald-500/20 text-emerald-400'
  if (st === 'overdue')    return 'badge bg-amber-500/20 text-amber-400'
  if (st === 'overdue_6m') return 'badge bg-red-500/20 text-red-400'
  return 'badge bg-surface-600 text-ink-600'
}
function feeExpiryLabel(s) {
  const st  = s.fee_expiry_status
  const exp = s.site_fee_expires
  if (!st) return ''
  if (st === 'unknown') return 'Not set'
  if (!exp) return ''
  const d = new Date(exp + 'T00:00:00')
  if (st === 'current') {
    return 'Due ' + d.toLocaleDateString('en-AU', { day: 'numeric', month: 'short', year: 'numeric' })
  }
  const days = Math.round((Date.now() - d.getTime()) / 86400000)
  return `${days}d overdue`
}
function memberTypeBadge(t) { return { adult: 'badge bg-sky-500/20 text-sky-400', youth: 'badge bg-purple-500/20 text-purple-400', child: 'badge bg-emerald-500/20 text-emerald-400', infant: 'badge bg-surface-600 text-ink-400' }[t] || 'badge bg-surface-600 text-ink-400' }
function memberTypeLabel(t) { return t ? t.charAt(0).toUpperCase() + t.slice(1) : 'Unknown' }
function tenderBadge(m)  { return { eftpos: 'bg-sky-500/20 text-sky-300', cash: 'bg-emerald-500/20 text-emerald-400', bank: 'bg-purple-500/20 text-purple-300', other: 'bg-surface-600 text-ink-400' }[m] || 'bg-surface-600 text-ink-400' }

// ── Formatting ────────────────────────────────────────────────────────────────
function fmt(n)     { return Number(n ?? 0).toFixed(2) }
function fmtDate(d) { return d ? new Date(d).toLocaleDateString('en-AU', { day: 'numeric', month: 'short', year: 'numeric' }) : '' }

// ── Data loading ──────────────────────────────────────────────────────────────
async function loadCamps() {
  try {
    const res = await api.camps.list()
    camps.value = Array.isArray(res) ? res : (res.camps ?? [])
    // Default stays null (All camps) — user can filter by specific camp if needed
  } catch {}
}

async function load() {
  loading.value = true
  try {
    const res = await api.sites.list({
      search:     search.value,
      type:       filterType.value,
      filter:     filterAlloc.value,
      fee_filter: filterFee.value,
      camp_id:    selectedCampId.value ?? '',
    })
    if (Array.isArray(res)) {
      sites.value   = res
      summary.value = null
    } else {
      sites.value   = res.sites   ?? []
      summary.value = res.summary ?? null
    }
  } catch {}
  loading.value = false
}

function onSearch() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => load(), 350)
}

// ── Detail modal ──────────────────────────────────────────────────────────────
async function openDetail(s) {
  detailData.value    = null
  detailLoading.value = true
  detailCampFilter.value = ''
  pendingHousehold.value = null
  hhSearch.value      = ''
  allocNotes.value    = ''
  showDetail.value    = true
  try {
    detailData.value = await api.get('/site/detail', { site_id: s.id })
    allocNotes.value = detailData.value?.alloc?.notes ?? ''
  } catch {}
  detailLoading.value = false
}

// ── Allocation actions ──────────────────────────────────────────────────────────
async function loadAllocations() {
  try {
    const res = await api.siteAllocations.list()
    allocRows.value            = res.allocations          ?? []
    unassignedHouseholds.value = res.unassigned_households ?? []
  } catch {}
}

function switchView(v) {
  activeView.value = v
  if (v === 'waitlist' && !waitlistItems.value.length) loadWaitlist()
}

function selectHousehold(h) {
  pendingHousehold.value = pendingHousehold.value?.id === h.id ? null : h
}

async function refreshAfterAlloc(siteId) {
  await Promise.all([loadAllocations(), load()])
  try {
    detailData.value = await api.get('/site/detail', { site_id: siteId })
    allocNotes.value = detailData.value?.alloc?.notes ?? ''
  } catch {}
}

async function commitAllocation(mode = '') {
  const site = detailData.value?.site
  const h    = pendingHousehold.value
  if (!site || !h) return
  allocSaving.value = true
  try {
    await api.siteAllocations.create({
      site_id:      site.id,
      household_id: h.id,
      notes:        allocNotes.value,
      conflict:     mode,
    })
    toast?.add('Household allocated', 'success')
    pendingHousehold.value = null
    hhSearch.value = ''
    await refreshAfterAlloc(site.id)
  } catch (e) {
    toast?.add(e?.data?.message || 'Allocation failed', 'error')
  } finally {
    allocSaving.value = false
  }
}

async function unassignCurrent() {
  const allocId = detailData.value?.alloc?.id
  const siteId  = detailData.value?.site?.id
  if (!allocId || !siteId) return
  if (!confirm('Unassign this household from the site?')) return
  allocSaving.value = true
  try {
    await api.siteAllocations.delete(allocId)
    toast?.add('Site unassigned', 'success')
    await refreshAfterAlloc(siteId)
  } catch {
    toast?.add('Failed to unassign', 'error')
  } finally {
    allocSaving.value = false
  }
}

// Unallocated tab → pick a vacant site for a household
function openAssignSite(h) {
  assignCtx.value = { household_id: h.id, household_name: h.name }
  siteSearch.value = ''
  showAssignSite.value = true
}
async function assignToSite(siteRow) {
  if (!assignCtx.value) return
  allocSaving.value = true
  try {
    await api.siteAllocations.create({
      site_id:      siteRow.site_id,
      household_id: assignCtx.value.household_id,
      notes:        '',
      conflict:     '',
    })
    toast?.add('Household allocated', 'success')
    showAssignSite.value = false
    await Promise.all([loadAllocations(), load()])
  } catch (e) {
    toast?.add(e?.data?.message || 'Allocation failed', 'error')
  } finally {
    allocSaving.value = false
  }
}

function openEditFromDetail() {
  const s = detailData.value?.site
  if (!s) return
  showDetail.value = false
  editing.value = s
  form.value = { ...blank(), ...s, power: !!s.power, is_active: s.is_active !== false }
  showModal.value = true
}

// ── Edit modal ────────────────────────────────────────────────────────────────
function openNew()   { editing.value = null; form.value = blank(); showModal.value = true }

async function save() {
  saving.value = true
  try {
    if (editing.value) {
      await api.sites.update(editing.value.id, form.value)
      toast?.add('Site updated', 'success')
    } else {
      await api.sites.create(form.value)
      toast?.add('Site added', 'success')
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
  if (!confirm(`Delete site ${form.value.site_number}?`)) return
  try {
    await api.sites.delete(editing.value.id)
    toast?.add('Site deleted', 'success')
    showModal.value = false
    await load()
  } catch {
    toast?.add('Delete failed', 'error')
  }
}

onMounted(async () => {
  await loadCamps()
  await Promise.all([load(), loadAllocations()])
})
</script>
