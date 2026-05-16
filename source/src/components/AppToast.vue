<template>
  <Teleport to="body">
    <div class="fixed top-4 right-4 z-[9999] flex flex-col gap-2 pointer-events-none">
      <TransitionGroup name="toast">
        <div v-for="t in toasts" :key="t.id"
          class="pointer-events-auto flex items-center gap-3 px-4 py-3 rounded-xl shadow-modal
                 border min-w-[260px] max-w-sm"
          :class="styles[t.type]">
          <span class="text-base flex-none">{{ icons[t.type] }}</span>
          <p class="text-sm font-medium leading-snug flex-1">{{ t.message }}</p>
          <button v-if="t.action" @click="doAction(t)"
            class="text-xs font-semibold text-ember-400 hover:text-ember-300 flex-none
                   py-0.5 px-2 rounded border border-ember-500/40 hover:border-ember-400/60 transition-colors whitespace-nowrap">
            {{ t.action.label }}
          </button>
        </div>
      </TransitionGroup>
    </div>
  </Teleport>
</template>

<script setup>
import { ref } from 'vue'

const toasts = ref([])
const timers = new Map()
let seq = 0

const styles = {
  success: 'bg-surface-700 border-sage-600/50 text-ink-100',
  error:   'bg-surface-700 border-red-500/50 text-ink-100',
  info:    'bg-surface-700 border-ember-500/50 text-ink-100',
  warning: 'bg-surface-700 border-amber-500/50 text-ink-100',
}
const icons = { success: '✓', error: '✕', info: 'ℹ', warning: '⚠' }

function remove(id) {
  toasts.value = toasts.value.filter(t => t.id !== id)
  timers.delete(id)
}

function add(message, type = 'info', duration = 3500, action = null) {
  const id = ++seq
  toasts.value.push({ id, message, type, action })
  const timer = setTimeout(() => remove(id), duration)
  timers.set(id, timer)
  return id
}

function doAction(t) {
  const timer = timers.get(t.id)
  if (timer) clearTimeout(timer)
  remove(t.id)
  t.action?.fn?.()
}

defineExpose({ add, remove })
</script>

<style scoped>
.toast-enter-active, .toast-leave-active { transition: all .25s ease; }
.toast-enter-from { opacity: 0; transform: translateX(16px); }
.toast-leave-to   { opacity: 0; transform: translateX(16px); }
</style>
