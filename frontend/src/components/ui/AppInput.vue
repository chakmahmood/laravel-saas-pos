<script setup lang="ts">
import { computed, useId } from 'vue'

const props = withDefaults(
  defineProps<{
    modelValue: string
    label?: string
    type?: string
    name?: string
    placeholder?: string
    autocomplete?: string
    inputmode?: 'text' | 'email' | 'numeric' | 'decimal' | 'tel' | 'url' | 'search'
    error?: string
    hint?: string
    required?: boolean
    disabled?: boolean
  }>(),
  { type: 'text', required: false, disabled: false },
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

function onInput(event: Event): void {
  emit('update:modelValue', (event.target as HTMLInputElement).value)
}
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <label v-if="label" :for="inputId" class="text-sm font-medium text-slate-300">
      {{ label }}
      <span v-if="required" class="text-rose-400" aria-hidden="true">*</span>
    </label>
    <input
      :id="inputId"
      :name="name"
      :type="type"
      :value="modelValue"
      :placeholder="placeholder"
      :autocomplete="autocomplete"
      :inputmode="inputmode"
      :disabled="disabled"
      :required="required"
      :aria-invalid="error ? 'true' : undefined"
      :aria-describedby="describedBy"
      class="h-10 w-full rounded-lg border bg-panel-2 px-3 text-sm text-slate-100 transition-colors placeholder:text-slate-500 focus:border-brand-500 focus:outline-none disabled:cursor-not-allowed disabled:opacity-60"
      :class="error ? 'border-rose-500/70' : 'border-hairline hover:border-hairline-strong'"
      @input="onInput"
    />
    <p v-if="error" :id="`${inputId}-error`" class="text-xs text-rose-400">{{ error }}</p>
    <p v-else-if="hint" :id="`${inputId}-hint`" class="text-xs text-slate-500">{{ hint }}</p>
  </div>
</template>
