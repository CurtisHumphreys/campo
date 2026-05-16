<template>
  <div class="p-6 max-w-4xl mx-auto space-y-6">

    <!-- Header -->
    <div class="flex items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold text-ink-100">Camps</h1>
        <p class="text-sm text-ink-500 mt-0.5">Manage annual camp records</p>
      </div>
      <button @click="openNew" class="btn btn-primary btn-sm">+ New Camp</button>
    </div>

    <LoadingSpinner v-if="loading" :full="true" />

    <EmptyState v-else-if="!camps.length" icon="🏕️" title="No camps yet"
      subtitle="Create your first camp to get started." />

    <!-- Camp list -->
    <div v-else class="space-y-3">
      <div v-for="c in camps" :key="c.id" class="card p-5">
        <div class="flex items-start justify-between gap-4">
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap mb-1">
              <span class="font-semibold text-ink-100 text-lg">{{ c.name }}</span>
              <span :class="statusBadge(c.status)">{{ c.status }}</span>
            </div>
            <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-ink-400">
              <span v-if="c.location">📍 {{ c.location }}</span>
              <span v-if="c.start_date">📅 {{ formatDate(c.start_date) }}{{ c.end_date ? ' – ' + formatDate(c.end_date) : '' }}</span>
            </div>
            <div v-if="c.churchsuite_last_sync_at" class="flex items-center gap-2 mt-1">
              <span :class="['badge text-xs', c.churchsuite_last_sync_status === 'success' ? 'bg-emerald-500/15 text-emerald-400' : c.churchsuite_last_sync_status === 'warning' ? 'bg-amber-500/15 text-amber-400' : 'bg-red-500/15 text-red-400']">
                CS {{ c.churchsuite_last_sync_status || 'synced' }}
              </span>
              <span class="text-xs text-ink-600">{{ formatDateTime(c.churchsuite_last_sync_at) }}</span>
              <span v-if="c.churchsuite_last_sync_message" class="text-xs text-ink-500 truncate max-w-xs">{{ c.churchsuite_last_sync_message }}</span>
            </div>
          </div>
          <div class="flex gap-2 flex-none flex-wrap justify-end">
            <button v-if="syncingId === c.id" disabled
              class="btn btn-secondary btn-sm text-xs min-w-32">
              {{ syncProgress ? `Syncing… ${syncProgress.processed}/${syncProgress.total_results || '?'}` : 'Starting sync…' }}
            </button>
            <button v-else @click="syncPrepayments(c)"
              class="btn btn-secondary btn-sm text-xs">
              ↻ Sync Prepayments
            </button>
            <button v-if="c.status !== 'active'" @click="setActive(c)"
              class="btn btn-secondary btn-sm">Set Active</button>
            <button @click="openEdit(c)" class="btn btn-ghost btn-sm">Edit</button>
            <button @click="confirmDelete(c)" class="btn btn-ghost btn-sm text-red-400 hover:text-red-300">Delete</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Camp modal -->
    <AppModal v-model="showModal" :title="editing ? 'Edit Camp' : 'New Camp'">
      <form @submit.prevent="save" class="space-y-4">

        <div class="grid grid-cols-2 gap-3">
          <div class="col-span-2">
            <label class="field-label">Camp Name *</label>
            <input v-model="form.name" type="text" required placeholder="e.g. Carrickalinga Camp 2026" />
          </div>
          <div>
            <label class="field-label">Start Date *</label>
            <input v-model="form.start_date" type="date" required />
          </div>
          <div>
            <label class="field-label">End Date *</label>
            <input v-model="form.end_date" type="date" required />
          </div>
          <div>
            <label class="field-label">Status</label>
            <select v-model="form.status">
              <option value="draft">Draft</option>
              <option value="active">Active</option>
              <option value="closed">Closed</option>
            </select>
          </div>
          <div>
            <label class="field-label">Location</label>
            <input v-model="form.location" type="text" placeholder="e.g. Carrickalinga, SA" />
          </div>
        </div>

        <hr class="border-surface-600" />
        <p class="text-xs text-ink-500 font-medium uppercase tracking-wide">Peak Period (optional)</p>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="field-label">On-peak Start</label>
            <input v-model="form.on_peak_start" type="date" />
          </div>
          <div>
            <label class="field-label">On-peak End</label>
            <input v-model="form.on_peak_end" type="date" />
          </div>
        </div>

        <hr class="border-surface-600" />
        <div>
          <label class="field-label">Banner Image URL</label>
          <input v-model="form.banner_image" type="url" placeholder="https://…" />
        </div>

      </form>
      <template #footer>
        <button @click="closeModal" class="btn btn-ghost">Cancel</button>
        <button @click="save" :disabled="saving" class="btn btn-primary">
          {{ saving ? 'Saving…' : 'Save Camp' }}
        </button>
      </template>
    </AppModal>

    <!-- Delete confirmation modal -->
    <AppModal v-model="showDeleteModal" title="Delete Camp">
      <p class="text-ink-300">Are you sure you want to delete <strong class="text-ink-100">{{ deleting?.name }}</strong>? This cannot be undone.</p>
      <template #footer>
        <button @click="showDeleteModal = false" class="btn btn-ghost">Cancel</button>
        <button @click="doDelete" :disabled="saving" class="btn bg-red-600 hover:bg-red-500 text-white">
          {{ saving ? 'Deleting…' : 'Delete' }}
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

