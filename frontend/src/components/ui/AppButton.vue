<script setup lang="ts">
import { computed } from 'vue'
import AppSpinner from './AppSpinner.vue'

const props = withDefaults(
  defineProps<{
    variant?: 'primary' | 'secondary' | 'ghost' | 'danger'
    size?: 'sm' | 'md' | 'lg'
    type?: 'button' | 'submit' | 'reset'
    loading?: boolean
    disabled?: boolean
    block?: boolean
  }>(),
  { variant: 'primary', size: 'md', type: 'button', loading: false, disabled: false, block: false },
)

defineEmits<{ (event: 'click', ev: MouseEvent): void }>()

const classes = computed(() => {
  const base =
    'inline-flex items-center justify-center gap-2 rounded-lg font-medium transition-colors duration-150 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60'
  const sizes = {
    sm: 'h-9 px-3 text-sm',
    md: 'h-10 px-4 text-sm',
    lg: 'h-11 px-5 text-base',
  }
  const variants = {
    primary: 'bg-brand-500 text-white shadow-sm hover:bg-brand-600 active:bg-brand-700',
    secondary: 'border border-hairline bg-panel-2 text-slate-100 hover:bg-panel-3',
    ghost: 'text-slate-300 hover:bg-white/5 hover:text-white',
    danger: 'bg-rose-500 text-white shadow-sm hover:bg-rose-600',
  }
  return [base, sizes[props.size], variants[props.variant], props.block ? 'w-full' : '']
})
</script>

<template>
  <button
    :type="type"
    :disabled="disabled || loading"
    :aria-busy="loading || undefined"
    :class="classes"
    @click="$emit('click', $event)"
  >
    <AppSpinner v-if="loading" size="sm" />
    <slot />
  </button>
</template>
