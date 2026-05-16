<template>
  <div class="p-6 max-w-3xl mx-auto space-y-6">
    <div>
      <h1 class="text-2xl font-bold text-ink-100">Settings</h1>
      <p class="text-sm text-ink-500 mt-0.5">Users and account management</p>
    </div>

    <!-- Users -->
    <div class="card p-5 space-y-4">
      <div class="flex items-center justify-between gap-3">
        <div class="section-label">Admin Users</div>
        <button @click="openNewUser" class="btn btn-primary btn-sm">+ Add User</button>
      </div>
      <LoadingSpinner v-if="loadingUsers" />
      <div v-else class="divide-y divide-surface-700">
        <div v-for="u in users" :key="u.id"
          class="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
          <div class="w-8 h-8 rounded-xl flex items-center justify-center flex-none text-xs font-bold"
            :class="u.activation_pending ? 'bg-amber-500/15 text-amber-400' : 'bg-surface-600 text-ember-400'">
            {{ (u.username || '?')[0].toUpperCase() }}
          </div>
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
              <span class="text-sm font-medium text-ink-200">{{ u.username }}</span>
              <span v-if="u.activation_pending"
                class="text-xs px-1.5 py-0.5 rounded bg-amber-500/15 text-amber-400 font-medium">
                Pending activation
              </span>
            </div>
            <div class="text-xs text-ink-500 capitalize">{{ u.role?.replace(/_/g, ' ') }}</div>
          </div>
          <div class="flex items-center gap-2 flex-none">
            <span v-if="u.id === auth.user?.id" class="text-xs text-ink-500">You</span>
            <button v-if="u.activation_pending" @click="resendActivation(u)"
              :disabled="resendingId === u.id"
              class="btn btn-ghost btn-sm text-amber-400 hover:text-amber-300">
              {{ resendingId === u.id ? 'Sending…' : 'Resend' }}
            </button>
            <button v-else-if="auth.user?.role === 'full_admin' && u.id !== auth.user?.id"
              @click="sendReset(u)"
              :disabled="sendResetId === u.id"
              :title="u.email ? 'Send password reset link to ' + u.email : 'No email address on file'"
              :class="u.email ? 'text-ink-400 hover:text-ink-200' : 'opacity-40 cursor-not-allowed'"
              class="btn btn-ghost btn-sm text-xs">
              {{ sendResetId === u.id ? 'Sending…' : 'Reset pwd' }}
            </button>
            <button @click="openEditUser(u)" class="btn btn-ghost btn-sm">Edit</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Change own password -->
    <div class="card p-5 space-y-4">
      <div class="section-label">Change Password</div>
      <form @submit.prevent="changePassword" class="space-y-4">
        <div>
          <label class="field-label">Current Password</label>
          <input v-model="pwForm.current" type="password" autocomplete="current-password" />
        </div>
        <div>
          <label class="field-label">New Password</label>
          <input v-model="pwForm.next" type="password" autocomplete="new-password" />
        </div>
        <div>
          <label class="field-label">Confirm New Password</label>
          <input v-model="pwForm.confirm" type="password" autocomplete="new-password" />
        </div>
        <p v-if="pwError" class="text-red-400 text-sm">{{ pwError }}</p>
        <button type="submit" :disabled="savingPw" class="btn btn-primary">
          {{ savingPw ? 'Updating…' : 'Update Password' }}
        </button>
      </form>
    </div>

    <!-- ChurchSuite Integration -->
    <div class="card p-5 space-y-4">
      <div class="flex items-center justify-between gap-3">
        <div>
          <div class="section-label">ChurchSuite Integration</div>
          <p class="text-xs text-ink-500 mt-0.5">Sync members, households and camp attendees from ChurchSuite</p>
        </div>
        <LoadingSpinner v-if="csLoading" class="w-5 h-5" />
        <span v-else-if="csStatus?.connected"
          class="flex items-center gap-1.5 text-xs font-medium text-green-400 bg-green-400/10 px-2.5 py-1 rounded-full">
          <span class="w-1.5 h-1.5 rounded-full bg-green-400 inline-block"></span> Connected
        </span>
        <span v-else class="flex items-center gap-1.5 text-xs font-medium text-ink-500 bg-surface-700 px-2.5 py-1 rounded-full">
          <span class="w-1.5 h-1.5 rounded-full bg-ink-600 inline-block"></span> Not connected
        </span>
      </div>

      <div v-if="csStatus?.issues?.length" class="rounded-xl bg-red-500/10 border border-red-500/20 p-3 space-y-1">
        <p v-for="issue in csStatus.issues" :key="issue" class="text-xs text-red-300">⚠ {{ issue }}</p>
      </div>

      <div v-if="csStatus" class="grid grid-cols-2 gap-3 text-xs">
        <div class="bg-surface-700 rounded-xl p-3">
          <div class="text-ink-500 mb-0.5">Client ID</div>
          <div class="text-ink-300 font-mono">{{ csStatus.client_id_hint }}</div>
        </div>
        <div class="bg-surface-700 rounded-xl p-3">
          <div class="text-ink-500 mb-0.5">Redirect URI</div>
          <div class="text-ink-300 font-mono truncate">{{ csStatus.redirect_uri }}</div>
        </div>
      </div>

      <div class="flex gap-2">
        <button v-if="!csStatus?.connected" @click="connectChurchSuite"
          class="btn btn-primary">
          Connect ChurchSuite
        </button>
        <button v-else @click="disconnectChurchSuite" :disabled="csDisconnecting"
          class="btn btn-ghost text-red-400 hover:text-red-300">
          {{ csDisconnecting ? 'Disconnecting…' : 'Disconnect' }}
        </button>
        <button @click="loadCsStatus" class="btn btn-ghost btn-sm text-ink-500">Refresh</button>
      </div>

      <div v-if="!csStatus?.connected" class="rounded-xl bg-surface-700 p-3 text-xs text-ink-500 space-y-1">
        <p class="font-medium text-ink-400">Before connecting:</p>
        <p>1. In ChurchSuite → API → OAuth Applications, open the Campo app (client ID: <span class="font-mono text-ink-300">{{ csStatus?.client_id_hint }}</span>)</p>
        <p>2. Add this redirect URI: <span class="font-mono text-ink-300">{{ csStatus?.redirect_uri }}</span></p>
        <p>3. Then click Connect above.</p>
      </div>
    </div>

    <!-- Directory Sync (only when connected) -->
    <div v-if="csStatus?.connected" class="card p-5 space-y-4">
      <div>
        <div class="section-label">Directory Sync</div>
        <p class="text-xs text-ink-500 mt-0.5">Pull all people and families from ChurchSuite into Campo members &amp; households</p>
      </div>
      <div v-if="dirSyncing" class="rounded-xl border border-ink-700 bg-ink-800 p-3 text-xs text-ink-300 space-y-1">
        <p class="font-medium">⟳ Syncing… ({{ dirSyncProcessed }} processed)</p>
        <p v-if="dirSyncStage">Stage: {{ dirSyncStage }}</p>
      </div>
      <div v-else-if="dirSyncResult" :class="dirSyncResult.success ? 'bg-green-500/10 border-green-500/20 text-green-300' : 'bg-red-500/10 border-red-500/20 text-red-300'"
        class="rounded-xl border p-3 text-xs space-y-1">
        <p class="font-medium">{{ dirSyncResult.success ? '✓ Sync complete' : '✗ Sync failed' }}</p>
        <p v-if="dirSyncResult.message">{{ dirSyncResult.message }}</p>
        <template v-if="dirSyncResult.summary">
          <p v-for="(val, key) in dirSyncResult.summary" :key="key">{{ formatSyncKey(key) }}: {{ val }}</p>
        </template>
      </div>
      <div class="flex gap-2">
        <button @click="runDirectorySync" :disabled="dirSyncing || spouseSyncing" class="btn btn-primary">
          {{ dirSyncing ? 'Syncing…' : 'Sync Members & Households' }}
        </button>
        <button @click="fillMissingSpouses" :disabled="dirSyncing || spouseSyncing" class="btn btn-secondary"
          title="Fetch any contacts skipped by the list API (e.g. male spouses)">
          {{ spouseSyncing ? 'Fetching…' : 'Fill Missing Members' }}
        </button>
      </div>
      <div v-if="spouseSyncResult" :class="spouseSyncResult.success ? 'bg-green-500/10 border-green-500/20 text-green-300' : 'bg-red-500/10 border-red-500/20 text-red-300'"
        class="rounded-xl border p-3 text-xs space-y-1">
        <p class="font-medium">{{ spouseSyncResult.success ? '✓ Done' : '✗ Failed' }}</p>
        <p v-if="spouseSyncResult.message">{{ spouseSyncResult.message }}</p>
        <template v-else-if="spouseSyncResult.fetched !== undefined">
          <p>Found {{ spouseSyncResult.missing_found }} missing · Fetched {{ spouseSyncResult.fetched }} · Failed {{ spouseSyncResult.failed }}</p>
        </template>
      </div>
    </div>

    <!-- Manual Contact Search (only when connected) -->
    <div v-if="csStatus?.connected" class="card p-5 space-y-4">
      <div>
        <div class="section-label">Import Specific Person</div>
        <p class="text-xs text-ink-500 mt-0.5">Search ChurchSuite by name and import anyone the sync missed</p>
      </div>
      <div class="flex gap-2">
        <input v-model="csSearchQuery" @keydown.enter="searchCsContacts" type="text" placeholder="Search by name…" class="flex-1" />
        <button @click="searchCsContacts" :disabled="csSearching || csSearchQuery.length < 2" class="btn btn-secondary">
          {{ csSearching ? 'Searching…' : 'Search' }}
        </button>
      </div>
      <div v-if="csSearchResults !== null" class="space-y-2">
        <p v-if="!csSearchResults.length" class="text-xs text-ink-500">No results found.</p>
        <div v-for="r in csSearchResults" :key="r.id" class="flex items-center justify-between rounded-lg bg-surface-2 px-3 py-2 text-sm">
          <div>
            <span class="font-medium">{{ r.first_name }} {{ r.last_name }}</span>
            <span v-if="r.email" class="text-xs text-ink-500 ml-2">{{ r.email }}</span>
          </div>
          <span v-if="r.in_db" class="text-xs text-green-400">Already imported</span>
          <button v-else @click="importCsContact(r)" :disabled="r.importing" class="btn btn-primary btn-sm text-xs px-3 py-1">
            {{ r.importing ? 'Importing…' : 'Import' }}
          </button>
        </div>
      </div>
    </div>

    <!-- Camp Sync (only when connected) -->
    <div v-if="csStatus?.connected" class="card p-5 space-y-4">
      <div>
        <div class="section-label">Camp Sync</div>
        <p class="text-xs text-ink-500 mt-0.5">Pull attendee registrations from a ChurchSuite event into a camp</p>
      </div>
      <div>
        <label class="field-label">Camp</label>
        <select v-model="syncCampId">
          <option value="">Select camp…</option>
          <option v-for="c in camps" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
      </div>
      <div v-if="campSyncResult" :class="campSyncResult.success ? 'bg-green-500/10 border-green-500/20 text-green-300' : 'bg-red-500/10 border-red-500/20 text-red-300'"
        class="rounded-xl border p-3 text-xs space-y-1">
        <p class="font-medium">{{ campSyncResult.success ? '✓ Sync complete' : '✗ Sync failed' }}</p>
        <p v-if="campSyncResult.message">{{ campSyncResult.message }}</p>
        <template v-if="campSyncResult.summary">
          <p v-for="(val, key) in campSyncResult.summary" :key="key">{{ formatSyncKey(key) }}: {{ val }}</p>
        </template>
      </div>
      <button @click="runCampSync" :disabled="campSyncing || !syncCampId" class="btn btn-primary">
        {{ campSyncing ? 'Syncing…' : 'Sync Camp Attendees' }}
      </button>
    </div>

    <!-- User modal -->
    <AppModal v-model="showUserModal" :title="editingUser ? 'Edit User' : 'Add User'">
      <form @submit.prevent="saveUser" class="space-y-4">
        <div>
          <label class="field-label">Username *</label>
          <input v-model="userForm.username" type="text" required autocomplete="off" />
        </div>
        <div>
          <label class="field-label">Email address *</label>
          <input v-model="userForm.email" type="email" required autocomplete="off" />
          <p v-if="!editingUser" class="text-xs text-ink-500 mt-1">
            An activation link will be emailed to this address so the user can set their own password.
          </p>
        </div>
        <div>
          <label class="field-label">Role</label>
          <select v-model="userForm.role">
            <option value="full_admin">Full Admin</option>
            <option value="admin">Admin</option>
            <option value="intranet_admin">Intranet Admin</option>
          </select>
        </div>
        <div v-if="editingUser">
          <label class="field-label">New Password (leave blank to keep)</label>
          <input v-model="userForm.password" type="password" autocomplete="new-password" />
        </div>
      </form>
      <template #footer>
        <button v-if="editingUser && editingUser.id !== auth.user?.id"
          @click="deleteUser" class="btn btn-ghost text-red-400 hover:text-red-300 mr-auto">
          Delete
        </button>
        <button @click="showUserModal = false" class="btn btn-ghost">Cancel</button>
        <button @click="saveUser" :disabled="savingUser" class="btn btn-primary">
          {{ savingUser ? 'Saving…' : 'Save' }}
        </button>
      </template>
    </AppModal>

    <!-- Mail settings -->
    <div class="card p-5 space-y-4">
      <div class="flex items-center justify-between gap-3">
        <div>
          <div class="section-label">Mail Settings</div>
          <p class="text-xs text-ink-500 mt-0.5">SMTP for activation and password reset emails</p>
        </div>
        <span v-if="mailStatus !== null"
          class="text-xs px-2 py-1 rounded-lg font-medium"
          :class="mailStatus.configured ? 'bg-green-500/15 text-green-400' : 'bg-amber-500/15 text-amber-400'">
          {{ mailStatus.configured ? 'Configured' : 'Not configured' }}
        </span>
      </div>

      <div v-if="mailStatus && !mailStatus.configured" class="rounded-xl bg-amber-500/10 border border-amber-500/20 p-3 text-xs text-amber-300 space-y-1">
        <p class="font-medium">Required before activation emails can be sent:</p>
        <p v-for="issue in mailStatus.issues" :key="issue">• {{ issue }}</p>
      </div>

      <LoadingSpinner v-if="loadingMail" />
      <template v-else>
        <div class="grid grid-cols-2 gap-3">
          <div class="col-span-2 sm:col-span-1">
            <label class="field-label">SMTP Host</label>
            <input v-model="mailForm.mail_host" type="text" placeholder="smtp.example.com" autocomplete="off" />
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
            <input v-model="mailForm.mail_username" type="text" autocomplete="off" />
          </div>
          <div>
            <label class="field-label">Password</label>
            <input v-model="mailForm.mail_password" type="password" autocomplete="new-password"
              :placeholder="mailHasPassword ? 'Leave blank to keep current' : 'No password saved'" />
          </div>
          <div>
            <label class="field-label">From Name</label>
            <input v-model="mailForm.mail_from_name" type="text" placeholder="Campo Notifications" />
          </div>
          <div>
            <label class="field-label">From Email *</label>
            <input v-model="mailForm.mail_from_email" type="email" placeholder="campo@yourdomain.com" />
          </div>
          <div class="col-span-2">
            <label class="field-label">App Base URL</label>
            <input v-model="mailForm.app_base_url" type="url" placeholder="https://campoffice.example.com" />
            <p class="text-xs text-ink-500 mt-1">Used to build activation and reset links in emails.</p>
          </div>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
          <button @click="saveMail" :disabled="savingMail" class="btn btn-primary btn-sm">
            {{ savingMail ? 'Saving…' : 'Save Mail Settings' }}
          </button>
          <div class="flex items-center gap-2">
            <input v-model="testEmail" type="email" placeholder="Send test to…" class="text-sm py-1.5 px-3 w-48" />
            <button @click="testMail" :disabled="testingMail || !testEmail" class="btn btn-secondary btn-sm">
              {{ testingMail ? 'Sending…' : 'Test' }}
            </button>
          </div>
        </div>
      </template>
    </div>

  </div>
