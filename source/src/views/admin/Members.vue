<template>
  <div class="p-6 max-w-6xl mx-auto space-y-5">
    <div class="flex items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold text-ink-100">Members</h1>
        <p class="text-sm text-ink-500 mt-0.5">Camp member directory</p>
      </div>
      <button @click="openNew" class="btn btn-primary btn-sm">+ Add Member</button>
    </div>

    <LoadingSpinner v-if="loading" :full="true" />

    <EmptyState v-else-if="!members.length" icon="👥" title="No members yet"
      subtitle="Add a new member to get started." />

    <template v-else>
      <!-- Bulk action bar -->
      <div v-if="selectedIds.length"
        class="flex items-center gap-3 px-4 py-2.5 bg-surface-700 rounded-xl border border-surface-600">
        <span class="text-sm text-ink-400">{{ selectedIds.length }} selected</span>
        <button @click="clearSelection" class="text-xs text-ink-500 hover:text-ink-300 transition-colors">Clear</button>
        <div class="flex-1"></div>
        <button @click="bulkDelete"
          class="btn btn-sm bg-red-500/15 text-red-400 hover:bg-red-500/25 border border-red-500/20 transition-colors">
          Delete {{ selectedIds.length }} member{{ selectedIds.length !== 1 ? 's' : '' }}
        </button>
      </div>

      <div class="overflow-x-auto rounded-xl border border-surface-600">
        <table class="w-full text-sm">
          <thead class="bg-surface-800">
            <!-- Sort header row -->
            <tr class="border-b border-surface-600">
              <th class="px-3 py-2.5 w-10">
                <input type="checkbox" :checked="isAllSelected" @change="toggleSelectAll" class="w-4 h-4" />
              </th>
              <th v-for="col in columns" :key="col.key"
                class="px-3 py-2.5 text-left text-xs font-semibold text-ink-400 uppercase tracking-wide whitespace-nowrap cursor-pointer select-none hover:text-ink-200 transition-colors"
                @click="setSort(col.key)">
                <span class="inline-flex items-center gap-1">
                  {{ col.label }}
                  <span v-if="sortKey === col.key" class="text-brand-400">{{ sortDir === 'asc' ? '↑' : '↓' }}</span>
                  <span v-else class="text-ink-700">↕</span>
                </span>
              </th>
            </tr>
            <!-- Column filter row -->
            <tr class="border-b border-surface-600">
              <td class="px-2 py-1.5 w-10"></td>
              <td class="px-2 py-1.5">
                <input v-model="filters.name" type="text" placeholder="Name…" class="w-full text-xs" />
              </td>
              <td class="px-2 py-1.5">
                <input v-model="filters.household" type="text" placeholder="Household…" class="w-full text-xs" />
              </td>
              <td class="px-2 py-1.5">
                <input v-model="filters.mobile" type="text" placeholder="Mobile…" class="w-full text-xs" />
              </td>
              <td class="px-2 py-1.5">
                <input v-model="filters.email" type="text" placeholder="Email…" class="w-full text-xs" />
              </td>
              <td class="px-2 py-1.5">
                <select v-model="filters.type" class="w-full text-xs">
                  <option value="">All types</option>
                  <option value="adult">Adult</option>
                  <option value="youth">Youth</option>
                  <option value="child">Child</option>
                  <option value="infant">Infant</option>
                </select>
              </td>
              <td class="px-2 py-1.5">
                <input v-model="filters.site" type="text" placeholder="Site…" class="w-full text-xs" />
              </td>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!sortedMembers.length">
              <td colspan="7" class="px-4 py-8 text-center text-ink-500 italic">
                No members match the current filters.
              </td>
            </tr>
            <tr v-for="m in sortedMembers" :key="m.id"
              class="border-b border-surface-700 last:border-0 hover:bg-surface-700 cursor-pointer transition-colors"
              @click="openEdit(m)">
              <td class="px-3 py-2.5 w-10" @click.stop>
                <input type="checkbox" :checked="selectedIds.includes(m.id)"
                  @change="toggleSelect(m.id)" class="w-4 h-4" />
              </td>
              <td class="px-3 py-2.5 font-medium text-ink-200 whitespace-nowrap">{{ fullName(m) }}</td>
              <td class="px-3 py-2.5 text-ink-400">{{ m.household_name || '—' }}</td>
              <td class="px-3 py-2.5 text-ink-400 whitespace-nowrap">{{ m.mobile || '—' }}</td>
              <td class="px-3 py-2.5 text-ink-400">{{ m.email || '—' }}</td>
              <td class="px-3 py-2.5">
                <span :class="typeBadge(m.member_type)" class="capitalize">{{ m.member_type }}</span>
              </td>
              <td class="px-3 py-2.5 text-ink-400 whitespace-nowrap">{{ m.site_numbers || '—' }}</td>
            </tr>
          </tbody>
        </table>
        <div class="px-4 py-2 text-xs text-ink-500 border-t border-surface-600 flex justify-between items-center">
          <span>{{ sortedMembers.length }} of {{ members.length }} members</span>
          <button v-if="hasFilters" @click="clearFilters"
            class="text-brand-400 hover:text-brand-300 transition-colors">Clear filters</button>
        </div>
      </div>
    </template>

    <!-- Member modal -->
    <AppModal v-model="showModal" :title="editing ? 'Edit Member' : 'Add Member'">
      <form @submit.prevent="save" class="space-y-4">
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="field-label">First Name *</label>
            <input v-model="form.first_name" type="text" required />
          </div>
          <div>
            <label class="field-label">Last Name</label>
            <input v-model="form.last_name" type="text" />
          </div>
        </div>

        <!-- Household -->
        <div>
          <label class="field-label">Household</label>
          <div class="flex gap-2">
            <select v-model="form.household_id" class="flex-1" @change="renamingHousehold = false">
              <option value="">— No household —</option>
              <option value="__new__">+ Create new household…</option>
              <option v-for="h in households" :key="h.id" :value="h.id">
                {{ h.name }}
              </option>
            </select>
            <button v-if="form.household_id && form.household_id !== '__new__'"
              type="button"
              @click="renamingHousehold = !renamingHousehold"
              class="btn btn-ghost btn-sm flex-none text-ink-500"
              title="Rename household">✏️</button>
          </div>

          <div v-if="form.household_id === '__new__'" class="mt-2 grid grid-cols-2 gap-2">
            <div>
              <label class="field-label text-xs">Last Name *</label>
              <input v-model="form.new_household_last" type="text" placeholder="e.g. Humphreys" class="w-full" />
            </div>
            <div>
              <label class="field-label text-xs">First Name *</label>
              <input v-model="form.new_household_first" type="text" placeholder="e.g. Curtis" class="w-full" />
            </div>
            <p class="col-span-2 text-xs text-ink-500 -mt-1">
              Will be saved as: <span class="text-ink-300">{{ householdPreview }}</span>
            </p>
          </div>

          <div v-if="renamingHousehold && form.household_id && form.household_id !== '__new__'"
            class="mt-2 space-y-1.5">
            <p class="text-xs text-ink-500">Rename this household</p>
            <div class="grid grid-cols-2 gap-2">
              <div>
                <label class="field-label text-xs">Last Name *</label>
                <input v-model="renameHouseholdLast" type="text" placeholder="e.g. Humphreys" class="w-full" />
              </div>
              <div>
                <label class="field-label text-xs">First Name *</label>
                <input v-model="renameHouseholdFirst" type="text" placeholder="e.g. Curtis" class="w-full" />
              </div>
            </div>
            <p class="text-xs text-ink-500">
              Will be renamed to: <span class="text-ink-300">{{ renamePreview }}</span>
            </p>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="field-label">Type</label>
            <select v-model="form.member_type">
              <option value="adult">Adult</option>
              <option value="youth">Youth</option>
              <option value="child">Child</option>
              <option value="infant">Infant</option>
            </select>
          </div>
          <div>
            <label class="field-label">Gender</label>
            <select v-model="form.gender">
              <option value="">—</option>
              <option value="male">Male</option>
              <option value="female">Female</option>
            </select>
          </div>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="field-label">Mobile</label>
            <input v-model="form.mobile" type="tel" />
          </div>
          <div>
            <label class="field-label">Email</label>
            <input v-model="form.email" type="email" />
          </div>
        </div>
        <div>
          <label class="field-label">Medical Notes</label>
          <textarea v-model="form.medical_notes" rows="2"
            placeholder="Allergies, conditions, medications…" class="resize-none" />
        </div>
        <div>
          <label class="field-label">Notes</label>
          <textarea v-model="form.notes" rows="2" class="resize-none" />
        </div>

        <!-- Read-only site allocation info -->
        <div v-if="editing && editing.site_numbers">
          <label class="field-label">Current Site(s)</label>
          <p class="text-sm text-ink-300 bg-surface-700 rounded-lg px-3 py-2">{{ editing.site_numbers }}</p>
        </div>
      </form>

      <!-- ChurchSuite Household panel -->
      <div v-if="editing" class="mt-5 border-t border-ink-700 pt-4">
        <p class="field-label mb-2">ChurchSuite Household</p>
        <div v-if="csHouseholdLoading" class="text-xs text-ink-500">Loading…</div>
        <div v-else-if="csHousehold && csHousehold.members && csHousehold.members.length" class="space-y-1">
          <div v-for="hm in csHousehold.members" :key="hm.id"
            class="flex items-center gap-2 rounded-lg bg-ink-800 px-3 py-2 text-sm"
            :class="hm.id === editing.id ? 'ring-1 ring-brand-400' : ''">
            <span class="flex-1 font-medium text-ink-100">{{ hm.first_name }} {{ hm.last_name }}</span>
            <span class="text-xs px-1.5 py-0.5 rounded-full"
              :class="hm.id === editing.id ? 'bg-brand-500/20 text-brand-300' : 'bg-ink-700 text-ink-400'">
              {{ hm.id === editing.id ? 'this member' : (hm.role_label || 'member') }}
            </span>
            <span v-if="hm.churchsuite_sync_status === 'review'"
              class="text-xs text-amber-400" title="Needs review">⚠</span>
          </div>
          <p v-if="csHousehold.household" class="text-xs text-ink-500 mt-1">
            ChurchSuite household · {{ csHousehold.members.length }} {{ csHousehold.members.length === 1 ? 'person' : 'people' }}
          </p>
        </div>
        <p v-else class="text-xs text-ink-500 italic">Not linked to a ChurchSuite household</p>
      </div>

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
import { ref, computed, inject, onMounted, onBeforeUnmount } from 'vue'
import { api } from '@/api.js'
import AppModal from '@/components/AppModal.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import EmptyState from '@/components/EmptyState.vue'

