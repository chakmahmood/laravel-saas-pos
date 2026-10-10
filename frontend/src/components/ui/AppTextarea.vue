<script setup lang="ts">
import { computed, useId } from 'vue'

const props = withDefaults(
  defineProps<{
    modelValue: string
    label?: string
    name?: string
    placeholder?: string
    rows?: number
    maxlength?: number
    error?: string
    hint?: string
    required?: boolean
    disabled?: boolean
  }>(),
  { rows: 3, required: false, disabled: false },
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
  emit('update:modelValue', (event.target as HTMLTextAreaElement).value)
}
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <label v-if="label" :for="inputId" class="text-sm font-medium text-slate-300">
      {{ label }}
      <span v-if="required" class="text-rose-400" aria-hidden="true">*</span>
    </label>
    <textarea
      :id="inputId"
      :name="name"
      :value="modelValue"
      :placeholder="placeholder"
      :rows="rows"
      :maxlength="maxlength"
      :disabled="disabled"
      :required="required"
      :aria-invalid="error ? 'true' : undefined"
      :aria-describedby="describedBy"
      class="w-full resize-y rounded-lg border bg-panel-2 px-3 py-2 text-sm text-slate-100 transition-colors placeholder:text-slate-500 focus:border-brand-500 focus:outline-none disabled:cursor-not-allowed disabled:opacity-60"
      :class="error ? 'border-rose-500/70' : 'border-hairline hover:border-hairline-strong'"
      @input="onInput"
    />
    <p v-if="error" :id="`${inputId}-error`" class="text-xs text-rose-400">{{ error }}</p>
    <p v-else-if="hint" :id="`${inputId}-hint`" class="text-xs text-slate-500">{{ hint }}</p>
  </div>
</template>
