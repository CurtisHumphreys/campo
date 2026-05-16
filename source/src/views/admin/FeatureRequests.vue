<template>
  <div class="p-6 max-w-6xl mx-auto space-y-6">

    <!-- Header -->
    <div class="flex items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold text-ink-100">Feature Requests</h1>
        <p class="text-sm text-ink-500 mt-0.5">Submit bugs and feature ideas</p>
      </div>
      <button @click="showForm = !showForm" class="btn btn-primary btn-sm">
        {{ showForm ? 'Cancel' : '+ Submit' }}
      </button>
    </div>

    <!-- Submit form -->
    <div v-if="showForm" class="card p-5 space-y-4">
      <div class="grid grid-cols-2 gap-3">
        <button
          v-for="t in types" :key="t.value"
          @click="form.type = t.value"
          :class="[
            'flex items-start gap-3 p-4 rounded-xl border-2 text-left transition-all',
            form.type === t.value
              ? t.activeClass
              : 'border-surface-700 bg-surface-800 hover:border-surface-500',
          ]"
        >
          <span class="text-2xl leading-none mt-0.5">{{ t.icon }}</span>
          <div>
            <div :class="['font-semibold text-sm', form.type === t.value ? t.labelColor : 'text-ink-200']">
              {{ t.label }}
            </div>
            <div class="text-xs text-ink-500 mt-0.5">{{ t.hint }}</div>
          </div>
          <span v-if="form.type === t.value" class="ml-auto text-xs font-bold" :class="t.labelColor">✓</span>
        </button>
      </div>
      <div>
        <label class="field-label">Title *</label>
        <input v-model="form.title" type="text"
          :placeholder="form.type === 'bug' ? 'Brief summary of the bug…' : 'Brief summary of the feature…'"
          class="w-full" />
      </div>
      <div>
        <label class="field-label">Description</label>
        <textarea v-model="form.description" rows="3" class="resize-none w-full"
          :placeholder="form.type === 'bug' ? 'Steps to reproduce, expected vs actual behaviour…' : 'What you\'d like and why it would help…'" />
      </div>
      <div class="flex justify-end gap-2">
        <button @click="showForm = false" class="btn btn-ghost">Cancel</button>
        <button @click="submit" :disabled="saving || !form.title.trim()" class="btn btn-primary">
          {{ saving ? 'Submitting…' : 'Submit' }}
        </button>
      </div>
    </div>

    <LoadingSpinner v-if="loading" :full="true" />

    <template v-else>

      <!-- Pending — two columns -->
      <section class="space-y-3">
        <div class="text-xs font-semibold uppercase tracking-wide text-ink-500">
          Pending · {{ pendingBugs.length + pendingFeatures.length }}
        </div>
        <EmptyState v-if="!pendingBugs.length && !pendingFeatures.length" icon="✅" title="Nothing pending"
          subtitle="No open feature requests or bugs." />
        <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-4">

          <!-- Bugs column -->
          <div class="space-y-2">
            <div class="text-xs font-semibold text-red-400 uppercase tracking-wide flex items-center gap-1.5">
              🐛 Bugs <span class="text-ink-600 font-normal normal-case tracking-normal">({{ pendingBugs.length }})</span>
            </div>
            <EmptyState v-if="!pendingBugs.length" icon="🐛" title="No bugs"
              subtitle="All clear." class="!py-6 !text-sm" />
            <VueDraggable v-else v-model="pendingBugs" :animation="150" handle=".drag-handle"
              class="space-y-2" @end="saveBugOrder">
              <div v-for="item in pendingBugs" :key="item.id" class="card p-4 space-y-2">
                <div class="flex items-start gap-2">
                  <span class="drag-handle cursor-grab active:cursor-grabbing text-ink-600 flex-none self-center select-none" title="Drag to reorder">
                    <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M8 6a2 2 0 110-4 2 2 0 010 4zM8 14a2 2 0 110-4 2 2 0 010 4zM8 22a2 2 0 110-4 2 2 0 010 4zM16 6a2 2 0 110-4 2 2 0 010 4zM16 14a2 2 0 110-4 2 2 0 010 4zM16 22a2 2 0 110-4 2 2 0 010 4z"/></svg>
                  </span>
                  <span class="badge bg-red-500/15 text-red-400 mt-0.5 flex-none">Bug</span>
                  <div class="flex-1 min-w-0">
                    <div class="font-medium text-ink-200 text-sm">{{ item.title }}</div>
                    <div v-if="item.description" class="text-xs text-ink-500 mt-0.5 whitespace-pre-wrap">{{ item.description }}</div>
                    <div class="text-xs text-ink-600 mt-1">by {{ item.submitter }} · {{ formatDate(item.created_at) }}</div>
                  </div>
                </div>
                <div class="flex gap-2 pt-1 border-t border-surface-700">
                  <button @click="promptAction(item)" class="btn btn-secondary btn-sm text-xs flex-1">⚡ Action</button>
                  <button @click="startEdit(item)" class="btn btn-ghost btn-sm text-xs flex-1">✏ Edit</button>
                  <button @click="markComplete(item)" class="btn btn-ghost btn-sm text-xs flex-1 text-green-400">✓ Complete</button>
                  <button @click="confirmDelete(item)" class="btn btn-ghost btn-sm text-xs text-red-400">Delete</button>
                </div>
              </div>
            </VueDraggable>
          </div>

          <!-- Features column -->
          <div class="space-y-2">
            <div class="text-xs font-semibold text-sky-400 uppercase tracking-wide flex items-center gap-1.5">
              ✨ Features <span class="text-ink-600 font-normal normal-case tracking-normal">({{ pendingFeatures.length }})</span>
            </div>
            <EmptyState v-if="!pendingFeatures.length" icon="✨" title="No feature requests"
              subtitle="Nothing queued." class="!py-6 !text-sm" />
            <VueDraggable v-else v-model="pendingFeatures" :animation="150" handle=".drag-handle"
              class="space-y-2" @end="saveFeatureOrder">
              <div v-for="item in pendingFeatures" :key="item.id" class="card p-4 space-y-2">
                <div class="flex items-start gap-2">
                  <span class="drag-handle cursor-grab active:cursor-grabbing text-ink-600 flex-none self-center select-none" title="Drag to reorder">
                    <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M8 6a2 2 0 110-4 2 2 0 010 4zM8 14a2 2 0 110-4 2 2 0 010 4zM8 22a2 2 0 110-4 2 2 0 010 4zM16 6a2 2 0 110-4 2 2 0 010 4zM16 14a2 2 0 110-4 2 2 0 010 4zM16 22a2 2 0 110-4 2 2 0 010 4z"/></svg>
                  </span>
                  <span class="badge bg-sky-500/15 text-sky-400 mt-0.5 flex-none">Feature</span>
                  <div class="flex-1 min-w-0">
                    <div class="font-medium text-ink-200 text-sm">{{ item.title }}</div>
                    <div v-if="item.description" class="text-xs text-ink-500 mt-0.5 whitespace-pre-wrap">{{ item.description }}</div>
                    <div class="text-xs text-ink-600 mt-1">by {{ item.submitter }} · {{ formatDate(item.created_at) }}</div>
                  </div>
                </div>
                <div class="flex gap-2 pt-1 border-t border-surface-700">
                  <button @click="promptAction(item)" class="btn btn-secondary btn-sm text-xs flex-1">⚡ Action</button>
                  <button @click="startEdit(item)" class="btn btn-ghost btn-sm text-xs flex-1">✏ Edit</button>
                  <button @click="markComplete(item)" class="btn btn-ghost btn-sm text-xs flex-1 text-green-400">✓ Complete</button>
                  <button @click="confirmDelete(item)" class="btn btn-ghost btn-sm text-xs text-red-400">Delete</button>
                </div>
              </div>
            </VueDraggable>
          </div>

        </div>
      </section>

      <!-- Completed -->
      <section v-if="completed.length" class="space-y-3">
        <div class="text-xs font-semibold uppercase tracking-wide text-ink-500">
          Completed · {{ completed.length }}
        </div>
        <div class="space-y-2">
          <div v-for="item in completed" :key="item.id"
            class="card p-4 opacity-60 flex items-start gap-3">
            <span :class="typeBadge(item.type)" class="mt-0.5 flex-none">{{ typeLabel(item.type) }}</span>
            <div class="flex-1 min-w-0">
              <div class="font-medium text-ink-300 line-through">{{ item.title }}</div>
              <div v-if="item.description" class="text-xs text-ink-600 mt-0.5 line-clamp-1">{{ item.description }}</div>
              <div class="text-xs text-ink-600 mt-1">
                by {{ item.submitter }} · completed {{ formatDate(item.completed_at) }}
              </div>
            </div>
            <button @click="confirmDelete(item)" class="btn btn-ghost btn-sm text-xs text-red-400 flex-none">Delete</button>
          </div>
        </div>
      </section>

    </template>

    <!-- Action password modal -->
    <AppModal v-model="showPasswordModal" title="Confirm Action">
      <div class="space-y-3">
        <p class="text-sm text-ink-400">Enter the password to open the AI assistant for this request.</p>
        <input ref="pwdInput" v-model="passwordEntry" type="password" placeholder="Password…"
          class="w-full" @keyup.enter="checkPassword" />
        <p v-if="passwordError" class="text-xs text-red-400">{{ passwordError }}</p>
      </div>
      <template #footer>
        <button @click="showPasswordModal = false" class="btn btn-ghost">Cancel</button>
        <button @click="checkPassword" class="btn btn-primary">Open Assistant</button>
      </template>
    </AppModal>

    <!-- Edit modal -->
    <AppModal v-model="showEditModal" title="Edit Request">
      <div class="space-y-4">
        <div class="grid grid-cols-2 gap-3">
          <button
            v-for="t in types" :key="t.value"
            @click="editForm.type = t.value"
            :class="[
              'flex items-center gap-2 p-3 rounded-xl border-2 text-left transition-all',
              editForm.type === t.value ? t.activeClass : 'border-surface-700 bg-surface-800 hover:border-surface-500',
            ]"
          >
            <span class="text-lg">{{ t.icon }}</span>
            <span :class="['font-semibold text-sm', editForm.type === t.value ? t.labelColor : 'text-ink-300']">{{ t.label }}</span>
            <span v-if="editForm.type === t.value" class="ml-auto text-xs font-bold" :class="t.labelColor">✓</span>
          </button>
        </div>
        <div>
          <label class="field-label">Title *</label>
          <input v-model="editForm.title" type="text" class="w-full" />
        </div>
        <div>
          <label class="field-label">Description</label>
          <textarea v-model="editForm.description" rows="3" class="resize-none w-full" />
        </div>
      </div>
      <template #footer>
        <button @click="showEditModal = false" class="btn btn-ghost">Cancel</button>
        <button @click="saveEdit" :disabled="editSaving || !editForm.title.trim()" class="btn btn-primary">
          {{ editSaving ? 'Saving…' : 'Save' }}
        </button>
      </template>
    </AppModal>

    <!-- Delete confirmation -->
    <AppModal v-model="showDeleteModal" title="Delete Request">
      <p class="text-sm text-ink-300">Delete <strong class="text-ink-100">{{ deleting?.title }}</strong>? This cannot be undone.</p>
      <template #footer>
        <button @click="showDeleteModal = false" class="btn btn-ghost">Cancel</button>
        <button @click="doDelete" :disabled="saving" class="btn bg-red-600 hover:bg-red-500 text-white btn-sm">
          {{ saving ? 'Deleting…' : 'Delete' }}
        </button>
      </template>
    </AppModal>

  </div>