const toast = inject('toast')

const loading    = ref(true)
const saving     = ref(false)
const showModal  = ref(false)
const editing    = ref(null)
const members    = ref([])
const households = ref([])

const filters = ref({ name: '', household: '', mobile: '', email: '', type: '', site: '' })
const sortKey = ref('fullName')
const sortDir = ref('asc')

const renamingHousehold    = ref(false)
const renameHouseholdFirst = ref('')
const renameHouseholdLast  = ref('')

const selectedIds = ref([])
const pendingMemberDeletes = new Map()

const isAllSelected = computed(() =>
  sortedMembers.value.length > 0 && sortedMembers.value.every(m => selectedIds.value.includes(m.id))
)

function toggleSelect(id) {
  const idx = selectedIds.value.indexOf(id)
  if (idx === -1) selectedIds.value.push(id)
  else selectedIds.value.splice(idx, 1)
}

function toggleSelectAll() {
  if (isAllSelected.value) selectedIds.value = []
  else selectedIds.value = sortedMembers.value.map(m => m.id)
}

function clearSelection() { selectedIds.value = [] }

onBeforeUnmount(() => {
  pendingMemberDeletes.forEach(({ ids, timer }) => {
    clearTimeout(timer)
    ids.forEach(id => api.members.delete(id).catch(() => {}))
  })
  pendingMemberDeletes.clear()
})

