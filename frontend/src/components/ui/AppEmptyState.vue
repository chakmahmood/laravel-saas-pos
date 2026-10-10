<script setup lang="ts">
import AppIcon from './AppIcon.vue'
import type { IconName } from './icons'

withDefaults(
  defineProps<{
    title: string
    description?: string
    icon?: IconName
  }>(),
  { icon: 'info' },
)
</script>

<template>
  <div class="flex flex-col items-center justify-center gap-3 px-6 py-12 text-center">
    <span
      class="flex h-12 w-12 items-center justify-center rounded-full border border-hairline bg-panel-2 text-slate-400"
    >
      <AppIcon :name="icon" :size="22" />
    </span>
    <div class="space-y-1">
      <p class="text-sm font-semibold text-slate-100">{{ title }}</p>
      <p v-if="description" class="mx-auto max-w-sm text-xs text-slate-400">{{ description }}</p>
    </div>
    <div v-if="$slots.actions" class="mt-1 flex items-center gap-2">
      <slot name="actions" />
    </div>
  </div>
</template>
