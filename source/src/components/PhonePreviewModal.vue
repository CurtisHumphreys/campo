<template>
  <Teleport to="body">
    <Transition name="preview">
      <div v-if="modelValue"
        class="fixed inset-0 z-50 flex items-center justify-center p-4"
        @click.self="close">
        <div class="absolute inset-0 bg-black/75 backdrop-blur-sm" @click="close" />

        <div class="relative flex flex-col items-center gap-4">
          <!-- Header bar -->
          <div class="flex items-center justify-between w-full max-w-sm">
            <span class="text-ink-300 text-sm font-medium">📲 Phone Preview</span>
            <div class="flex items-center gap-2">
              <button @click="reload" title="Reload"
                class="btn-ghost btn p-1.5 rounded-lg text-ink-400 hover:text-ink-100">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
              </button>
              <a :href="currentUrl" target="_blank" title="Open in new tab"
                class="btn-ghost btn p-1.5 rounded-lg text-ink-400 hover:text-ink-100">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                </svg>
              </a>
              <button @click="close"
                class="btn-ghost btn p-1.5 rounded-lg text-ink-400 hover:text-ink-100">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>
          </div>

          <!-- App switcher -->
          <div class="flex items-center bg-surface-700 rounded-xl p-1 gap-1 w-full max-w-sm">
            <button v-for="app in apps" :key="app.id"
              @click="switchApp(app.id)"
              class="flex-1 flex items-center justify-center gap-1.5 py-2 px-3 rounded-lg text-sm font-medium transition-all"
              :class="activeApp === app.id
                ? 'bg-ember-500 text-surface-900 shadow-ember'
                : 'text-ink-400 hover:text-ink-200'">
              <span class="text-base leading-none">{{ app.icon }}</span>
              {{ app.label }}
            </button>
          </div>

          <!-- Phone shell -->
          <div class="phone-shell">
            <!-- Notch -->
            <div class="phone-notch" />
            <!-- Screen -->
            <div class="phone-screen">
              <div v-if="loading" class="flex items-center justify-center h-full bg-surface-800">
                <div class="flex flex-col items-center gap-3">
                  <div class="w-8 h-8 border-2 border-ember-500 border-t-transparent rounded-full animate-spin" />
                  <span class="text-ink-500 text-xs">Loading…</span>
                </div>
              </div>
              <iframe
                ref="frame"
                :src="currentUrl"
                @load="loading = false"
                class="w-full h-full border-0"
                :class="loading ? 'opacity-0 absolute' : ''"
                allow="geolocation"
                sandbox="allow-scripts allow-same-origin allow-forms allow-popups"
                :title="apps.find(a => a.id === activeApp)?.label + ' Preview'"
              />
            </div>
            <!-- Home indicator -->
            <div class="phone-home" />
          </div>

          <p class="text-ink-600 text-xs">This is a live preview — changes you make are reflected here.</p>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { ref, computed, watch } from 'vue'

const props = defineProps({ modelValue: Boolean })
const emit  = defineEmits(['update:modelValue'])

const apps = [
  { id: 'intranet', label: 'Intranet',   icon: '📱', url: 'https://campo.nix.local/'    },
  { id: 'admin',    label: 'Camp Admin', icon: '🖥️',  url: 'https://campoffice.nix.local/' },
]

const activeApp  = ref('intranet')
const frame      = ref(null)
const loading    = ref(false)

const currentUrl = computed(() => apps.find(a => a.id === activeApp.value)?.url)

function close() { emit('update:modelValue', false) }

function switchApp(id) {
  if (id === activeApp.value) return
  activeApp.value = id
  loading.value   = true
}

function reload() {
  if (!frame.value) return
  loading.value   = true
  frame.value.src = currentUrl.value
}

watch(() => props.modelValue, (open) => {
  if (open) {
    activeApp.value = 'intranet'
    loading.value   = true
  }
})
</script>

<style scoped>
.phone-shell {
  position: relative;
  width: 320px;
  height: 660px;
  background: #1a1a1a;
  border-radius: 3rem;
  border: 8px solid #2a2a2a;
  box-shadow:
    0 0 0 1px #3a3a3a,
    0 30px 80px rgba(0,0,0,0.8),
    inset 0 0 0 1px #111;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 12px 0 8px;
  gap: 8px;
}

.phone-notch {
  width: 90px;
  height: 22px;
  background: #0f0f0f;
  border-radius: 0 0 14px 14px;
  border: 2px solid #222;
  border-top: none;
  flex-shrink: 0;
  z-index: 2;
}

.phone-screen {
  flex: 1;
  width: 100%;
  position: relative;
  overflow: hidden;
}

.phone-screen iframe {
  display: block;
}

.phone-home {
  width: 100px;
  height: 4px;
  background: #3a3a3a;
  border-radius: 2px;
  flex-shrink: 0;
}

.preview-enter-active, .preview-leave-active { transition: all .25s ease; }
.preview-enter-from, .preview-leave-to { opacity: 0; }
.preview-enter-from .relative, .preview-leave-to .relative { transform: scale(0.95); }
</style>