const csHousehold        = ref(null)
const csHouseholdLoading = ref(false)

const columns = [
  { key: 'fullName',       label: 'Name' },
  { key: 'household_name', label: 'Household' },
  { key: 'mobile',         label: 'Mobile' },
  { key: 'email',          label: 'Email' },
  { key: 'member_type',    label: 'Type' },
  { key: 'site_numbers',   label: 'Site(s)' },
]

const blank = () => ({
  first_name: '', last_name: '', household_id: '',
  new_household_first: '', new_household_last: '',
  member_type: 'adult', gender: '', mobile: '', email: '',
  medical_notes: '', notes: ''
})
const form = ref(blank())

function formatHouseholdName(last, first) {
  const l = last.trim(); const f = first.trim()
  if (l && f) return `${l} (${f})`
  return l || f || ''
}

const householdPreview = computed(() =>
  formatHouseholdName(form.value.new_household_last, form.value.new_household_first)
)
const renamePreview = computed(() =>
  formatHouseholdName(renameHouseholdLast.value, renameHouseholdFirst.value)
)

function fullName(m) { return [m.first_name, m.last_name].filter(Boolean).join(' ') }

function typeBadge(type) {
  return {
    adult:  'badge bg-surface-600 text-ink-300',
    youth:  'badge bg-amber-500/20 text-amber-400',
    child:  'badge bg-sky-500/20 text-sky-400',
    infant: 'badge bg-emerald-500/20 text-emerald-400',
  }[type] || 'badge bg-surface-600 text-ink-400'
}

