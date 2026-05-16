<template>
  <div class="p-4 space-y-4 animate-fade-in">
    <div class="flex items-center justify-between">
      <h2 class="text-xl font-bold text-ink-100">Lost & Found</h2>
      <button @click="showForm = true" class="btn btn-primary btn-sm">+ Report</button>
    </div>
    <div class="flex gap-2">
      <button v-for="f in ['all','lost','found']" :key="f" @click="filter = f"
        class="btn btn-sm capitalize" :class="filter === f ? 'btn-primary' : 'btn-secondary'">{{ f }}</button>
    </div>
    <LoadingSpinner v-if="loading" :full="true" />
    <EmptyState v-else-if="!filtered.length" icon="🔍" title="Nothing here" subtitle="No items reported yet." />
    <div v-else class="space-y-3">
      <div v-for="item in filtered" :key="item.id" class="card p-4">
        <div class="flex items-start justify-between gap-2 mb-2">
          <span class="badge" :class="item.item_type === 'lost' ? 'badge-red' : 'badge-sage'">
            {{ item.item_type === 'lost' ? '🔴 Lost' : '🟢 Found' }}
          </span>
          <span class="text-xs text-ink-500">{{ timeAgo(item.created_at) }}</span>
        </div>
        <h3 class="font-semibold text-ink-100 mb-1">{{ item.title }}</h3>
        <p class="text-sm text-ink-300 leading-relaxed">{{ item.description }}</p>
        <div v-if="item.location_details" class="mt-2 text-xs text-ink-500">📍 {{ item.location_details }}</div>
        <div class="mt-2 pt-2 border-t border-surface-500 text-xs text-ink-500 flex gap-2">
          <span>{{ item.reporter_name }}</span>
          <span v-if="item.site_number">· Site {{ item.site_number }}</span>
          <span v-if="item.contact_details">· {{ item.contact_details }}</span>
        </div>
      </div>
    </div>
    <AppModal v-model="showForm" title="Report Lost or Found Item">
      <form @submit.prevent="submit" class="space-y-4">
        <div class="grid grid-cols-2 gap-2">
          <button type="button" @click="form.item_type='lost'" class="btn" :class="form.item_type==='lost' ? 'btn-danger' : 'btn-secondary'">🔴 Lost Item</button>
          <button type="button" @click="form.item_type='found'" class="btn" :class="form.item_type==='found' ? 'btn-success' : 'btn-secondary'">🟢 Found Item</button>
        </div>
        <div><label class="field-label">Your Name</label><input v-model="form.reporter_name" required /></div>
        <div><label class="field-label">Your Site Number</label><input v-model="form.site_number" required /></div>
        <div><label class="field-label">Item Title</label><input v-model="form.title" required placeholder="e.g. Blue backpack" /></div>
        <div><label class="field-label">Description</label><textarea v-model="form.description" required rows="3" class="w-full resize-none" placeholder="Describe the item..." /></div>
        <div><label class="field-label">Location (optional)</label><input v-model="form.location_details" placeholder="Where was it lost/found?" /></div>
        <div><label class="field-label">Contact Details (optional)</label><input v-model="form.contact_details" placeholder="Best way to reach you" /></div>
        <button type="submit" :disabled="submitting" class="btn btn-primary w-full btn-lg">{{ submitting ? 'Submitting...' : 'Submit Report' }}</button>
      </form>
    </AppModal>
  </div>
</template>
<script setup>
import { ref, computed, inject, onMounted } from 'vue'
import { publicApi } from '@/api.js'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import EmptyState from '@/components/EmptyState.vue'
import AppModal from '@/components/AppModal.vue'
const toast = inject('toast')
const loading = ref(true), showForm = ref(false), submitting = ref(false), filter = ref('all')
const items = ref([])
const filtered = computed(() => filter.value === 'all' ? items.value : items.value.filter(i => i.item_type === filter.value))
const form = ref({ item_type:'found', reporter_name:'', site_number:'', title:'', description:'', location_details:'', contact_details:'' })
function timeAgo(ts) { const s=Math.floor((Date.now()-new Date(ts))/1000); if(s<60)return 'just now'; if(s<3600)return Math.floor(s/60)+'m ago'; if(s<86400)return Math.floor(s/3600)+'h ago'; return Math.floor(s/86400)+'d ago' }
async function submit() {
  submitting.value = true
  try { await publicApi.submitLostFound(form.value); toast.add('Report submitted!', 'success'); showForm.value = false; form.value = { item_type:'found', reporter_name:'', site_number:'', title:'', description:'', location_details:'', contact_details:'' } }
  catch { toast.add('Failed to submit', 'error') }
  finally { submitting.value = false }
}
onMounted(async () => {
  try { const d = await publicApi.features(); items.value = (d.lost_found || []).filter(i => i.status === 'approved') }
  catch {} finally { loading.value = false }
})
</script>
