<script setup lang="ts">
import { useToastStore, type ToastKind } from '@/stores/toast'
import AppIcon from './AppIcon.vue'
import type { IconName } from './icons'

const toast = useToastStore()

const iconFor: Record<ToastKind, IconName> = {
  success: 'checkCircle',
  error: 'alertCircle',
  warning: 'alertTriangle',
  info: 'info',
}

const accentFor: Record<ToastKind, string> = {
  success: 'text-emerald-400',
  error: 'text-rose-400',
  warning: 'text-amber-400',
  info: 'text-sky-400',
}
</script>

<template>
  <div
    class="pointer-events-none fixed inset-x-0 bottom-0 z-[60] flex flex-col items-center gap-2 p-4 sm:items-end sm:p-6"
    aria-live="polite"
  >
    <TransitionGroup
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="translate-y-2 opacity-0"
      leave-active-class="transition duration-150 ease-in"
      leave-to-class="translate-y-2 opacity-0"
    >
      <div
        v-for="item in toast.toasts"
        :key="item.id"
        class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-xl border border-hairline bg-panel-2 px-4 py-3 shadow-pop"
        role="status"
      >
        <span class="mt-0.5" :class="accentFor[item.kind]">
          <AppIcon :name="iconFor[item.kind]" :size="18" />
        </span>
        <p class="flex-1 text-sm text-slate-100">{{ item.message }}</p>
        <button
          type="button"
          class="text-slate-500 transition-colors hover:text-slate-200"
          aria-label="Tutup notifikasi"
          @click="toast.dismiss(item.id)"
        >
          <AppIcon name="close" :size="16" />
        </button>
      </div>
    </TransitionGroup>
  </div>
</template>