function setSort(key) {
  if (sortKey.value === key) sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
  else { sortKey.value = key; sortDir.value = 'asc' }
}

const hasFilters = computed(() => Object.values(filters.value).some(v => v !== ''))
function clearFilters() { Object.keys(filters.value).forEach(k => { filters.value[k] = '' }) }

const filteredMembers = computed(() => {
  const f = filters.value
  return members.value.filter(m => {
    if (f.name && !fullName(m).toLowerCase().includes(f.name.toLowerCase())) return false
    if (f.household && !(m.household_name || '').toLowerCase().includes(f.household.toLowerCase())) return false
    if (f.mobile && !(m.mobile || '').toLowerCase().includes(f.mobile.toLowerCase())) return false
    if (f.email && !(m.email || '').toLowerCase().includes(f.email.toLowerCase())) return false
    if (f.type && m.member_type !== f.type) return false
    if (f.site && !(m.site_numbers || '').toLowerCase().includes(f.site.toLowerCase())) return false
    return true
  })
})

const sortedMembers = computed(() => {
  const arr = [...filteredMembers.value]
  const key = sortKey.value
  const dir = sortDir.value === 'asc' ? 1 : -1
  return arr.sort((a, b) => {
    let av, bv
    if (key === 'fullName') {
      av = ((a.last_name || '') + ' ' + (a.first_name || '')).toLowerCase()
      bv = ((b.last_name || '') + ' ' + (b.first_name || '')).toLowerCase()
    } else {
      av = (a[key] || '').toString().toLowerCase()
      bv = (b[key] || '').toString().toLowerCase()
    }
    return av < bv ? -1 * dir : av > bv ? 1 * dir : 0
  })
})

async function loadHouseholds() {
  try { households.value = await api.memberHouseholds.list() } catch {}
}

async function load() {
  loading.value = true
  try {
    const res = await api.members.list({ per_page: 5000 })
    members.value = res.members ?? []
  } catch {}
  loading.value = false
}

function openNew() {
  editing.value = null
  form.value = blank()
  renamingHousehold.value = false
  renameHouseholdFirst.value = ''
  renameHouseholdLast.value  = ''
  showModal.value = true
}

async function openEdit(m) {
  editing.value = m
  form.value = { ...blank(), ...m, household_id: '' }
  renamingHousehold.value = false
  csHousehold.value = null
  renameHouseholdLast.value  = ''
  renameHouseholdFirst.value = ''
  showModal.value = true
  csHouseholdLoading.value = true
  try {
    const res = await api.get('/member/cs-household', { member_id: m.id })
    csHousehold.value = res && res.members ? res : null
    if (res && res.household && res.household.id) {
      form.value.household_id = res.household.id
      renameHouseholdLast.value = res.household.display_name || ''
    }
  } catch { csHousehold.value = null }
  finally { csHouseholdLoading.value = false }
}

