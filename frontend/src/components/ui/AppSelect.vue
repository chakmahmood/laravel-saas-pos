<script setup lang="ts">
import { computed, useId } from 'vue'
import AppIcon from './AppIcon.vue'

interface SelectOption {
  value: string
  label: string
}

const props = withDefaults(
  defineProps<{
    modelValue: string
    options: SelectOption[]
    label?: string
    name?: string
    placeholder?: string
    error?: string
    hint?: string
    required?: boolean
    disabled?: boolean
  }>(),
  { required: false, disabled: false },
)

const emit = defineEmits<{ (event: 'update:modelValue', value: string): void }>()

const uid = useId()
const inputId = computed(() => (props.name ? `field-${props.name}` : `field-${uid}`))
const describedBy = computed(() => {
  if (props.error) {
    return `${inputId.value}-error`
  }
  if (props.hint) {
    return `${inputId.value}-hint`
  }
  return undefined
})

function onChange(event: Event): void {
  emit('update:modelValue', (event.target as HTMLSelectElement).value)
}
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <label v-if="label" :for="inputId" class="text-sm font-medium text-slate-300">
      {{ label }}
      <span v-if="required" class="text-rose-400" aria-hidden="true">*</span>
    </label>
    <div class="relative">
      <select
        :id="inputId"
        :name="name"
        :value="modelValue"
        :disabled="disabled"
        :required="required"
        :aria-invalid="error ? 'true' : undefined"
        :aria-describedby="describedBy"
        class="h-10 w-full appearance-none rounded-lg border bg-panel-2 px-3 pr-9 text-sm text-slate-100 transition-colors focus:border-brand-500 focus:outline-none disabled:cursor-not-allowed disabled:opacity-60"
        :class="error ? 'border-rose-500/70' : 'border-hairline hover:border-hairline-strong'"
        @change="onChange"
      >
        <option v-if="placeholder" value="" disabled>{{ placeholder }}</option>
        <option v-for="option in options" :key="option.value" :value="option.value">
          {{ option.label }}
        </option>
      </select>
      <span
        class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-slate-500"
        aria-hidden="true"
      >
        <AppIcon name="chevronDown" :size="16" />
      </span>
    </div>
    <p v-if="error" :id="`${inputId}-error`" class="text-xs text-rose-400">{{ error }}</p>
    <p v-else-if="hint" :id="`${inputId}-hint`" class="text-xs text-slate-500">{{ hint }}</p>
  </div>
</template>