</template>

<script setup>
import { ref, inject, onMounted, nextTick } from 'vue'
import { VueDraggable } from 'vue-draggable-plus'
import { api } from '@/api.js'
import AppModal from '@/components/AppModal.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import EmptyState from '@/components/EmptyState.vue'

const toast = inject('toast')

const loading           = ref(true)
const saving            = ref(false)
const editSaving        = ref(false)
const showForm          = ref(false)
const showPasswordModal = ref(false)
const showDeleteModal   = ref(false)
const showEditModal     = ref(false)
const pendingBugs       = ref([])
const pendingFeatures   = ref([])
const completed         = ref([])
const actionTarget      = ref(null)
const deleting          = ref(null)
const editTarget        = ref(null)
const passwordEntry     = ref('')
const passwordError     = ref('')
const pwdInput          = ref(null)

const form     = ref({ type: 'feature', title: '', description: '' })
const editForm = ref({ type: 'feature', title: '', description: '' })

const types = [
  {
    value: 'feature',
    label: 'Feature Request',
    icon: '✨',
    hint: 'Something new you\'d like to see added',
    activeClass: 'border-sky-500 bg-sky-500/10',
    labelColor: 'text-sky-400',
  },
  {
    value: 'bug',
    label: 'Bug Report',
    icon: '🐛',
    hint: 'Something that\'s broken or not working right',
    activeClass: 'border-red-500 bg-red-500/10',
    labelColor: 'text-red-400',
  },
]

