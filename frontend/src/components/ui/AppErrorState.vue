<script setup lang="ts">
import AppButton from './AppButton.vue'
import AppIcon from './AppIcon.vue'

withDefaults(
  defineProps<{
    title?: string
    message: string
    retryLabel?: string
    showRetry?: boolean
  }>(),
  { title: 'Terjadi kesalahan', retryLabel: 'Coba lagi', showRetry: true },
)

defineEmits<{ (event: 'retry'): void }>()
</script>

<template>
  <div
    role="alert"
    class="flex flex-col items-start gap-3 rounded-xl border border-rose-500/25 bg-rose-500/5 px-5 py-4"
  >
    <div class="flex items-start gap-3">
      <span class="mt-0.5 text-rose-400">
        <AppIcon name="alertTriangle" :size="20" />
      </span>
      <div class="space-y-1">
        <p class="text-sm font-semibold text-rose-200">{{ title }}</p>
        <p class="text-sm text-rose-200/80">{{ message }}</p>
      </div>
    </div>
    <div v-if="showRetry || $slots.actions" class="flex items-center gap-2 pl-8">
      <AppButton v-if="showRetry" size="sm" variant="secondary" @click="$emit('retry')">
        <AppIcon name="refresh" :size="16" />
        {{ retryLabel }}
      </AppButton>
      <slot name="actions" />
    </div>
  </div>
</template>
