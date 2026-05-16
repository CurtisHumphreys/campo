<template>
  <div class="p-4 space-y-4 animate-fade-in">
    <h2 class="text-xl font-bold text-ink-100">Polls</h2>
    <LoadingSpinner v-if="loading" :full="true" />
    <EmptyState v-else-if="!polls.length" icon="🗳️" title="No active polls" subtitle="Check back later." />
    <div v-else class="space-y-4">
      <div v-for="poll in polls" :key="poll.id" class="card p-4 space-y-3">
        <h3 class="font-semibold text-ink-100">{{ poll.title }}</h3>
        <p v-if="poll.description" class="text-sm text-ink-400">{{ poll.description }}</p>
        <div v-if="!voted[poll.id]" class="space-y-2">
          <button v-for="opt in poll.options" :key="opt.id"
            @click="vote(poll, opt)"
            class="w-full text-left p-3 rounded-xl border border-surface-500 bg-surface-600
                   hover:border-ember-500 hover:bg-surface-500 transition-all text-sm text-ink-200">
            {{ opt.label }}
          </button>
          <div class="pt-1">
            <input v-model="voteNames[poll.id]" placeholder="Your name" class="w-full text-sm mb-2" />
            <input v-model="voteSites[poll.id]" placeholder="Site number" class="w-full text-sm" />
          </div>
        </div>
        <div v-else class="space-y-2">
          <div v-if="poll.show_results_public" v-for="opt in poll.options" :key="opt.id">
            <div class="flex justify-between text-sm mb-1">
              <span class="text-ink-300">{{ opt.label }}</span>
              <span class="text-ink-400">{{ opt.votes || 0 }}</span>
            </div>
            <div class="h-2 bg-surface-600 rounded-full overflow-hidden">
              <div class="h-full bg-ember-500 rounded-full transition-all"
                :style="{ width: pct(poll, opt) + '%' }" />
            </div>
          </div>
          <p v-else class="text-sm text-sage-400">✓ Vote recorded. Thank you!</p>
        </div>
        <div v-if="poll.closes_at" class="text-xs text-ink-500">Closes {{ fmtDate(poll.closes_at) }}</div>
      </div>
    </div>
  </div>
</template>
<script setup>
import { ref, inject, onMounted } from 'vue'
import { publicApi } from '@/api.js'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import EmptyState from '@/components/EmptyState.vue'
const toast = inject('toast')
const loading = ref(true), polls = ref([])
const voted = ref({}), voteNames = ref({}), voteSites = ref({})
const VOTED_KEY = 'campo_voted_polls'

function pct(poll, opt) {
  const total = poll.options.reduce((s,o) => s + (o.votes||0), 0)
  return total ? Math.round((opt.votes||0)/total*100) : 0
}
function fmtDate(d) { return new Date(d).toLocaleDateString('en', { weekday:'short', month:'short', day:'numeric' }) }

async function vote(poll, opt) {
  const name = voteNames.value[poll.id] || '', site = voteSites.value[poll.id] || ''
  if (!name || !site) { toast.add('Please enter your name and site number', 'warning'); return }
  try {
    await publicApi.submitPollResponse({ poll_id: poll.id, option_id: opt.id, responder_name: name, site_number: site, response_key: `${poll.id}_${name}_${site}` })
    voted.value[poll.id] = true
    const saved = JSON.parse(localStorage.getItem(VOTED_KEY) || '{}')
    saved[poll.id] = true
    localStorage.setItem(VOTED_KEY, JSON.stringify(saved))
    toast.add('Vote recorded!', 'success')
  } catch { toast.add('Failed to submit vote', 'error') }
}
onMounted(async () => {
  const saved = JSON.parse(localStorage.getItem(VOTED_KEY) || '{}')
  voted.value = saved
  try { const d = await publicApi.features(); polls.value = (d.polls || []).filter(p => p.status === 'active') }
  catch {} finally { loading.value = false }
})
</script>
