<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import AppInput from '@/components/ui/AppInput.vue'
import AppModal from '@/components/ui/AppModal.vue'
import AppPasswordInput from '@/components/ui/AppPasswordInput.vue'
import { teamService } from '@/features/team/api'
import { fieldErrorsFrom, memberErrorMessage } from '@/features/team/errors'
import type { MemberCreatePayload, StoreMember } from '@/features/team/types'
import { ApiError } from '@/lib/errors'

const props = defineProps<{
  open: boolean
  mode: 'admin' | 'cashier'
}>()

const emit = defineEmits<{
  (event: 'close'): void
  (event: 'saved', member: StoreMember): void
}>()

const FORM_ID = 'member-form'

const name = ref('')
const email = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const submitting = ref(false)
const formError = ref('')
const fieldErrors = ref<Record<string, string>>({})

const isAdmin = computed(() => props.mode === 'admin')
const title = computed(() => (isAdmin.value ? 'Tambah Admin' : 'Tambah Kasir'))

watch(
  () => props.open,
  (open) => {
    if (!open) {
      return
    }
    name.value = ''
    email.value = ''
    password.value = ''
    passwordConfirmation.value = ''
    formError.value = ''
    fieldErrors.value = {}
    submitting.value = false
  },
)

function validate(): boolean {
  const errors: Record<string, string> = {}
  if (name.value.trim() === '') {
    errors.name = 'Nama lengkap wajib diisi.'
  }
  if (email.value.trim() === '') {
    errors.email = 'Email wajib diisi.'
  } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim())) {
    errors.email = 'Format email tidak valid.'
  }
  if (password.value.length < 8) {
    errors.password = 'Kata sandi minimal 8 karakter.'
  }
  if (passwordConfirmation.value !== password.value) {
    errors.password_confirmation = 'Konfirmasi kata sandi tidak cocok.'
  }
  fieldErrors.value = errors
  return Object.keys(errors).length === 0
}

async function onSubmit(): Promise<void> {
  if (submitting.value) {
    return
  }

  formError.value = ''
  if (!validate()) {
    return
  }

  const payload: MemberCreatePayload = {
    name: name.value.trim(),
    email: email.value.trim(),
    password: password.value,
    password_confirmation: passwordConfirmation.value,
  }

  submitting.value = true
  try {
    const saved = isAdmin.value
      ? await teamService.createAdmin(payload)
      : await teamService.createCashier(payload)
    emit('saved', saved)
  } catch (caught) {
    if (caught instanceof ApiError) {
      const mapped = fieldErrorsFrom(caught)
      fieldErrors.value = mapped
      if (caught.status !== 422 || Object.keys(mapped).length === 0) {
        formError.value = memberErrorMessage(caught)
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
        label="Nama lengkap"
        name="name"
        placeholder="Contoh: Budi Santoso"
        :error="fieldErrors.name"
        required
      />
      <AppInput
        v-model="email"
        label="Email"
        name="email"
        type="email"
        inputmode="email"
        placeholder="nama@toko.com"
        :error="fieldErrors.email"
        required
      />
      <AppPasswordInput
        v-model="password"
        label="Kata sandi awal"
        name="password"
        autocomplete="new-password"
        hint="Minimal 8 karakter. Karyawan wajib menggantinya saat login pertama."
        :error="fieldErrors.password"
        required
      />
      <AppPasswordInput
        v-model="passwordConfirmation"
        label="Konfirmasi kata sandi"
        name="password_confirmation"
        autocomplete="new-password"
        :error="fieldErrors.password_confirmation"
        required
      />

      <p class="flex items-start gap-2 text-xs text-slate-400">
        <AppIcon name="info" :size="16" class="mt-0.5 shrink-0 text-sky-400" />
        <span>
          Berikan email dan kata sandi awal ini kepada karyawan. Mereka akan
          diminta mengganti kata sandi saat login pertama.
        </span>
      </p>
    </form>

    <template #footer>
      <AppButton variant="secondary" :disabled="submitting" @click="emit('close')">Batal</AppButton>
      <AppButton type="submit" :form="FORM_ID" :loading="submitting" :disabled="submitting">
        {{ submitting ? 'Menyimpan…' : 'Simpan' }}
      </AppButton>
    </template>
  </AppModal>
</template>
