<template>
  <div class="p-4 space-y-4 animate-fade-in">
    <div class="flex items-center justify-between">
      <h2 class="text-xl font-bold text-ink-100">Noticeboard</h2>
      <button @click="showPost = true" class="btn btn-primary btn-sm">+ Post</button>
    </div>
    <LoadingSpinner v-if="loading" :full="true" />
    <EmptyState v-else-if="!notices.length" icon="📌" title="No notices yet" subtitle="Be the first to post something." />
    <div v-else class="space-y-3">
      <div v-for="n in notices" :key="n.id" class="card p-4">
        <div class="flex items-start justify-between gap-2 mb-2">
          <span class="badge badge-ember text-xs">{{ n.category }}</span>
          <span class="text-xs text-ink-500">{{ timeAgo(n.created_at) }}</span>
        </div>
        <h3 class="font-semibold text-ink-100 mb-1">{{ n.title }}</h3>
        <p class="text-sm text-ink-300 leading-relaxed">{{ n.message }}</p>
        <div v-if="n.contact_details || n.author_name" class="mt-2 pt-2 border-t border-surface-500 flex items-center gap-2 text-xs text-ink-500">
          <span v-if="n.author_name">{{ n.author_name }}</span>
          <span v-if="n.author_name && n.site_number">· Site {{ n.site_number }}</span>
          <span v-if="n.contact_details">· {{ n.contact_details }}</span>
        </div>
      </div>
    </div>
    <AppModal :open="showPost" title="Post a Notice" @close="showPost = false">
      <form @submit.prevent="submitPost" class="space-y-4">
        <div>
          <label class="field-label">Your Name</label>
          <input v-model="form.author_name" required placeholder="Your name" />
        </div>
        <div>
          <label class="field-label">Site Number</label>
          <input v-model="form.site_number" required placeholder="e.g. 42" />
        </div>
        <div>
          <label class="field-label">Category</label>
          <select v-model="form.category">
            <option v-for="c in categories" :key="c">{{ c }}</option>
          </select>
        </div>
        <div>
          <label class="field-label">Title</label>
          <input v-model="form.title" required placeholder="Notice title" />
        </div>
        <div>
          <label class="field-label">Message</label>
          <textarea v-model="form.message" required rows="3" placeholder="What would you like to share?" class="w-full resize-none" />
        </div>
        <div>
          <label class="field-label">Contact Details (optional)</label>
          <input v-model="form.contact_details" placeholder="e.g. come find us at site 42" />
        </div>
      </form>
      <template #footer>
        <button @click="submitPost" :disabled="submitting" class="btn btn-primary w-full">
          {{ submitting ? 'Submitting...' : 'Submit Notice' }}
        </button>
      </template>
    </AppModal>
  </div>
</template>
<script setup>
import { ref, inject, onMounted } from 'vue'
import { publicApi } from '@/api.js'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import EmptyState from '@/components/EmptyState.vue'
import AppModal from '@/components/AppModal.vue'
const toast = inject('toast')
const loading = ref(true), showPost = ref(false), submitting = ref(false)
const notices = ref([])
const categories = ['General','For Sale','Wanted','Lost & Found','Event','Other']
const form = ref({ author_name:'', site_number:'', category:'General', title:'', message:'', contact_details:'' })
function timeAgo(ts) {
  const s = Math.floor((Date.now() - new Date(ts)) / 1000)
  if (s < 60) return 'just now'
  if (s < 3600) return Math.floor(s/60) + 'm ago'
  if (s < 86400) return Math.floor(s/3600) + 'h ago'
  return Math.floor(s/86400) + 'd ago'
}
async function load() {
  try { const d = await publicApi.features(); notices.value = (d.noticeboard || []).filter(n => n.status === 'approved') }
  catch {} finally { loading.value = false }
}
async function submitPost() {
  submitting.value = true
  try {
    await publicApi.submitNoticeboard(form.value)
    toast.add('Notice submitted for approval', 'success')
    showPost.value = false
    form.value = { author_name:'', site_number:'', category:'General', title:'', message:'', contact_details:'' }
  } catch { toast.add('Failed to submit', 'error') }
  finally { submitting.value = false }
}
onMounted(load)
</script>
