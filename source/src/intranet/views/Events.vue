<template>
  <div class="p-4 space-y-4 animate-fade-in">
    <h2 class="text-xl font-bold text-ink-100">Events</h2>
    <LoadingSpinner v-if="loading" :full="true" />
    <EmptyState v-else-if="!events.length" icon="📅" title="No events" subtitle="Events will appear here." />
    <div v-else class="space-y-3">
      <div v-for="e in events" :key="e.id" class="card p-4">
        <h3 class="font-semibold text-ink-100 mb-1">{{ e.title }}</h3>
        <p v-if="e.description" class="text-sm text-ink-300 mb-2">{{ e.description }}</p>
        <div class="flex items-center gap-3 text-xs text-ink-500">
          <span v-if="e.date">📅 {{ fmtDate(e.date) }}</span>
          <span v-if="e.location">📍 {{ e.location }}</span>
        </div>
      </div>
    </div>
  </div>
</template>
<script setup>
import { ref, onMounted } from 'vue'
import { publicApi } from '@/api.js'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import EmptyState from '@/components/EmptyState.vue'
const loading = ref(true), events = ref([])
function fmtDate(d) { return new Date(d).toLocaleDateString('en', { weekday:'short', month:'short', day:'numeric' }) }
onMounted(async () => {
  try { const d = await publicApi.features(); events.value = d.events || [] }
  catch {} finally { loading.value = false }
})
</script>
