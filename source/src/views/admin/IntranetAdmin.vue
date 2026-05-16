<template>
  <div class="p-6 max-w-4xl mx-auto space-y-6">
    <div>
      <h1 class="text-2xl font-bold text-ink-100">Intranet Admin</h1>
      <p class="text-sm text-ink-500 mt-0.5">Manage content shown in the Campo phone app</p>
    </div>

    <!-- Between Camps mode -->
    <div class="card p-4 space-y-3">
      <div class="flex items-center justify-between">
        <div>
          <div class="font-semibold text-ink-200 text-sm">App Mode</div>
          <div class="text-xs text-ink-500 mt-0.5">Controls what campers see when they open the Campo app</div>
        </div>
        <label class="flex items-center gap-2 cursor-pointer select-none">
          <div class="relative">
            <input type="checkbox" v-model="betweenCampsMode" class="sr-only peer" />
            <div class="w-10 h-6 bg-surface-700 peer-checked:bg-ember-500 rounded-full transition-colors"></div>
            <div class="absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform peer-checked:translate-x-4"></div>
          </div>
          <span class="text-sm font-medium" :class="betweenCampsMode ? 'text-ember-400' : 'text-ink-400'">
            {{ betweenCampsMode ? 'Between Camps' : 'Camp Mode' }}
          </span>
        </label>
      </div>
      <div v-if="betweenCampsMode" class="space-y-2">
        <label class="field-label">Nightly Fee Payment URL</label>
        <input v-model="betweenCampsCheckoutUrl" type="url" placeholder="https://..." class="w-full" />
        <p class="text-xs text-ink-500">Campers will see a button linking to this URL for nightly fee payments.</p>
      </div>
      <div class="flex justify-end">
        <button @click="saveBetweenCampsMode" :disabled="savingMode" class="btn btn-primary btn-sm">
          {{ savingMode ? 'Saving…' : 'Save Mode' }}
        </button>
      </div>
    </div>

    <!-- Camp selector -->
    <div class="card p-4 flex items-center gap-3">
      <label class="text-sm text-ink-400 flex-none">Camp</label>
      <select v-model="selectedCampId" @change="loadTab(activeTab)" class="flex-1 text-sm">
        <option v-for="c in camps" :key="c.id" :value="c.id">
          {{ c.name }}{{ c.status === 'active' ? ' (active)' : '' }}
        </option>
      </select>
    </div>

    <!-- Tabs -->
    <div class="flex gap-1 p-1 bg-surface-800 rounded-xl w-fit flex-wrap">
      <button v-for="t in tabs" :key="t.key" @click="switchTab(t.key)"
        class="px-4 py-2 rounded-lg text-sm font-medium transition-all"
        :class="activeTab === t.key ? 'bg-surface-600 text-ink-100' : 'text-ink-500 hover:text-ink-300'">
        {{ t.icon }} {{ t.label }}
      </button>
    </div>

    <!-- Program tab -->
    <div v-if="activeTab === 'program'" class="space-y-4">
      <div class="flex items-center justify-between gap-3">
        <div class="text-sm font-semibold text-ink-300 uppercase tracking-wide">Program</div>
        <div class="flex items-center gap-2">
          <button @click="openImport" class="btn btn-ghost btn-sm" :disabled="!selectedCampId">Upload CSV</button>
          <button @click="openNewSession" class="btn btn-primary btn-sm" :disabled="!selectedCampId">+ Add Session</button>
        </div>
      </div>
      <LoadingSpinner v-if="loading" />
      <EmptyState v-else-if="!sessionsByDay.length && !sessions.length" icon="📋" title="No sessions"
        subtitle="Add sessions to build the camp program." />
      <div v-else class="overflow-x-auto -mx-6 px-6 pb-2">
        <div class="flex gap-3" :style="`min-width: ${sessionsByDay.length * 180}px`">
          <div v-for="(day, idx) in sessionsByDay" :key="day.date || 'unscheduled'" class="w-44 flex-none space-y-2">
            <!-- Day header -->
            <div class="px-1 pb-1 border-b border-surface-700">
              <div class="text-xs font-bold text-ink-200 uppercase tracking-wide">
                {{ day.date ? `Day ${idx + 1}` : 'Unscheduled' }}
              </div>
              <div class="text-xs text-ink-500 mt-0.5">{{ day.label }}</div>
            </div>
            <!-- Session cards -->
            <div v-for="s in day.sessions" :key="s.id"
                 class="card p-2.5 cursor-pointer hover:bg-surface-700 transition-colors"
                 @click="openEditSession(s)">
              <div class="flex items-start gap-2">
                <div class="w-7 h-7 rounded-lg flex items-center justify-center text-sm flex-none leading-none"
                     :class="sessionTypeIcon(s.session_type).bg">
                  {{ sessionTypeIcon(s.session_type).icon }}
                </div>
                <div class="min-w-0 flex-1">
                  <div class="font-medium text-ink-200 text-xs leading-snug">{{ s.title }}</div>
                  <div v-if="s.start_time" class="text-xs text-ember-400 mt-0.5 tabular-nums">
                    {{ s.start_time }}<span v-if="s.end_time"> – {{ s.end_time }}</span>
                  </div>
                  <div v-if="s.location" class="text-xs text-ink-500 truncate mt-0.5">{{ s.location }}</div>
                </div>
              </div>
            </div>
            <!-- Empty day placeholder -->
            <div v-if="!day.sessions.length"
                 class="rounded-xl border border-dashed border-surface-700 py-5 text-center text-xs text-ink-700">
              empty
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Notices tab -->
    <div v-if="activeTab === 'notices'" class="space-y-4">
      <div class="flex items-center justify-between gap-3">
        <div class="text-sm font-semibold text-ink-300 uppercase tracking-wide">Notices</div>
        <button @click="openNewNotice" class="btn btn-primary btn-sm" :disabled="!selectedCampId">+ Post Notice</button>
      </div>
      <LoadingSpinner v-if="loading" />
      <EmptyState v-else-if="!notices.length" icon="📌" title="No notices"
        subtitle="Post the first notice for this camp." />
      <div v-else class="space-y-2">
        <div v-for="n in notices" :key="n.id" class="card p-4 flex items-start gap-3 cursor-pointer hover:bg-surface-700 transition-colors"
          @click="openEditNotice(n)">
          <div class="flex-1 min-w-0">
            <div class="font-medium text-ink-200">{{ n.title }}</div>
            <div class="text-sm text-ink-400 mt-0.5 line-clamp-2">{{ n.message }}</div>
            <div class="text-xs text-ink-500 mt-1 flex gap-2 flex-wrap">
              <span v-if="n.author_name">{{ n.author_name }}</span>
              <span v-if="n.site_number">Site {{ n.site_number }}</span>
              <span>{{ formatDate(n.created_at) }}</span>
            </div>
          </div>
          <div class="flex flex-col gap-1 flex-none items-end">
            <span v-if="n.category" class="badge bg-surface-600 text-ink-400 text-xs">{{ n.category }}</span>
            <span :class="n.status === 'approved' ? 'bg-emerald-500/15 text-emerald-400' : 'bg-amber-500/15 text-amber-400'"
              class="badge capitalize text-xs">{{ n.status }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Polls tab -->
    <div v-if="activeTab === 'polls'" class="space-y-4">
      <div class="flex items-center justify-between gap-3">
        <div class="text-sm font-semibold text-ink-300 uppercase tracking-wide">Polls</div>
        <button @click="openNewPoll" class="btn btn-primary btn-sm" :disabled="!selectedCampId">+ Create Poll</button>
      </div>
      <LoadingSpinner v-if="loading" />
      <EmptyState v-else-if="!polls.length" icon="📊" title="No polls"
        subtitle="Create a poll for camp attendees to vote on." />
      <div v-else class="space-y-2">
        <div v-for="p in polls" :key="p.id" class="card p-4 flex items-start justify-between gap-3">
          <div class="flex-1 min-w-0 cursor-pointer" @click="openEditPoll(p)">
            <div class="font-medium text-ink-200">{{ p.title }}</div>
            <div v-if="p.options_text" class="text-xs text-ink-500 mt-0.5">
              {{ p.options_text.split('\n').filter(Boolean).length }} options
            </div>
          </div>
          <div class="flex gap-2 flex-none items-center">
            <span :class="p.status === 'live' ? 'bg-emerald-500/15 text-emerald-400' : p.status === 'draft' ? 'bg-surface-600 text-ink-500' : 'bg-amber-500/15 text-amber-400'"
              class="badge capitalize">{{ p.status }}</span>
            <button @click="togglePoll(p)" class="btn btn-ghost btn-sm">
              {{ p.status === 'live' ? 'Close' : 'Go Live' }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Lost & Found tab -->
    <div v-if="activeTab === 'lostandfound'" class="space-y-4">
      <div class="text-sm font-semibold text-ink-300 uppercase tracking-wide">Lost &amp; Found</div>
      <LoadingSpinner v-if="loading" />
      <EmptyState v-else-if="!lostFound.length" icon="🔍" title="No items"
        subtitle="Items submitted by attendees via the phone app will appear here." />
      <div v-else class="space-y-2">
        <div v-for="item in lostFound" :key="item.id" class="card p-4 flex items-start justify-between gap-3">
          <div>
            <div class="flex items-center gap-2">
              <span class="font-medium text-ink-200">{{ item.title }}</span>
              <span :class="item.item_type === 'lost' ? 'bg-red-500/15 text-red-400' : 'bg-emerald-500/15 text-emerald-400'"
                class="badge capitalize">{{ item.item_type }}</span>
            </div>
            <div class="text-xs text-ink-500 mt-0.5">
              <span v-if="item.reporter_name">{{ item.reporter_name }}</span>
              <span v-if="item.location_details"> · {{ item.location_details }}</span>
              <span> · {{ formatDate(item.created_at) }}</span>
            </div>
            <div v-if="item.description" class="text-sm text-ink-400 mt-1">{{ item.description }}</div>
          </div>
          <div class="flex-none">
            <span v-if="item.status === 'returned' || item.status === 'resolved'" class="badge bg-surface-600 text-ink-500">Resolved</span>
            <button v-else @click="resolveItem(item)" class="btn btn-ghost btn-sm">Resolve</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Site Updates tab -->
    <div v-if="activeTab === 'siteupdates'" class="space-y-4">
      <div class="text-sm font-semibold text-ink-300 uppercase tracking-wide">Site Detail Updates</div>
      <LoadingSpinner v-if="loading" />
      <EmptyState v-else-if="!siteUpdates.length" icon="📝" title="No site updates"
        subtitle="Site detail update requests from Campo will appear here." />
      <div v-else class="space-y-2">
        <div v-for="item in siteUpdates" :key="item.id" class="card p-4 flex items-start justify-between gap-3">
          <div class="min-w-0">
            <div class="font-medium text-ink-200">
              {{ item.member_first_name }} {{ item.member_last_name }}
              <span class="text-ink-500">· Site {{ item.site_number }}</span>
            </div>
            <div class="text-xs text-ink-500 mt-1 flex gap-2 flex-wrap">
              <span v-if="item.phone_number">{{ item.phone_number }}</span>
              <span v-if="item.email">{{ item.email }}</span>
              <span>{{ formatDate(item.created_at) }}</span>
            </div>
            <div v-if="item.other_members" class="text-sm text-ink-400 mt-1">{{ item.other_members }}</div>
            <div v-if="item.verification_note" class="text-xs text-ink-500 mt-1">{{ item.verification_note }}</div>
          </div>
          <div class="flex flex-col gap-2 flex-none items-end">
            <span class="badge capitalize" :class="item.status === 'resolved' ? 'bg-emerald-500/15 text-emerald-400' : 'bg-amber-500/15 text-amber-400'">{{ item.status }}</span>
            <button v-if="item.status !== 'resolved'" @click="updateSiteUpdateStatus(item, 'resolved')" class="btn btn-ghost btn-sm">Resolve</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Check-ins tab -->
    <div v-if="activeTab === 'checkins'" class="space-y-4">
      <div class="text-sm font-semibold text-ink-300 uppercase tracking-wide">Self Check-ins</div>
      <LoadingSpinner v-if="loading" />
      <EmptyState v-else-if="!checkIns.length" icon="✅" title="No check-ins"
        subtitle="Self check-ins from Campo will appear here." />
      <div v-else class="space-y-2">
        <div v-for="item in checkIns" :key="item.id" class="card p-4 flex items-start justify-between gap-3">
          <div class="min-w-0">
            <div class="font-medium text-ink-200">
              {{ item.submitter_name }}
              <span class="text-ink-500">· Site {{ item.site_number }}</span>
            </div>
            <div class="text-xs text-ink-500 mt-1 flex gap-2 flex-wrap">
              <span>{{ item.arrival_date }} - {{ item.departure_date }}</span>
              <span>{{ item.adults_count }} adults</span>
              <span>{{ item.kids_count }} kids</span>
              <span v-if="item.is_day_trip">Day trip</span>
            </div>
            <div class="text-xs text-ink-500 mt-1 flex gap-2 flex-wrap">
              <span v-if="item.phone_number">{{ item.phone_number }}</span>
              <span v-if="item.email">{{ item.email }}</span>
              <span v-if="item.site_type">{{ item.site_type }}</span>
            </div>
            <div v-if="item.verification_note" class="text-xs text-ink-500 mt-1">{{ item.verification_note }}</div>
          </div>
          <div class="flex flex-col gap-2 flex-none items-end">
            <span class="badge capitalize" :class="item.status === 'resolved' ? 'bg-emerald-500/15 text-emerald-400' : 'bg-amber-500/15 text-amber-400'">{{ item.status }}</span>
            <button v-if="item.status !== 'resolved'" @click="updateCheckInStatus(item, 'resolved')" class="btn btn-ghost btn-sm">Resolve</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Accounts tab -->
    <div v-if="activeTab === 'accounts'" class="space-y-4">
      <div class="flex items-center justify-between gap-3">
        <div>
          <div class="text-sm font-semibold text-ink-300 uppercase tracking-wide">Camper Accounts</div>
          <p class="text-xs text-ink-500 mt-0.5">People who have signed into the Campo app with their email.</p>
        </div>
        <button @click="loadAccounts" class="btn btn-ghost btn-sm text-ink-500">Refresh</button>
      </div>

      <LoadingSpinner v-if="loadingAccounts" />
      <EmptyState v-else-if="!campoUsers.length" icon="👤" title="No accounts yet"
        subtitle="Users will appear here once they sign into Campo with their email." />

      <div v-else class="space-y-2">
        <div v-for="u in campoUsers" :key="u.id" class="card p-4 space-y-2">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <div class="flex items-center gap-2 flex-wrap">
                <span class="font-medium text-ink-200 text-sm">{{ u.name || '(no name)' }}</span>
                <span class="text-xs text-ink-500">{{ u.email }}</span>
                <span v-if="u.active_sessions > 0"
                  class="badge bg-emerald-500/15 text-emerald-400 text-xs">
                  {{ u.active_sessions }} active session{{ u.active_sessions > 1 ? 's' : '' }}
                </span>
              </div>
              <div class="text-xs text-ink-500 mt-1 flex gap-3 flex-wrap">
                <span v-if="u.phone">{{ u.phone }}</span>
                <span>Joined {{ formatDate(u.created_at) }}</span>
                <span v-if="u.last_login_at">Last seen {{ formatDate(u.last_login_at) }}</span>
              </div>
            </div>
            <button @click="confirmDeleteUser(u)" class="btn btn-ghost btn-sm text-red-400 hover:text-red-300 flex-none">Delete</button>
          </div>

          <!-- Household link -->
          <div class="flex items-center gap-2 pt-1 border-t border-surface-700 flex-wrap">
            <span class="text-xs text-ink-500 flex-none">Household:</span>
            <template v-if="u.household_id">
              <span class="text-xs font-medium text-ink-300">
                {{ u.household_name }}
                <span v-if="u.site_number" class="text-ink-500"> · Site {{ u.site_number }}</span>
              </span>
              <button @click="unlinkHousehold(u)" class="btn btn-ghost btn-sm text-xs text-ink-500 ml-auto">Unlink</button>
            </template>
            <template v-else>
              <span class="text-xs text-amber-400">Not linked</span>
              <div class="ml-auto flex items-center gap-1.5">
                <input
                  v-model="u._searchQ"
                  @input="searchHouseholds(u)"
                  type="text"
                  placeholder="Search by name or site…"
                  class="text-xs py-1 px-2 w-44 rounded-lg bg-surface-700 border border-surface-600 text-ink-300 placeholder-ink-600"
                />
                <div v-if="u._searchResults?.length" class="absolute z-10 mt-1 w-52 bg-surface-700 rounded-xl border border-surface-600 shadow-xl overflow-hidden">
                  <button
                    v-for="h in u._searchResults"
                    :key="h.id"
                    class="w-full text-left px-3 py-2 text-xs hover:bg-surface-600 text-ink-300"
                    @click="linkHousehold(u, h)"
                  >
                    {{ h.name }}<span v-if="h.site_number" class="text-ink-500"> · Site {{ h.site_number }}</span>
                  </button>
                </div>
              </div>
            </template>
          </div>
        </div>
      </div>
    </div>

    <!-- Email tab -->
    <div v-if="activeTab === 'email'" class="space-y-4">
      <div class="text-sm font-semibold text-ink-300 uppercase tracking-wide">Email / SMTP Settings</div>
      <p class="text-xs text-ink-500 -mt-2">Used for OTP sign-in codes and notification emails sent from the Campo app.</p>

      <div v-if="mailStatus !== null"
        class="flex items-center gap-2 rounded-xl p-3 text-xs font-medium"
        :class="mailStatus.configured ? 'bg-green-500/10 text-green-400' : 'bg-amber-500/10 text-amber-300'">
        <span class="w-2 h-2 rounded-full flex-none" :class="mailStatus.configured ? 'bg-green-400' : 'bg-amber-400'" />
        {{ mailStatus.configured ? 'SMTP is configured and ready.' : 'SMTP needs configuration before emails can be sent.' }}
      </div>
      <div v-if="mailStatus && !mailStatus.configured && mailStatus.issues?.length"
        class="rounded-xl bg-amber-500/10 border border-amber-500/20 p-3 text-xs text-amber-300 space-y-0.5">
        <p v-for="issue in mailStatus.issues" :key="issue">• {{ issue }}</p>
      </div>

      <LoadingSpinner v-if="loadingMail" />
      <template v-else>
        <div class="card p-4 space-y-3">
          <div class="grid grid-cols-2 gap-3">
            <div class="col-span-2 sm:col-span-1">
              <label class="field-label">SMTP Host</label>
              <input v-model="mailForm.mail_host" type="text" placeholder="smtp.hostinger.com" autocomplete="off" />
            </div>
            <div>
              <label class="field-label">Port</label>
              <input v-model="mailForm.mail_port" type="number" placeholder="587" min="1" max="65535" />
            </div>
            <div>
              <label class="field-label">Encryption</label>
              <select v-model="mailForm.mail_encryption">
                <option value="tls">STARTTLS (587)</option>
                <option value="ssl">SSL/TLS (465)</option>
                <option value="">None</option>
              </select>
            </div>
            <div>
              <label class="field-label">Username</label>
              <input v-model="mailForm.mail_username" type="text" autocomplete="off" placeholder="you@yourdomain.com" />
            </div>
            <div>
              <label class="field-label">Password</label>
              <input v-model="mailForm.mail_password" type="password" autocomplete="new-password"
                :placeholder="mailHasPassword ? 'Leave blank to keep current' : 'Enter SMTP password'" />
            </div>
            <div>
              <label class="field-label">From Name</label>
              <input v-model="mailForm.mail_from_name" type="text" placeholder="Campo" />
            </div>
            <div>
              <label class="field-label">From Email</label>
              <input v-model="mailForm.mail_from_email" type="email" placeholder="campo@yourdomain.com" />
            </div>
          </div>
          <div class="flex items-center gap-2 flex-wrap pt-1">
            <button @click="saveMail" :disabled="savingMail" class="btn btn-primary btn-sm">
              {{ savingMail ? 'Saving…' : 'Save Settings' }}
            </button>
            <input v-model="testEmailAddr" type="email" placeholder="Send test to…" class="text-sm py-1.5 px-3 w-48" />
            <button @click="testMail" :disabled="testingMail || !testEmailAddr" class="btn btn-secondary btn-sm">
              {{ testingMail ? 'Sending…' : 'Send Test' }}
            </button>
          </div>
        </div>
      </template>
    </div>

    <!-- Session modal -->
    <AppModal v-model="showSessionModal" :title="editingSession ? 'Edit Session' : 'Add Session'">
      <form @submit.prevent="saveSession" class="space-y-4">
        <div>
          <label class="field-label">Title *</label>
          <input v-model="sessionForm.title" required type="text" />
        </div>

        <!-- Camp day checkboxes (new session only) -->
        <div v-if="!editingSession && campDays.length" class="space-y-2">
          <div class="flex items-center justify-between">
            <label class="field-label mb-0">Apply to camp days</label>
            <div class="flex gap-3 text-xs">
              <button type="button" @click="sessionForm.selected_days = campDays.map(d => d.date)"
                class="text-ember-400 hover:text-ember-300 transition-colors">All</button>
              <button type="button" @click="sessionForm.selected_days = []"
                class="text-ink-500 hover:text-ink-400 transition-colors">Clear</button>
            </div>
          </div>
          <div class="flex flex-wrap gap-2">
            <label v-for="day in campDays" :key="day.date"
              class="flex items-center gap-1.5 cursor-pointer select-none text-sm rounded-lg px-2.5 py-1.5 transition-colors"
              :class="sessionForm.selected_days.includes(day.date)
                ? 'bg-ember-500/20 text-ember-300 ring-1 ring-ember-500/40'
                : 'bg-surface-700 text-ink-300 hover:bg-surface-600'">
              <input type="checkbox" :value="day.date" v-model="sessionForm.selected_days" class="sr-only" />
              {{ day.label }}
            </label>
          </div>
          <p v-if="sessionForm.selected_days.length" class="text-xs text-ink-500">
            {{ sessionForm.selected_days.length }} day{{ sessionForm.selected_days.length > 1 ? 's' : '' }} selected
          </p>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="field-label">Start time</label>
            <input v-model="sessionForm.start_time" type="time" />
          </div>
          <div>
            <label class="field-label">End time</label>
            <input v-model="sessionForm.end_time" type="time" />
          </div>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div class="min-w-0">
            <label class="field-label">Location</label>
            <input v-model="sessionForm.location" type="text" class="w-full" />
          </div>
          <div class="min-w-0">
            <label class="field-label">Type</label>
            <select v-model="sessionForm.session_type" class="w-full">
              <option value="general">General</option>
              <option value="meeting">Meeting</option>
              <option value="meal">Meal</option>
              <option value="activity">Activity</option>
              <option value="free_time">Free Time</option>
              <option value="other">Other</option>
            </select>
          </div>
        </div>
        <div>
          <label class="field-label">Description</label>
          <textarea v-model="sessionForm.description" rows="2" class="resize-none" />
        </div>
      </form>
      <template #footer>
        <button v-if="editingSession" @click="deleteSession"
          class="btn btn-ghost text-red-400 hover:text-red-300 mr-auto">Delete</button>
        <button @click="showSessionModal = false" class="btn btn-ghost">Cancel</button>
        <button @click="saveSession" :disabled="saving" class="btn btn-primary">
          {{ saving ? 'Saving…' : (!editingSession && sessionForm.selected_days.length > 1 ? `Create ${sessionForm.selected_days.length} Sessions` : 'Save') }}
        </button>
      </template>
    </AppModal>

    <!-- CSV import modal -->
    <AppModal v-model="showImportModal" title="Upload Schedule">
      <div class="space-y-4">
        <div>
          <label class="field-label">Camp</label>
          <select v-model="importCampId" class="w-full text-sm">
            <option v-for="c in camps" :key="c.id" :value="c.id">
              {{ c.name }}{{ c.status === 'active' ? ' (active)' : '' }}
            </option>
          </select>
        </div>
        <div class="rounded-lg bg-amber-500/10 border border-amber-500/30 p-3 text-sm text-amber-300 leading-snug">
          This will <strong>replace all existing sessions</strong> for the selected camp. This action cannot be undone.
        </div>
        <div class="text-sm text-ink-400">
          Not sure of the format?
          <a href="/api/intranet/sessions/template"
             class="text-ember-400 hover:text-ember-300 underline"
             download>Download the CSV template</a>
          <span class="text-ink-600 ml-1 text-xs">(columns: title, date, start_time, end_time, location, session_type, description)</span>
        </div>
        <div>
          <label class="field-label">CSV File</label>
          <input type="file" accept=".csv,text/csv,text/plain" @change="handleImportFile"
                 class="w-full text-sm text-ink-400 file:mr-3 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-surface-600 file:text-ink-300 hover:file:bg-surface-500 cursor-pointer" />
        </div>
        <div v-if="importErrors.length" class="rounded-lg bg-red-500/10 border border-red-500/30 p-3 space-y-1">
          <div class="text-xs font-semibold text-red-400">Rows skipped during import:</div>
          <ul class="text-xs text-red-300 space-y-0.5 list-disc list-inside">
            <li v-for="e in importErrors" :key="e">{{ e }}</li>
          </ul>
        </div>
      </div>
      <template #footer>
        <button @click="showImportModal = false" class="btn btn-ghost">Cancel</button>
        <button @click="runImport" :disabled="importing || !importCampId || !importFile" class="btn btn-primary">
          {{ importing ? 'Importing…' : 'Upload Schedule' }}
        </button>
      </template>
    </AppModal>

    <!-- Notice modal -->
    <AppModal v-model="showNoticeModal" :title="editingNotice ? 'Edit Notice' : 'Post Notice'">
      <form @submit.prevent="saveNotice" class="space-y-4">
        <div>
          <label class="field-label">Title *</label>
          <input v-model="noticeForm.title" required type="text" />
        </div>
        <div>
          <label class="field-label">Message *</label>
          <textarea v-model="noticeForm.message" required rows="4" class="resize-none" />
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="field-label">Category</label>
            <select v-model="noticeForm.category">
              <option>General</option>
              <option>Announcement</option>
              <option>For Sale</option>
              <option>Wanted</option>
              <option>Event</option>
              <option>Prayer Request</option>
            </select>
          </div>
          <div>
            <label class="field-label">Status</label>
            <select v-model="noticeForm.status">
              <option value="approved">Approved</option>
              <option value="pending">Pending review</option>
            </select>
          </div>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="field-label">Author name</label>
            <input v-model="noticeForm.author_name" type="text" placeholder="Optional" />
          </div>
          <div>
            <label class="field-label">Site number</label>
            <input v-model="noticeForm.site_number" type="text" placeholder="Optional" />
          </div>
        </div>
        <div>
          <label class="field-label">Expires (optional)</label>
          <input v-model="noticeForm.expires_at" type="datetime-local" />
        </div>
      </form>
      <template #footer>
        <button v-if="editingNotice" @click="deleteNotice"
          class="btn btn-ghost text-red-400 hover:text-red-300 mr-auto">Delete</button>
        <button @click="showNoticeModal = false" class="btn btn-ghost">Cancel</button>
        <button @click="saveNotice" :disabled="saving" class="btn btn-primary">
          {{ saving ? 'Saving…' : 'Save' }}
        </button>
      </template>
    </AppModal>

    <!-- Poll modal -->
    <AppModal v-model="showPollModal" :title="editingPoll ? 'Edit Poll' : 'Create Poll'">
      <form @submit.prevent="savePoll" class="space-y-4">
        <div>
          <label class="field-label">Title *</label>
          <input v-model="pollForm.title" required type="text" />
        </div>
        <div>
          <label class="field-label">Description</label>
          <textarea v-model="pollForm.description" rows="2" class="resize-none" />
        </div>
        <div>
          <label class="field-label">Options (one per line)</label>
          <textarea v-model="pollForm.optionsText" rows="4" class="resize-none"
            :placeholder="editingPoll ? 'Leave blank to keep existing options' : 'Option A\nOption B\nOption C'" />
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="field-label">Status</label>
            <select v-model="pollForm.status">
              <option value="draft">Draft</option>
              <option value="live">Live</option>
              <option value="closed">Closed</option>
            </select>
          </div>
          <div class="flex items-center gap-2 pt-5">
            <input v-model="pollForm.show_results_public" type="checkbox" id="show_results" class="w-4 h-4" />
            <label for="show_results" class="text-sm text-ink-300">Show results publicly</label>
          </div>
        </div>
      </form>
      <template #footer>
        <button v-if="editingPoll" @click="deletePoll"
          class="btn btn-ghost text-red-400 hover:text-red-300 mr-auto">Delete</button>
        <button @click="showPollModal = false" class="btn btn-ghost">Cancel</button>
        <button @click="savePoll" :disabled="saving" class="btn btn-primary">
          {{ saving ? 'Saving…' : (editingPoll ? 'Save' : 'Create Poll') }}
        </button>
      </template>
    </AppModal>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { api, intranet as intranetApi, settings as settingsApi } from '@/api.js'
import AppModal from '@/components/AppModal.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import EmptyState from '@/components/EmptyState.vue'
import { inject } from 'vue'

const toast = inject('toast')

const camps          = ref([])
const selectedCampId = ref(null)

const betweenCampsMode       = ref(false)
const betweenCampsCheckoutUrl = ref('')
const savingMode             = ref(false)

const tabs = [
  { key: 'program',      icon: '📋', label: 'Program' },
  { key: 'notices',      icon: '📌', label: 'Notices' },
  { key: 'polls',        icon: '📊', label: 'Polls' },
  { key: 'lostandfound', icon: '🔍', label: 'Lost & Found' },
  { key: 'siteupdates',  icon: '📝', label: 'Site Updates' },
  { key: 'checkins',     icon: '✅', label: 'Check-ins' },
  { key: 'accounts',     icon: '👤',  label: 'Accounts' },
  { key: 'email',        icon: '✉️',  label: 'Email' },
]
const activeTab = ref('program')
const loading   = ref(false)
const saving    = ref(false)

const sessions  = ref([])
const notices   = ref([])
const polls     = ref([])
const lostFound = ref([])
const siteUpdates = ref([])
const checkIns = ref([])

// Modals
const showSessionModal = ref(false)
const showNoticeModal  = ref(false)
const showPollModal    = ref(false)
const editingSession   = ref(null)
const editingNotice    = ref(null)
const editingPoll      = ref(null)

const blankSession = () => ({ title: '', date: '', start_time: '', end_time: '', location: '', description: '', session_type: 'general', selected_days: [] })
const blankNotice  = () => ({ title: '', message: '', category: 'General', status: 'approved', expires_at: '', author_name: '', site_number: '' })
const blankPoll    = () => ({ title: '', description: '', optionsText: '', status: 'draft', show_results_public: false })

const sessionForm = ref(blankSession())
const noticeForm  = ref(blankNotice())
const pollForm    = ref(blankPoll())

const SESSION_TYPE_LABELS = {
  general: 'General', meeting: 'Meeting', meal: 'Meal',
  activity: 'Activity', free_time: 'Free Time', other: 'Other',
  worship: 'Worship', speaker: 'Speaker', meals: 'Meals', free: 'Free',
}
function sessionTypeLabel(t) { return SESSION_TYPE_LABELS[t] || t }

const SESSION_TYPE_ICONS = {
  general:   { icon: '📋', bg: 'bg-surface-600 text-ink-400' },
  meeting:   { icon: '📖', bg: 'bg-blue-500/20 text-blue-300' },
  meal:      { icon: '🍽️', bg: 'bg-amber-500/20 text-amber-300' },
  activity:  { icon: '⚡', bg: 'bg-green-500/20 text-green-300' },
  free_time: { icon: '🌿', bg: 'bg-teal-500/20 text-teal-300' },
  other:     { icon: '✦',  bg: 'bg-surface-600 text-ink-500' },
  worship:   { icon: '🙏', bg: 'bg-purple-500/20 text-purple-300' },
  speaker:   { icon: '🎤', bg: 'bg-blue-500/20 text-blue-300' },
  meals:     { icon: '🍽️', bg: 'bg-amber-500/20 text-amber-300' },
  free:      { icon: '🌿', bg: 'bg-teal-500/20 text-teal-300' },
}
function sessionTypeIcon(t) { return SESSION_TYPE_ICONS[t] ?? SESSION_TYPE_ICONS.other }

const campDays = computed(() => {
  const camp = camps.value.find(c => c.id === selectedCampId.value)
  if (!camp?.start_date || !camp?.end_date) return []
  const days = []
  const cur = new Date(camp.start_date + 'T00:00:00')
  const end = new Date(camp.end_date + 'T00:00:00')
  while (cur <= end) {
    const iso = cur.toISOString().slice(0, 10)
    const label = cur.toLocaleDateString('en-AU', { weekday: 'short', day: 'numeric', month: 'short' })
    days.push({ date: iso, label })
    cur.setDate(cur.getDate() + 1)
  }
  return days
})

const sessionsByDay = computed(() => {
  const dayMap = {}
  campDays.value.forEach((day, i) => {
    dayMap[day.date] = { date: day.date, label: day.label, dayNum: i + 1, sessions: [] }
  })
  const unscheduled = []
  for (const s of sessions.value) {
    if (s.date && dayMap[s.date]) {
      dayMap[s.date].sessions.push(s)
    } else {
      unscheduled.push(s)
    }
  }
  const result = Object.values(dayMap)
  result.forEach(d => d.sessions.sort((a, b) => (a.start_time || '').localeCompare(b.start_time || '')))
  if (unscheduled.length) result.push({ date: null, label: 'No date set', dayNum: null, sessions: unscheduled })
  return result
})

watch(() => sessionForm.value.start_time, (newTime) => {
  if (!newTime || sessionForm.value.end_time) return
  const [h, m] = newTime.split(':').map(Number)
  sessionForm.value.end_time = `${String((h + 1) % 24).padStart(2, '0')}:${String(m).padStart(2, '0')}`
})

function formatDate(d) {
  if (!d) return ''
  return new Date(d).toLocaleDateString('en-AU', { day: 'numeric', month: 'short', year: 'numeric' })
}

async function loadCamps() {
  const res = await api.camps.list()
  camps.value = res
  const active = res.find(c => c.status === 'active')
  selectedCampId.value = active?.id ?? res[0]?.id ?? null
}

async function loadBetweenCampsMode() {
  try {
    const res = await intranetApi.get()
    betweenCampsMode.value       = !!res.content?.between_camps_mode
    betweenCampsCheckoutUrl.value = res.content?.between_camps_checkout_url || ''
  } catch {}
}

async function saveBetweenCampsMode() {
  savingMode.value = true
  try {
    await intranetApi.save({
      between_camps_mode:        betweenCampsMode.value ? 1 : 0,
      between_camps_checkout_url: betweenCampsCheckoutUrl.value.trim(),
    })
    toast?.add('App mode saved', 'success')
  } catch { toast?.add('Failed to save', 'error') }
  finally { savingMode.value = false }
}

async function loadTab(tab) {
  if (!selectedCampId.value) return
  loading.value = true
  try {
    const cid = selectedCampId.value
    if (tab === 'program')      sessions.value  = await api.intranetAdmin.program(cid)
    if (tab === 'notices')      notices.value   = await api.intranetAdmin.notices(cid)
    if (tab === 'polls')        polls.value     = await api.intranetAdmin.polls(cid)
    if (tab === 'lostandfound') lostFound.value = await api.intranetAdmin.lostFound(cid)
    if (tab === 'siteupdates' || tab === 'checkins') {
      const features = await api.intranetAdmin.features()
      siteUpdates.value = features.site_updates || []
      checkIns.value = features.check_ins || []
    }
  } catch {}
  loading.value = false
}

// CSV import
const showImportModal = ref(false)
const importCampId    = ref(null)
const importFile      = ref(null)
const importing       = ref(false)
const importErrors    = ref([])

function openImport() {
  importCampId.value = selectedCampId.value
  importFile.value   = null
  importErrors.value = []
  showImportModal.value = true
}

function handleImportFile(e) {
  importFile.value   = e.target.files?.[0] ?? null
  importErrors.value = []
}

async function runImport() {
  if (!importCampId.value || !importFile.value) return
  importing.value = true
  try {
    const fd = new FormData()
    fd.append('camp_id', importCampId.value)
    fd.append('file', importFile.value)
    const res = await api.intranetAdmin.importSessions(fd)
    const msg = res.errors?.length
      ? `${res.imported} imported, ${res.errors.length} skipped`
      : `${res.imported} session${res.imported !== 1 ? 's' : ''} imported`
    toast?.add(msg, 'success')
    if (res.errors?.length) {
      importErrors.value = res.errors
    } else {
      showImportModal.value = false
    }
    if (importCampId.value === selectedCampId.value) await loadTab('program')
  } catch (e) {
    toast?.add(e?.data?.message || 'Import failed', 'error')
  } finally {
    importing.value = false
  }
}

// Session CRUD
function openNewSession()   { editingSession.value = null; sessionForm.value = blankSession(); showSessionModal.value = true }
function openEditSession(s) { editingSession.value = s; sessionForm.value = { ...blankSession(), ...s }; showSessionModal.value = true }

async function saveSession() {
  saving.value = true
  try {
    const { selected_days, ...formData } = sessionForm.value
    const base = { ...formData, camp_id: selectedCampId.value }
    if (editingSession.value) {
      await api.intranetAdmin.updateSession(editingSession.value.id, base)
    } else if (selected_days.length) {
      await Promise.all(selected_days.map(date => api.intranetAdmin.createSession({ ...base, date })))
      toast?.add(`${selected_days.length} session${selected_days.length > 1 ? 's' : ''} created`, 'success')
      showSessionModal.value = false
      await loadTab('program')
      return
    } else {
      await api.intranetAdmin.createSession(base)
    }
    toast?.add('Saved', 'success')
    showSessionModal.value = false
    await loadTab('program')
  } catch (e) { toast?.add(e?.data?.message || 'Failed', 'error') }
  finally { saving.value = false }
}

async function deleteSession() {
  if (!confirm('Delete this session?')) return
  try {
    await api.intranetAdmin.deleteSession(editingSession.value.id)
    toast?.add('Deleted', 'success')
    showSessionModal.value = false
    await loadTab('program')
  } catch (e) { toast?.add(e?.data?.message || 'Failed', 'error') }
}

// Notice CRUD
function openNewNotice()   { editingNotice.value = null; noticeForm.value = blankNotice(); showNoticeModal.value = true }
function openEditNotice(n) { editingNotice.value = n; noticeForm.value = { ...blankNotice(), ...n }; showNoticeModal.value = true }

async function saveNotice() {
  saving.value = true
  try {
    const payload = { ...noticeForm.value, camp_id: selectedCampId.value, expires_at: noticeForm.value.expires_at || null }
    if (editingNotice.value) {
      await api.intranetAdmin.updateNotice(editingNotice.value.id, payload)
    } else {
      await api.intranetAdmin.createNotice(payload)
    }
    toast?.add('Saved', 'success')
    showNoticeModal.value = false
    await loadTab('notices')
  } catch (e) { toast?.add(e?.data?.message || 'Failed', 'error') }
  finally { saving.value = false }
}

async function deleteNotice() {
  if (!confirm('Delete this notice?')) return
  try {
    await api.intranetAdmin.deleteNotice(editingNotice.value.id)
    toast?.add('Deleted', 'success')
    showNoticeModal.value = false
    await loadTab('notices')
  } catch (e) { toast?.add(e?.data?.message || 'Failed', 'error') }
}

// Poll CRUD
function openNewPoll()   { editingPoll.value = null; pollForm.value = blankPoll(); showPollModal.value = true }
function openEditPoll(p) {
  editingPoll.value = p
  pollForm.value = { title: p.title, description: p.description || '', optionsText: '', status: p.status, show_results_public: !!parseInt(p.show_results_public || 0) }
  showPollModal.value = true
}

async function savePoll() {
  saving.value = true
  try {
    const options = pollForm.value.optionsText
      ? pollForm.value.optionsText.split('\n').map(s => s.trim()).filter(Boolean)
      : []
    const payload = {
      camp_id: selectedCampId.value,
      title: pollForm.value.title,
      description: pollForm.value.description,
      options,
      status: pollForm.value.status,
      show_results_public: pollForm.value.show_results_public ? 1 : 0,
    }
    if (editingPoll.value) {
      await api.intranetAdmin.updatePoll(editingPoll.value.id, payload)
    } else {
      await api.intranetAdmin.createPoll(payload)
    }
    toast?.add(editingPoll.value ? 'Poll updated' : 'Poll created', 'success')
    showPollModal.value = false
    await loadTab('polls')
  } catch (e) { toast?.add(e?.data?.message || 'Failed', 'error') }
  finally { saving.value = false }
}

async function deletePoll() {
  if (!confirm('Delete this poll?')) return
  try {
    await api.intranetAdmin.deletePoll(editingPoll.value.id)
    toast?.add('Deleted', 'success')
    showPollModal.value = false
    await loadTab('polls')
  } catch (e) { toast?.add(e?.data?.message || 'Failed', 'error') }
}

async function togglePoll(p) {
  try {
    const newStatus = p.status === 'live' ? 'closed' : 'live'
    await api.intranetAdmin.updatePoll(p.id, { title: p.title, status: newStatus })
    p.status = newStatus
    toast?.add(newStatus === 'live' ? 'Poll is now live' : 'Poll closed', 'success')
  } catch (e) { toast?.add(e?.data?.message || 'Failed', 'error') }
}

async function resolveItem(item) {
  try {
    await api.intranetAdmin.resolveItem(item.id)
    item.status = 'returned'
    toast?.add('Marked as resolved', 'success')
  } catch (e) { toast?.add(e?.data?.message || 'Failed', 'error') }
}

async function updateSiteUpdateStatus(item, status) {
  try {
    await api.intranetAdmin.updateSiteUpdate(item.id, { status, admin_notes: item.admin_notes || '' })
    item.status = status
    toast?.add('Updated', 'success')
  } catch (e) { toast?.add(e?.data?.message || 'Failed', 'error') }
}

async function updateCheckInStatus(item, status) {
  try {
    await api.intranetAdmin.updateCheckIn(item.id, { status, admin_notes: item.admin_notes || '' })
    item.status = status
    toast?.add('Updated', 'success')
  } catch (e) { toast?.add(e?.data?.message || 'Failed', 'error') }
}

// ── Mail settings ────────────────────────────────────────────────────────────
const loadingMail     = ref(false)
const savingMail      = ref(false)
const testingMail     = ref(false)
const testEmailAddr   = ref('')
const mailHasPassword = ref(false)
const mailStatus      = ref(null)
const mailForm        = ref({
  mail_host: '', mail_port: 587, mail_encryption: 'tls',
  mail_username: '', mail_password: '',
  mail_from_name: 'Campo', mail_from_email: '',
})

async function loadMailSettings() {
  loadingMail.value = true
  try {
    const res = await settingsApi.getMail()
    mailHasPassword.value = res.has_password || false
    mailStatus.value = { configured: res.configured, issues: res.issues || [] }
    mailForm.value = {
      mail_host:       res.host        || '',
      mail_port:       res.port        || 587,
      mail_encryption: res.encryption  || 'tls',
      mail_username:   res.username    || '',
      mail_password:   '',
      mail_from_name:  res.from_name   || 'Campo',
      mail_from_email: res.from_email  || '',
    }
  } catch {}
  loadingMail.value = false
}

async function saveMail() {
  savingMail.value = true
  try {
    await settingsApi.saveMail(mailForm.value)
    toast?.add('Mail settings saved', 'success')
    await loadMailSettings()
  } catch (e) {
    toast?.add(e?.data?.message || 'Save failed', 'error')
  } finally { savingMail.value = false }
}

async function testMail() {
  if (!testEmailAddr.value) return
  testingMail.value = true
  try {
    await settingsApi.testMail(testEmailAddr.value)
    toast?.add(`Test email sent to ${testEmailAddr.value}`, 'success')
  } catch (e) {
    toast?.add(e?.data?.message || 'Send failed — check your SMTP settings', 'error')
  } finally { testingMail.value = false }
}

onMounted(async () => {
  await Promise.all([loadCamps(), loadBetweenCampsMode()])
  await loadTab(activeTab.value)
})

function switchTab(key) {
  activeTab.value = key
  if (key === 'email') { loadMailSettings(); return }
  loadTab(key)
}
</script>
