<script setup lang="ts">
import { computed, ref, useId } from 'vue'

const props = withDefaults(
  defineProps<{
    modelValue: string
    label?: string
    name?: string
    placeholder?: string
    autocomplete?: string
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

const revealed = ref(false)
const inputType = computed(() => (revealed.value ? 'text' : 'password'))

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
    <div class="relative">
      <input
        :id="inputId"
        :name="name"
        :type="inputType"
        :value="modelValue"
        :placeholder="placeholder"
        :autocomplete="autocomplete"
        :disabled="disabled"
        :required="required"
        :aria-invalid="error ? 'true' : undefined"
        :aria-describedby="describedBy"
        class="h-10 w-full rounded-lg border bg-panel-2 px-3 pr-12 text-sm text-slate-100 transition-colors placeholder:text-slate-500 focus:border-brand-500 focus:outline-none disabled:cursor-not-allowed disabled:opacity-60"
        :class="error ? 'border-rose-500/70' : 'border-hairline hover:border-hairline-strong'"
        @input="onInput"
      />
      <button
        type="button"
        class="absolute inset-y-0 right-0 flex w-11 items-center justify-center rounded-r-lg text-xs font-medium text-slate-400 transition-colors hover:text-slate-100 focus-visible:outline-none"
        :aria-pressed="revealed"
        :aria-label="revealed ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'"
        :disabled="disabled"
        @click="revealed = !revealed"
      >
        {{ revealed ? 'Sembunyi' : 'Lihat' }}
      </button>
    </div>
    <p v-if="error" :id="`${inputId}-error`" class="text-xs text-rose-400">{{ error }}</p>
    <p v-else-if="hint" :id="`${inputId}-hint`" class="text-xs text-slate-500">{{ hint }}</p>
  </div>
</template>
