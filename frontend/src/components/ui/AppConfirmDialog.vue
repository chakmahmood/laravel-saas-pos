<script setup lang="ts">
import AppModal from './AppModal.vue'
import AppButton from './AppButton.vue'
import AppIcon from './AppIcon.vue'

withDefaults(
  defineProps<{
    open: boolean
    title: string
    message: string
    confirmLabel?: string
    cancelLabel?: string
    variant?: 'danger' | 'primary'
    loading?: boolean
  }>(),
  { confirmLabel: 'Hapus', cancelLabel: 'Batal', variant: 'danger', loading: false },
)

const emit = defineEmits<{ (event: 'confirm'): void; (event: 'close'): void }>()
</script>

<template>
  <AppModal :open="open" :title="title" max-width="sm" @close="emit('close')">
    <div class="flex items-start gap-3">
      <span
        class="mt-0.5 shrink-0"
        :class="variant === 'danger' ? 'text-rose-400' : 'text-brand-300'"
      >
        <AppIcon name="alertTriangle" :size="20" />
      </span>
      <p class="text-sm text-slate-300">{{ message }}</p>
    </div>

    <template #footer>
      <AppButton variant="secondary" :disabled="loading" @click="emit('close')">
        {{ cancelLabel }}
      </AppButton>
      <AppButton :variant="variant" :loading="loading" @click="emit('confirm')">
        {{ confirmLabel }}
      </AppButton>
    </template>
  </AppModal>
</template>
