<script setup lang="ts">
import { computed } from 'vue'

type BadgeVariant = 'neutral' | 'brand' | 'success' | 'warning' | 'danger' | 'info'

const props = withDefaults(
  defineProps<{
    variant?: BadgeVariant
    dot?: boolean
  }>(),
  { variant: 'neutral', dot: false },
)

const classes = computed(
  () =>
    ({
      neutral: 'border-hairline bg-panel-2 text-slate-300',
      brand: 'border-brand-500/30 bg-brand-500/10 text-brand-300',
      success: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300',
      warning: 'border-amber-500/30 bg-amber-500/10 text-amber-300',
      danger: 'border-rose-500/30 bg-rose-500/10 text-rose-300',
      info: 'border-sky-500/30 bg-sky-500/10 text-sky-300',
    })[props.variant],
)

const dotClass = computed(
  () =>
    ({
      neutral: 'bg-slate-400',
      brand: 'bg-brand-400',
      success: 'bg-emerald-400',
      warning: 'bg-amber-400',
      danger: 'bg-rose-400',
      info: 'bg-sky-400',
    })[props.variant],
)
</script>

<template>
  <span
    class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium"
    :class="classes"
  >
    <span v-if="dot" class="h-1.5 w-1.5 rounded-full" :class="dotClass" aria-hidden="true" />
    <slot />
  </span>
</template>
