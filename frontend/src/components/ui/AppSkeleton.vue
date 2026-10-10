<script setup lang="ts">
withDefaults(
  defineProps<{
    lines?: number
  }>(),
  { lines: 3 },
)
</script>

<template>
  <div class="animate-pulse space-y-2.5" aria-hidden="true">
    <div
      v-for="line in lines"
      :key="line"
      class="h-3 rounded bg-white/5"
      :class="line === lines ? 'w-2/3' : 'w-full'"
    />
  </div>
</template>
