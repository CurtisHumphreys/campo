<template>
  <Teleport to="body">
    <Transition name="modal">
      <div v-if="modelValue"
        class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4"
        @click.self="$emit('update:modelValue', false)">
        <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="$emit('update:modelValue', false)" />
        <div class="relative w-full sm:max-w-lg bg-surface-700 border border-surface-500
                    rounded-t-3xl sm:rounded-2xl shadow-modal flex flex-col max-h-[90dvh]"
          :class="width">
          <!-- Handle bar (mobile) -->
          <div class="sm:hidden flex justify-center pt-3 pb-1">
            <div class="w-10 h-1 bg-surface-400 rounded-full" />
          </div>
          <!-- Header -->
          <div v-if="title" class="flex items-center justify-between px-6 py-4 border-b border-surface-500">
            <h2 class="text-lg font-semibold text-ink-100">{{ title }}</h2>
            <button @click="$emit('update:modelValue', false)"
              class="btn-ghost btn p-1.5 rounded-lg text-ink-400 hover:text-ink-100">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
              </svg>
            </button>
          </div>
          <!-- Body -->
          <div class="overflow-y-auto flex-1 px-6 py-4">
            <slot />
          </div>
          <!-- Footer -->
          <div v-if="$slots.footer" class="px-6 py-4 border-t border-surface-500 pb-safe">
            <slot name="footer" />
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
defineProps({ modelValue: Boolean, title: String, width: { type: String, default: '' } })
defineEmits(['update:modelValue'])
</script>

<style scoped>
.modal-enter-active, .modal-leave-active { transition: all .25s ease; }
.modal-enter-from, .modal-leave-to { opacity: 0; }
.modal-enter-from .relative, .modal-leave-to .relative { transform: translateY(20px); }
</style>