const loading         = ref(true)
const saving          = ref(false)
const showModal       = ref(false)
const showDeleteModal = ref(false)
const editing         = ref(null)
const deleting        = ref(null)
const camps           = ref([])
const syncingId       = ref(null)
const syncProgress    = ref(null)

const blank = () => ({
  name: '', status: 'draft', start_date: '', end_date: '',
  on_peak_start: '', on_peak_end: '', location: 'Carrickalinga',
  banner_image: ''
})
const form = ref(blank())

async function load() {
  loading.value = true
  try { camps.value = await api.camps.list() } catch {}
  loading.value = false
}

function openNew()   { editing.value = null; form.value = blank(); showModal.value = true }
function openEdit(c) { editing.value = c; form.value = { ...c, on_peak_start: c.on_peak_start || '', on_peak_end: c.on_peak_end || '', banner_image: c.banner_image || '' }; showModal.value = true }
function closeModal() { showModal.value = false }

function confirmDelete(c) { deleting.value = c; showDeleteModal.value = true }

function buildPayload() {
  const d = { ...form.value }
  d.year = d.start_date ? new Date(d.start_date).getFullYear() : new Date().getFullYear()
  d.on_peak_start = d.on_peak_start || null
  d.on_peak_end   = d.on_peak_end   || null
  d.banner_image  = d.banner_image  || null
  d.emergency_contact   = d.emergency_contact   || null
  d.first_aid_location  = d.first_aid_location  || null
  return d
}

async function save() {
  saving.value = true
  try {
    const payload = buildPayload()
    if (editing.value) {
      await api.camps.update(editing.value.id, payload)
      if (payload.status === 'active') await api.camps.setActive(editing.value.id)
      toast?.add('Camp updated', 'success')
    } else {
      const res = await api.camps.create(payload)
      if (payload.status === 'active' && res?.id) await api.camps.setActive(res.id)
      toast?.add('Camp created', 'success')
    }
    closeModal()
    await load()
  } catch (e) {
    toast?.add(e?.data?.message || 'Save failed', 'error')
  } finally {
    saving.value = false
  }
}

async function syncPrepayments(camp) {
  syncingId.value = camp.id
  syncProgress.value = null
  let token = null
  try {
    while (true) {
      const res = await api.churchsuite.syncCamp(camp.id, token ? { sync_token: token } : {})
      if (!res.success) throw new Error(res.message || 'Sync failed')
      if (res.in_progress) {
        token = res.sync_token
        syncProgress.value = res.progress ?? null
      } else {
        const s = res.summary ?? {}
        const parts = []
        if (s.created)   parts.push(`${s.created} new`)
        if (s.updated)   parts.push(`${s.updated} updated`)
        if (s.unchanged) parts.push(`${s.unchanged} unchanged`)
        if (s.warnings)  parts.push(`${s.warnings} warnings`)
        toast?.add('Sync complete: ' + (parts.join(', ') || 'no changes'), 'success')
        await load()
        break
      }
    }
  } catch (e) {
    toast?.add(e?.data?.message || e?.message || 'Sync failed', 'error')
  } finally {
    syncingId.value = null
    syncProgress.value = null
  }
}

async function setActive(c) {
  try {
    await api.camps.setActive(c.id)
    toast?.add(`${c.name} set as active`, 'success')
    await load()
  } catch {
    toast?.add('Failed to set active camp', 'error')
  }
}

async function doDelete() {
  saving.value = true
  try {
    await api.camps.delete(deleting.value.id)
    toast?.add('Camp deleted', 'success')
    showDeleteModal.value = false
    await load()
  } catch {
    toast?.add('Delete failed', 'error')
  } finally {
    saving.value = false
  }
}

function statusBadge(status) {
  return {
    draft:  'badge bg-surface-600 text-ink-400',
    active: 'badge badge-ember',
    closed: 'badge bg-surface-700 text-ink-500',
  }[status] || 'badge bg-surface-600 text-ink-400'
}

function formatDate(d) {
  if (!d) return ''
  const [y, m, day] = d.split('-')
  return `${parseInt(day)} ${['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][parseInt(m)-1]} ${y}`
}
function formatDateTime(d) {
  if (!d) return ''
  return new Date(d).toLocaleString('en-AU', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
}

onMounted(load)
</script>
