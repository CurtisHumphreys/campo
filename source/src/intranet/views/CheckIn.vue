<template>
  <div class="p-4 space-y-4 animate-fade-in">
    <h2 class="text-xl font-bold text-ink-100">Check In</h2>
    <p class="text-sm text-ink-400">Check in when you arrive at camp.</p>
    <div v-if="checkedIn" class="card p-6 text-center">
      <div class="text-4xl mb-3">✅</div>
      <h3 class="font-semibold text-ink-100 mb-1">Checked In!</h3>
      <p class="text-sm text-ink-400">Welcome to camp, {{ checkedIn }}!</p>
    </div>
    <form v-else @submit.prevent="submit" class="space-y-4">
      <div>
        <label class="field-label">Search Your Name</label>
        <input v-model="search" placeholder="Start typing your name..." @input="doSearch" />
        <div v-if="results.length" class="mt-2 card divide-y divide-surface-500 overflow-hidden">
          <button v-for="r in results" :key="r.id" type="button"
            @click="selectMember(r)"
            class="w-full text-left px-4 py-3 hover:bg-surface-600 transition-colors">
            <div class="font-medium text-ink-100 text-sm">{{ r.first_name }} {{ r.last_name }}</div>
            <div v-if="r.site_number" class="text-xs text-ink-400">Site {{ r.site_number }}</div>
          </button>
        </div>
      </div>
      <div v-if="selected">
        <div class="card p-3 flex items-center gap-3 mb-4">
          <div class="w-10 h-10 rounded-xl bg-ember-500/20 flex items-center justify-center text-ember-400 font-bold">
            {{ selected.first_name[0] }}{{ selected.last_name[0] }}
          </div>
          <div>
            <div class="font-semibold text-ink-100 text-sm">{{ selected.first_name }} {{ selected.last_name }}</div>
            <div v-if="selected.site_number" class="text-xs text-ink-400">Site {{ selected.site_number }}</div>
          </div>
        </div>
        <button type="submit" :disabled="submitting" class="btn btn-primary w-full btn-lg">
          {{ submitting ? 'Checking in...' : 'Confirm Check In' }}
        </button>
      </div>
    </form>
  </div>
</template>
<script setup>
import { ref, inject } from 'vue'
import { publicApi } from '@/api.js'
const toast = inject('toast')
const search = ref(''), results = ref([]), selected = ref(null), submitting = ref(false), checkedIn = ref(null)
let searchTimer
function doSearch() {
  clearTimeout(searchTimer)
  if (search.value.length < 2) { results.value = []; return }
  searchTimer = setTimeout(async () => {
    try { const d = await publicApi.searchCheckIn(search.value); results.value = d.members || [] }
    catch { results.value = [] }
  }, 300)
}
function selectMember(m) { selected.value = m; results.value = []; search.value = m.first_name + ' ' + m.last_name }
async function submit() {
  if (!selected.value) return
  submitting.value = true
  try {
    await publicApi.checkIn({ member_id: selected.value.id, site_number: selected.value.site_number })
    checkedIn.value = selected.value.first_name
  } catch { toast.add('Check-in failed', 'error') }
  finally { submitting.value = false }
}
</script>
