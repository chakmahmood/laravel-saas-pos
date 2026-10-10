<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue'
import { formatCurrencyInput, parseCurrencyInput } from '@/lib/format'

const props = withDefaults(
  defineProps<{
    modelValue: number | null
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

const emit = defineEmits<{ (event: 'update:modelValue', value: number | null): void }>()

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

const display = ref(formatCurrencyInput(props.modelValue))

watch(
  () => props.modelValue,
  (value) => {
    if (parseCurrencyInput(display.value) !== value) {
      display.value = formatCurrencyInput(value)
    }
  },
)

function onInput(event: Event): void {
  const parsed = parseCurrencyInput((event.target as HTMLInputElement).value)
  display.value = formatCurrencyInput(parsed)
  emit('update:modelValue', parsed)
}
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <label v-if="label" :for="inputId" class="text-sm font-medium text-slate-300">
      {{ label }}
      <span v-if="required" class="text-rose-400" aria-hidden="true">*</span>
    </label>
    <div class="relative">
      <span
        class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-slate-500"
        aria-hidden="true"
        >Rp</span
      >
      <input
        :id="inputId"
        :name="name"
        type="text"
        inputmode="numeric"
        :value="display"
        :placeholder="placeholder ?? '0'"
        :disabled="disabled"
        :required="required"
        :aria-invalid="error ? 'true' : undefined"
        :aria-describedby="describedBy"
        class="h-10 w-full rounded-lg border bg-panel-2 pl-10 pr-3 text-sm text-slate-100 transition-colors placeholder:text-slate-500 focus:border-brand-500 focus:outline-none disabled:cursor-not-allowed disabled:opacity-60"
        :class="error ? 'border-rose-500/70' : 'border-hairline hover:border-hairline-strong'"
        @input="onInput"
      />
    </div>
    <p v-if="error" :id="`${inputId}-error`" class="text-xs text-rose-400">{{ error }}</p>
    <p v-else-if="hint" :id="`${inputId}-hint`" class="text-xs text-slate-500">{{ hint }}</p>
  </div>
</template>
