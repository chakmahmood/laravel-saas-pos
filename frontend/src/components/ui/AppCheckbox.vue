<script setup lang="ts">
withDefaults(
  defineProps<{
    modelValue: boolean
    label: string
    description?: string
    name?: string
    disabled?: boolean
  }>(),
  { disabled: false },
)

const emit = defineEmits<{ (event: 'update:modelValue', value: boolean): void }>()

function onChange(event: Event): void {
  emit('update:modelValue', (event.target as HTMLInputElement).checked)
}
</script>

<template>
  <label class="flex cursor-pointer items-start gap-3" :class="disabled ? 'cursor-not-allowed opacity-60' : ''">
    <input
      type="checkbox"
      :name="name"
      :checked="modelValue"
      :disabled="disabled"
      class="mt-0.5 h-4 w-4 shrink-0 rounded border-hairline bg-panel-2 accent-brand-500 focus-visible:outline-none"
      @change="onChange"
    />
    <span class="min-w-0">
      <span class="block text-sm font-medium text-slate-200">{{ label }}</span>
      <span v-if="description" class="mt-0.5 block text-xs text-slate-400">{{ description }}</span>
    </span>
  </label>
</template>