</template>

<script setup>
import { ref, inject, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { api, auth as authApi } from '@/api.js'
import { useAuthStore } from '@/stores/auth.js'
import AppModal from '@/components/AppModal.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

const toast = inject('toast')
const auth  = useAuthStore()
const route = useRoute()

const loadingUsers  = ref(true)
const savingUser    = ref(false)
const showUserModal = ref(false)
const editingUser   = ref(null)
const users         = ref([])
const resendingId   = ref(null)
const sendResetId   = ref(null)

const blankUser = () => ({ username: '', email: '', role: 'admin', password: '' })
const userForm  = ref(blankUser())

const savingPw = ref(false)
const pwError  = ref('')
const pwForm   = ref({ current: '', next: '', confirm: '' })

async function loadUsers() {
  loadingUsers.value = true
  try {
    const res = await api.users.list()
    users.value = res.users ?? res
  } catch {}
  loadingUsers.value = false
}

function openNewUser()   { editingUser.value = null;  userForm.value = blankUser(); showUserModal.value = true }
function openEditUser(u) { editingUser.value = u; userForm.value = { username: u.username, email: u.email || '', role: u.role, password: '' }; showUserModal.value = true }

async function saveUser() {
  savingUser.value = true
  try {
    if (editingUser.value) {
      await api.users.update(editingUser.value.id, userForm.value)
      toast?.add('User updated', 'success')
    } else {
      const res = await api.users.create(userForm.value)
      if (res.activation_email_sent) {
        toast?.add('User created — activation email sent', 'success')
      } else {
        toast?.add(`User created — activation email failed: ${res.mail_error || 'unknown error'}. Configure mail in Settings and use Resend.`, 'error')
      }
    }
    showUserModal.value = false
    await loadUsers()
  } catch (e) {
    toast?.add(e?.data?.message || 'Save failed', 'error')
  } finally {
    savingUser.value = false
  }
}

async function resendActivation(u) {
  resendingId.value = u.id
  try {
    await api.users.resendActivation(u.id)
    toast?.add(`Activation email sent to ${u.email}`, 'success')
  } catch (e) {
    toast?.add(e?.data?.message || 'Failed to send activation email', 'error')
  } finally {
    resendingId.value = null
  }
}

async function sendReset(u) {
  if (!u.email) { toast?.add('This user has no email address on file — add one first', 'error'); return }
  sendResetId.value = u.id
  try {
    await authApi.requestReset(u.username)
    toast?.add(`Password reset link sent to ${u.email}`, 'success')
  } catch (e) {
    toast?.add(e?.data?.message || 'Failed to send reset email', 'error')
  } finally {
    sendResetId.value = null
  }
}

async function deleteUser() {
  if (!confirm(`Delete user "${editingUser.value.username}"?`)) return
  try {
    await api.users.delete(editingUser.value.id)
    toast?.add('User deleted', 'success')
    showUserModal.value = false
    await loadUsers()
  } catch {
    toast?.add('Delete failed', 'error')
  }
}

async function changePassword() {
  pwError.value = ''
  if (pwForm.value.next !== pwForm.value.confirm) { pwError.value = 'Passwords do not match'; return }
  if (pwForm.value.next.length < 8) { pwError.value = 'Password must be at least 8 characters'; return }
  savingPw.value = true
  try {
    await api.users.changePassword({ current_password: pwForm.value.current, new_password: pwForm.value.next })
    toast?.add('Password updated', 'success')
    pwForm.value = { current: '', next: '', confirm: '' }
  } catch (e) {
    pwError.value = e?.data?.error || 'Incorrect current password'
  } finally {
    savingPw.value = false
  }
}

// ── Mail settings ─────────────────────────────────────────────────────────────
const loadingMail  = ref(true)
const savingMail   = ref(false)
const testingMail  = ref(false)
const testEmail    = ref('')
const mailHasPassword = ref(false)
const mailStatus   = ref(null)
const mailForm     = ref({
  mail_host: '', mail_port: 587, mail_encryption: 'tls',
  mail_username: '', mail_password: '',
  mail_from_name: 'Campo Notifications', mail_from_email: '',
  app_base_url: '',
})

async function loadMailSettings() {
  loadingMail.value = true
  try {
    const res = await api.settings.getMail()
    mailHasPassword.value = res.has_password || false
    mailStatus.value      = { configured: res.configured, issues: res.issues || [] }
    mailForm.value = {
      mail_host:       res.host        || '',
      mail_port:       res.port        || 587,
      mail_encryption: res.encryption  || 'tls',
      mail_username:   res.username    || '',
      mail_password:   '',
      mail_from_name:  res.from_name   || 'Campo Notifications',
      mail_from_email: res.from_email  || '',
      app_base_url:    res.app_base_url|| '',
    }
  } catch {}
  loadingMail.value = false
}

async function saveMail() {
  savingMail.value = true
  try {
    await api.settings.saveMail(mailForm.value)
    toast?.add('Mail settings saved', 'success')
    await loadMailSettings()
  } catch (e) {
    toast?.add(e?.data?.message || 'Save failed', 'error')
  } finally {
    savingMail.value = false
  }
}

async function testMail() {
  if (!testEmail.value) return
  testingMail.value = true
  try {
    await api.settings.testMail(testEmail.value)
    toast?.add(`Test email sent to ${testEmail.value}`, 'success')
  } catch (e) {
    toast?.add(e?.data?.message || 'Send failed', 'error')
  } finally {
    testingMail.value = false
  }
}

// ── ChurchSuite ──────────────────────────────────────────────────────────────
const csLoading      = ref(false)
const csStatus       = ref(null)
const csDisconnecting = ref(false)
const dirSyncing       = ref(false)
const dirSyncResult    = ref(null)
const dirSyncProcessed = ref(0)
const dirSyncStage     = ref('')
const spouseSyncing    = ref(false)
const spouseSyncResult = ref(null)
const csSearchQuery    = ref('')
const csSearching      = ref(false)
const csSearchResults  = ref(null)
const campSyncing    = ref(false)
const campSyncResult = ref(null)
const syncCampId     = ref('')
const camps          = ref([])

async function loadCsStatus() {
  csLoading.value = true
  try {
    csStatus.value = await api.churchsuite.status()
  } catch { csStatus.value = null }
  csLoading.value = false
}

function connectChurchSuite() {
  window.location.href = '/api/churchsuite/oauth/start?return_to=/settings'
}

async function disconnectChurchSuite() {
  if (!confirm('Disconnect ChurchSuite? You will need to re-authorise to sync again.')) return
  csDisconnecting.value = true
  try {
    await api.churchsuite.disconnect()
    toast?.add('ChurchSuite disconnected', 'success')
    await loadCsStatus()
  } catch (e) {
    toast?.add(e?.data?.message || 'Disconnect failed', 'error')
  }
  csDisconnecting.value = false
}

async function runDirectorySync() {
  dirSyncing.value = true
  dirSyncResult.value = null
  dirSyncProcessed.value = 0
  dirSyncStage.value = ''
  const totals = { created: 0, updated: 0, reviewed: 0, skipped: 0 }
  let token = null
  try {
    while (true) {
      const res = await api.churchsuite.syncDirectory(token)
      if (res.summary) {
        for (const k of Object.keys(totals)) {
          totals[k] += res.summary[k] ?? 0
        }
      }
      if (res.processed) dirSyncProcessed.value = res.processed
      if (res.stage)     dirSyncStage.value = res.stage
      if (!res.in_progress) {
        dirSyncResult.value = { success: true, summary: totals }
        toast?.add('Directory sync complete', 'success')
        break
      }
      token = res.sync_token
    }
  } catch (e) {
    dirSyncResult.value = { success: false, message: e?.data?.message || 'Sync failed' }
    toast?.add(dirSyncResult.value.message, 'error')
  }
  dirSyncing.value = false
}

async function fillMissingSpouses() {
  spouseSyncing.value = true
  spouseSyncResult.value = null
  try {
    const res = await api.churchsuite.fillMissingSpouses()
    spouseSyncResult.value = res
    if (res.fetched > 0) toast?.add(`Fetched ${res.fetched} missing members`, 'success')
    else toast?.add(res.message || 'No missing members found', 'success')
  } catch (e) {
    spouseSyncResult.value = { success: false, message: e?.data?.message || 'Failed' }
    toast?.add(spouseSyncResult.value.message, 'error')
  }
  spouseSyncing.value = false
}

async function searchCsContacts() {
  if (csSearchQuery.value.length < 2) return
  csSearching.value = true
  csSearchResults.value = null
  try {
    const res = await api.churchsuite.searchContacts(csSearchQuery.value)
    csSearchResults.value = res.results ?? []
  } catch (e) {
    toast?.add(e?.data?.message || 'Search failed', 'error')
    csSearchResults.value = []
  }
  csSearching.value = false
}

async function importCsContact(r) {
  r.importing = true
  try {
    const res = await api.churchsuite.importContact(r.id)
    if (res.success) {
      r.in_db = true
      toast?.add(`Imported ${res.name}`, 'success')
    } else {
      toast?.add(res.message || 'Import failed', 'error')
    }
  } catch (e) {
    toast?.add(e?.data?.message || 'Import failed', 'error')
  }
  r.importing = false
}

async function runCampSync() {
  if (!syncCampId.value) return
  campSyncing.value = true
  campSyncResult.value = null
  try {
    const res = await api.churchsuite.syncCamp(syncCampId.value)
    campSyncResult.value = res
    if (res.success !== false) toast?.add('Camp sync complete', 'success')
  } catch (e) {
    campSyncResult.value = { success: false, message: e?.data?.message || 'Sync failed' }
    toast?.add(campSyncResult.value.message, 'error')
  }
  campSyncing.value = false
}

function formatSyncKey(key) {
  return key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
}

async function loadCamps() {
  try {
    const res = await api.camps.list()
    camps.value = Array.isArray(res) ? res : (res.camps ?? [])
  } catch {}
}

onMounted(async () => {
  loadUsers()
  loadMailSettings()
  loadCsStatus()
  loadCamps()

  const cs = route.query.churchsuite
  if (cs === 'connected') {
    toast?.add('ChurchSuite connected successfully!', 'success')
  } else if (cs === 'error') {
    const msg = route.query.churchsuite_message || 'ChurchSuite connection failed'
    toast?.add(msg, 'error')
  }
})
</script>