async function save() {
  saving.value = true
  try {
    let householdId = form.value.household_id

    if (householdId === '__new__') {
      const name = formatHouseholdName(form.value.new_household_last, form.value.new_household_first)
      if (!name) { toast?.add('Enter a household last name', 'error'); saving.value = false; return }
      const res = await api.memberHouseholds.create({ name })
      if (!res?.id) { toast?.add('Failed to create household', 'error'); saving.value = false; return }
      householdId = res.id
      await loadHouseholds()
    }

    if (renamingHousehold.value && householdId && householdId !== '__new__') {
      const newName = formatHouseholdName(renameHouseholdLast.value, renameHouseholdFirst.value)
      if (newName) {
        await api.memberHouseholds.update(householdId, { name: newName })
        await loadHouseholds()
        renamingHousehold.value = false
      } else {
        toast?.add('Enter a name to rename the household', 'error')
        saving.value = false
        return
      }
    }

    const payload = {
      first_name:    form.value.first_name,
      last_name:     form.value.last_name,
      member_type:   form.value.member_type,
      gender:        form.value.gender,
      mobile:        form.value.mobile,
      email:         form.value.email,
      medical_notes: form.value.medical_notes,
      notes:         form.value.notes,
    }

    let savedId = editing.value?.id
    if (editing.value) {
      await api.members.update(editing.value.id, payload)
      toast?.add('Member updated', 'success')
    } else {
      const res = await api.members.create(payload)
      savedId = res.id
      toast?.add('Member added', 'success')
    }

    if (savedId && householdId !== undefined) {
      await api.memberHouseholds.assign(savedId, householdId || null)
    }

    showModal.value = false
    await load()
  } catch (e) {
    toast?.add(e?.data?.message || 'Save failed', 'error')
  } finally {
    saving.value = false
  }
}

function doDelete() {
  const member = editing.value
  if (!member) return
  showModal.value = false
  members.value = members.value.filter(m => m.id !== member.id)
  selectedIds.value = selectedIds.value.filter(id => id !== member.id)

  const key = `single_${member.id}`
  const timer = setTimeout(async () => {
    pendingMemberDeletes.delete(key)
    try { await api.members.delete(member.id) } catch { toast?.add('Delete failed', 'error') }
  }, 6000)

  pendingMemberDeletes.set(key, { ids: [member.id], timer })
  toast?.add(`${fullName(member)} removed`, 'info', 6000, {
    label: 'Undo',
    fn() {
      const entry = pendingMemberDeletes.get(key)
      if (!entry) return
      clearTimeout(entry.timer)
      pendingMemberDeletes.delete(key)
      members.value = [...members.value, member]
        .sort((a, b) => ((a.last_name || '') + (a.first_name || '')).localeCompare((b.last_name || '') + (b.first_name || '')))
    }
  })
}

function bulkDelete() {
  const ids = [...selectedIds.value]
  if (!ids.length) return
  const deleted = members.value.filter(m => ids.includes(m.id))
  members.value = members.value.filter(m => !ids.includes(m.id))
  selectedIds.value = []

  const key = `bulk_${Date.now()}`
  const timer = setTimeout(async () => {
    pendingMemberDeletes.delete(key)
    for (const id of ids) {
      try { await api.members.delete(id) } catch {}
    }
  }, 6000)

  pendingMemberDeletes.set(key, { ids, timer })
  toast?.add(`${ids.length} member${ids.length !== 1 ? 's' : ''} deleted`, 'info', 6000, {
    label: 'Undo',
    fn() {
      const entry = pendingMemberDeletes.get(key)
      if (!entry) return
      clearTimeout(entry.timer)
      pendingMemberDeletes.delete(key)
      members.value = [...members.value, ...deleted]
        .sort((a, b) => ((a.last_name || '') + (a.first_name || '')).localeCompare((b.last_name || '') + (b.first_name || '')))
    }
  })
}

onMounted(async () => {
  await loadHouseholds()
  await load()
})
</script>
