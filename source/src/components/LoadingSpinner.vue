<template>
  <div class="flex items-center justify-center gap-3" :class="full ? 'h-full min-h-[200px]' : ''">
    <div class="w-6 h-6 border-2 border-surface-500 border-t-ember-500 rounded-full animate-spin" />
    <span v-if="label" class="text-sm text-ink-400">{{ label }}</span>
  </div>
</template>
<script setup>
defineProps({ full: Boolean, label: String })
</script>
