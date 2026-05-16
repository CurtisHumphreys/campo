<template>
  <div class="flex flex-col items-center justify-center py-16 text-center px-4">
    <div class="text-5xl mb-4">{{ icon }}</div>
    <h3 class="text-lg font-semibold text-ink-200 mb-1">{{ title }}</h3>
    <p v-if="subtitle" class="text-sm text-ink-400 max-w-xs">{{ subtitle }}</p>
    <slot />
  </div>
</template>
<script setup>
defineProps({ icon: { type: String, default: '📭' }, title: String, subtitle: String })
</script>
