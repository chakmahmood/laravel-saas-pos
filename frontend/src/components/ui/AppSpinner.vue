<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    size?: 'sm' | 'md' | 'lg'
  }>(),
  { size: 'md' },
)

const sizeClass = computed(
  () =>
    ({
      sm: 'h-4 w-4 border-2',
      md: 'h-5 w-5 border-2',
      lg: 'h-8 w-8 border-[3px]',
    })[props.size],
)
</script>

<template>
  <span
    class="inline-block animate-spin rounded-full border-current border-t-transparent"
    :class="sizeClass"
    role="status"
    aria-hidden="true"
  />
</template>
