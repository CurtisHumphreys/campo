<template>
  <div class="flex flex-col h-dvh bg-surface-800 overflow-hidden">
    <!-- Top bar -->
    <header class="flex-none bg-surface-900/80 backdrop-blur-md border-b border-surface-600 pt-safe">
      <div class="flex items-center justify-between px-4 py-3">
        <div class="flex items-center gap-2">
          <div class="w-7 h-7 rounded-lg bg-ember-500 flex items-center justify-center">
            <span class="text-surface-900 font-black text-xs">C</span>
          </div>
          <div>
            <h1 class="text-sm font-bold text-ink-100 leading-none">CAMPO</h1>
            <p v-if="campName" class="text-[10px] text-ink-500 leading-none mt-0.5">{{ campName }}</p>
          </div>
        </div>
        <div class="flex items-center gap-1">
          <button @click="showMore = true"
            class="btn-ghost btn p-2 rounded-xl text-ink-400">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M4 6h16M4 12h16M4 18h16"/>
            </svg>
          </button>
        </div>
      </div>
    </header>

    <!-- Page content -->
    <main class="flex-1 overflow-y-auto">
      <RouterView v-slot="{ Component }">
        <Transition name="page" mode="out-in">
          <component :is="Component" />
        </Transition>
      </RouterView>
    </main>

    <!-- Bottom nav -->
    <nav class="flex-none bg-surface-900/90 backdrop-blur-md border-t border-surface-600 pb-safe">
      <div class="flex items-stretch">
        <RouterLink v-for="tab in tabs" :key="tab.name"
          :to="tab.path"
          class="flex-1 flex flex-col items-center justify-center gap-1 py-2.5 min-h-[56px]
                 text-ink-500 transition-colors relative"
          :class="isActive(tab.path) ? 'text-ember-400' : 'hover:text-ink-300'">
          <div class="relative">
            <component :is="tab.icon" class="w-5 h-5" />
            <span v-if="tab.badge" class="absolute -top-1 -right-1 w-4 h-4 bg-ember-500
              rounded-full text-[10px] font-bold text-surface-900 flex items-center justify-center">
              {{ tab.badge }}
            </span>
          </div>
          <span class="text-[10px] font-medium leading-none">{{ tab.label }}</span>
          <div v-if="isActive(tab.path)"
            class="absolute bottom-0 left-1/2 -translate-x-1/2 w-6 h-0.5
                   bg-ember-500 rounded-full" />
        </RouterLink>
      </div>
    </nav>

    <!-- More menu -->
    <AppModal v-model="showMore" title="More">
      <div class="grid grid-cols-2 gap-3">
        <RouterLink v-for="item in moreItems" :key="item.name"
          :to="item.path" @click="showMore = false"
          class="card-hover flex flex-col items-center gap-2 p-4 text-center rounded-2xl">
          <span class="text-2xl">{{ item.emoji }}</span>
          <span class="text-sm font-medium text-ink-200">{{ item.label }}</span>
        </RouterLink>
      </div>
    </AppModal>

    <AppToast ref="toast" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, provide } from 'vue'
import { useRoute } from 'vue-router'
import { publicApi } from '@/api.js'
import AppModal from '@/components/AppModal.vue'
import AppToast from '@/components/AppToast.vue'
import IconProgram from './icons/IconProgram.vue'
import IconMap from './icons/IconMap.vue'
import IconNotice from './icons/IconNotice.vue'
import IconPoll from './icons/IconPoll.vue'
import IconLostFound from './icons/IconLostFound.vue'

const route    = useRoute()
const toast    = ref(null)
const showMore = ref(false)
const campData = ref(null)
const campName = computed(() => campData.value?.camp?.name)

provide('toast', { add: (...a) => toast.value?.add(...a) })
provide('campData', campData)

const tabs = [
  { name: 'program',     path: '/intranet/',          label: 'Program',     icon: IconProgram },
  { name: 'map',         path: '/intranet/map',        label: 'Map',         icon: IconMap },
  { name: 'noticeboard', path: '/intranet/noticeboard',label: 'Notices',     icon: IconNotice },
  { name: 'polls',       path: '/intranet/polls',      label: 'Polls',       icon: IconPoll },
  { name: 'lost-found',  path: '/intranet/lost-found', label: 'Lost & Found',icon: IconLostFound },
]

const moreItems = [
  { name: 'ask-admin', path: '/intranet/ask-admin', label: 'Ask Admin',  emoji: '💬' },
  { name: 'events',    path: '/intranet/events',    label: 'Events',     emoji: '📅' },
  { name: 'check-in',  path: '/intranet/check-in',  label: 'Check In',   emoji: '✅' },
  { name: 'waitlist',  path: '/intranet/waitlist',  label: 'Site Waitlist', emoji: '📋' },
]

function isActive(path) {
  if (path === '/intranet/') return route.path === '/intranet/' || route.path === '/intranet'
  return route.path.startsWith(path)
}

onMounted(async () => {
  try { campData.value = await publicApi.intranet() } catch {}
})
</script>

<style scoped>
.page-enter-active, .page-leave-active { transition: opacity .15s ease; }
.page-enter-from, .page-leave-to { opacity: 0; }
</style>
