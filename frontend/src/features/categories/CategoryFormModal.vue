<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppCheckbox from '@/components/ui/AppCheckbox.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import AppInput from '@/components/ui/AppInput.vue'
import AppModal from '@/components/ui/AppModal.vue'
import AppTextarea from '@/components/ui/AppTextarea.vue'
import { categoriesService } from '@/features/categories/api'
import type { Category, CategoryPayload } from '@/features/categories/types'
import { ApiError, humanMessage } from '@/lib/errors'

const props = defineProps<{
  open: boolean
  category: Category | null
}>()

const emit = defineEmits<{ (event: 'close'): void; (event: 'saved', category: Category): void }>()

const FORM_ID = 'category-form'

const name = ref('')
const description = ref('')
const isActive = ref(true)
const submitting = ref(false)
const formError = ref('')
const fieldErrors = ref<Record<string, string>>({})

const isEdit = computed(() => props.category !== null)
const title = computed(() => (isEdit.value ? 'Ubah Kategori' : 'Tambah Kategori'))

watch(
  () => props.open,
  (open) => {
    if (!open) {
      return
    }
    name.value = props.category?.name ?? ''
    description.value = props.category?.description ?? ''
    isActive.value = props.category?.is_active ?? true
    formError.value = ''
    fieldErrors.value = {}
    submitting.value = false
  },
)

async function onSubmit(): Promise<void> {
  if (submitting.value) {
    return
  }

  formError.value = ''
  fieldErrors.value = {}

  if (name.value.trim() === '') {
    fieldErrors.value.name = 'Nama kategori wajib diisi.'
    return
  }

  const payload: CategoryPayload = {
    name: name.value.trim(),
    description: description.value.trim() === '' ? null : description.value.trim(),
    is_active: isActive.value,
  }

  submitting.value = true
  try {
    const saved = props.category
      ? await categoriesService.update(props.category.id, payload)
      : await categoriesService.create(payload)
    emit('saved', saved)
  } catch (caught) {
    if (caught instanceof ApiError) {
      const mapped: Record<string, string> = {}
      for (const [field, messages] of Object.entries(caught.errors ?? {})) {
        if (messages[0]) {
          mapped[field] = messages[0]
        }
      }
      fieldErrors.value = mapped
      if (Object.keys(mapped).length === 0 || caught.status !== 422) {
        formError.value = humanMessage(caught)
      }
    } else {
      formError.value = 'Terjadi kesalahan. Silakan coba lagi.'
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <AppModal :open="open" :title="title" max-width="md" @close="emit('close')">
    <form :id="FORM_ID" class="space-y-4" novalidate @submit.prevent="onSubmit">
      <div
        v-if="formError"
        role="alert"
        class="flex items-start gap-2.5 rounded-lg border border-rose-500/25 bg-rose-500/5 px-3.5 py-2.5 text-sm text-rose-200"
      >
        <AppIcon name="alertCircle" :size="18" class="mt-0.5 shrink-0" />
        <span>{{ formError }}</span>
      </div>

      <AppInput
        v-model="name"
        label="Nama kategori"
        name="name"
        placeholder="Contoh: Minuman"
        :error="fieldErrors.name"
        required
      />
      <AppTextarea
        v-model="description"
        label="Deskripsi"
        name="description"
        placeholder="Deskripsi singkat (opsional)"
        :maxlength="1000"
        :error="fieldErrors.description"
      />
      <AppCheckbox
        v-model="isActive"
        label="Aktif"
        description="Kategori nonaktif tidak tampil sebagai pilihan saat membuat produk."
      />
    </form>

    <template #footer>
      <AppButton variant="secondary" :disabled="submitting" @click="emit('close')">Batal</AppButton>
      <AppButton type="submit" :form="FORM_ID" :loading="submitting" :disabled="submitting">
        {{ submitting ? 'Menyimpan…' : 'Simpan' }}
      </AppButton>
    </template>
  </AppModal>
</template>
