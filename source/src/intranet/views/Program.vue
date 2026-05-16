<template>
  <div class="p-4 space-y-4 animate-fade-in">
    <div class="flex items-center justify-between">
      <h2 class="text-xl font-bold text-ink-100">Program</h2>
      <span v-if="offline" class="badge badge-muted">Offline</span>
    </div>

    <LoadingSpinner v-if="loading" :full="true" label="Loading program..." />

    <EmptyState v-else-if="!sessions.length"
      icon="📋" title="No program yet"
      subtitle="The camp program will appear here once it's published." />

    <template v-else>
      <!-- Day selector -->
      <div class="flex gap-2 overflow-x-auto pb-1 -mx-4 px-4 snap-x">
        <button v-for="day in days" :key="day.key"
          @click="activeDay = day.key"
          class="flex-none snap-start flex flex-col items-center px-3 py-2 rounded-xl
                 min-w-[60px] transition-all"
          :class="activeDay === day.key
            ? 'bg-ember-500 text-surface-900'
            : 'bg-surface-700 text-ink-400 border border-surface-500'">
          <span class="text-[10px] font-semibold uppercase">{{ day.short }}</span>
          <span class="text-lg font-bold leading-tight">{{ day.num }}</span>
        </button>
      </div>

      <!-- Sessions for active day -->
      <div class="space-y-3">
        <div v-for="s in activeSessions" :key="s.id"
          class="card p-4 flex gap-4">
          <div class="flex-none text-center min-w-[52px]">
            <div class="text-ember-400 font-bold text-sm">{{ s.start_time }}</div>
            <div v-if="s.end_time" class="text-ink-500 text-xs">{{ s.end_time }}</div>
          </div>
          <div class="flex-1 min-w-0">
            <h3 class="font-semibold text-ink-100 leading-snug">{{ s.title }}</h3>
            <p v-if="s.description" class="text-sm text-ink-400 mt-1 leading-relaxed">
              {{ s.description }}
            </p>
            <div v-if="s.location" class="flex items-center gap-1 mt-2">
              <span class="text-xs text-ink-500">📍 {{ s.location }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Raw program text fallback -->
      <div v-if="!sessions.length && programText" class="card p-4">
        <p class="text-sm text-ink-300 whitespace-pre-wrap leading-relaxed">{{ programText }}</p>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { publicApi } from '@/api.js'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import EmptyState from '@/components/EmptyState.vue'

const loading     = ref(true)
const offline     = ref(false)
const sessions    = ref([])
const programText = ref('')
const activeDay   = ref(null)

const CACHE_KEY = 'campo_program_cache'

const days = computed(() => {
  const map = {}
  sessions.value.forEach(s => {
    if (!s.date) return
    const d = new Date(s.date)
    const k = s.date
    if (!map[k]) {
      map[k] = {
        key: k,
        short: d.toLocaleDateString('en', { weekday: 'short' }),
        num: d.getDate(),
        full: d.toLocaleDateString('en', { weekday: 'long', month: 'short', day: 'numeric' })
      }
    }
  })
  return Object.values(map).sort((a, b) => a.key.localeCompare(b.key))
})

const activeSessions = computed(() =>
  sessions.value.filter(s => s.date === activeDay.value)
    .sort((a, b) => (a.start_time || '').localeCompare(b.start_time || ''))
)

onMounted(async () => {
  // Try cache first for offline
  const cached = localStorage.getItem(CACHE_KEY)
  if (cached) {
    try {
      const c = JSON.parse(cached)
      sessions.value = c.sessions || []
      programText.value = c.program || ''
    } catch {}
  }
  try {
    const data = await publicApi.features()
    const prog = data.program || {}
    sessions.value = prog.sessions || []
    programText.value = prog.text || ''
    localStorage.setItem(CACHE_KEY, JSON.stringify({ sessions: sessions.value, program: programText.value, ts: Date.now() }))
    offline.value = false
  } catch {
    offline.value = !!cached
  }
  if (days.value.length) activeDay.value = days.value[0].key
  loading.value = false
})
</script>