function typeBadge(t) {
  return t === 'bug' ? 'badge bg-red-500/15 text-red-400' : 'badge bg-sky-500/15 text-sky-400'
}
function typeLabel(t) { return t === 'bug' ? 'Bug' : 'Feature' }

function formatDate(d) {
  if (!d) return ''
  return new Date(d).toLocaleString('en-AU', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

async function load() {
  loading.value = true
  try {
    const res = await api.featureRequests.list()
    const p = res.pending ?? []
    pendingBugs.value     = p.filter(i => i.type === 'bug')
    pendingFeatures.value = p.filter(i => i.type !== 'bug')
    completed.value       = res.completed ?? []
  } catch {}
  loading.value = false
}

async function submit() {
  if (!form.value.title.trim()) return
  saving.value = true
  try {
    await api.featureRequests.create({ ...form.value })
    toast?.add('Request submitted', 'success')
    form.value = { type: 'feature', title: '', description: '' }
    showForm.value = false
    await load()
  } catch { toast?.add('Submit failed', 'error') }
  finally { saving.value = false }
}

function startEdit(item) {
  editTarget.value = item
  editForm.value = { type: item.type, title: item.title, description: item.description }
  showEditModal.value = true
}

async function saveEdit() {
  if (!editForm.value.title.trim()) return
  editSaving.value = true
  try {
    await api.featureRequests.update(editTarget.value.id, { ...editForm.value })
    toast?.add('Saved', 'success')
    showEditModal.value = false
    await load()
  } catch { toast?.add('Save failed', 'error') }
  finally { editSaving.value = false }
}

async function saveBugOrder() {
  try {
    await api.featureRequests.reorder(pendingBugs.value.map(i => i.id))
  } catch {
    toast?.add('Failed to save order', 'error')
    await load()
  }
}

async function saveFeatureOrder() {
  try {
    await api.featureRequests.reorder(pendingFeatures.value.map(i => i.id))
  } catch {
    toast?.add('Failed to save order', 'error')
    await load()
  }
}

function promptAction(item) {
  actionTarget.value  = item
  passwordEntry.value = ''
  passwordError.value = ''
  showPasswordModal.value = true
  nextTick(() => pwdInput.value?.focus())
}

function checkPassword() {
  if (passwordEntry.value === 'yabadaba') {
    showPasswordModal.value = false
    window.open(`/code.php?id=${actionTarget.value.id}`, '_blank')
    actionTarget.value = null
  } else {
    passwordError.value = 'Incorrect password.'
    passwordEntry.value = ''
    pwdInput.value?.focus()
  }
}

async function markComplete(item) {
  try {
    await api.featureRequests.complete(item.id)
    toast?.add('Marked as complete', 'success')
    await load()
  } catch { toast?.add('Failed to mark complete', 'error') }
}

function confirmDelete(item) {
  deleting.value = item
  showDeleteModal.value = true
}

async function doDelete() {
  saving.value = true
  try {
    await api.featureRequests.delete(deleting.value.id)
    toast?.add('Deleted', 'success')
    showDeleteModal.value = false
    await load()
  } catch { toast?.add('Delete failed', 'error') }
  finally { saving.value = false }
}

onMounted(load)
</script>
