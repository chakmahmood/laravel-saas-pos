<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import AppPasswordInput from '@/components/ui/AppPasswordInput.vue'
import { fieldErrorsFrom } from '@/features/team/errors'
import AuthLayout from '@/layouts/AuthLayout.vue'
import { ApiError, humanMessage } from '@/lib/errors'
import { useAuthStore } from '@/stores/auth'
import { useCurrentStoreStore } from '@/stores/currentStore'
import { useToastStore } from '@/stores/toast'

const auth = useAuthStore()
const store = useCurrentStoreStore()
const toast = useToastStore()
const router = useRouter()

const currentPassword = ref('')
const password = ref('')
const confirmation = ref('')
const submitting = ref(false)
const formError = ref('')
const fieldErrors = ref<Record<string, string>>({})

function validate(): boolean {
  const errors: Record<string, string> = {}
  if (currentPassword.value === '') {
    errors.current_password = 'Kata sandi saat ini wajib diisi.'
  }
  if (password.value.length < 8) {
    errors.password = 'Kata sandi baru minimal 8 karakter.'
  }
  if (confirmation.value !== password.value) {
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
  fieldErrors.value = {}
  if (!validate()) {
    return
  }

  submitting.value = true
  try {
    await auth.changePassword({
      current_password: currentPassword.value,
      password: password.value,
      password_confirmation: confirmation.value,
    })
    currentPassword.value = ''
    password.value = ''
    confirmation.value = ''
    toast.success('Kata sandi berhasil diubah.')
    await router.replace(store.hasStore ? { name: 'dashboard' } : { name: 'select-store' })
  } catch (caught) {
    if (caught instanceof ApiError) {
      const mapped = fieldErrorsFrom(caught)
      fieldErrors.value = mapped
      if (caught.status !== 422 || Object.keys(mapped).length === 0) {
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
  <AuthLayout
    title="Ganti kata sandi awal"
    subtitle="Demi keamanan, ganti kata sandi awal Anda sebelum menggunakan aplikasi."
  >
    <form class="space-y-4" novalidate @submit.prevent="onSubmit">
      <div
        v-if="formError"
        role="alert"
        class="flex items-start gap-2.5 rounded-lg border border-rose-500/25 bg-rose-500/5 px-3.5 py-2.5 text-sm text-rose-200"
      >
        <AppIcon name="alertCircle" :size="18" class="mt-0.5 shrink-0" />
        <span>{{ formError }}</span>
      </div>

      <AppPasswordInput
        v-model="currentPassword"
        label="Kata sandi saat ini"
        name="current_password"
        autocomplete="current-password"
        :error="fieldErrors.current_password"
        required
      />
      <AppPasswordInput
        v-model="password"
        label="Kata sandi baru"
        name="password"
        autocomplete="new-password"
        hint="Minimal 8 karakter."
        :error="fieldErrors.password"
        required
      />
      <AppPasswordInput
        v-model="confirmation"
        label="Konfirmasi kata sandi baru"
        name="password_confirmation"
        autocomplete="new-password"
        :error="fieldErrors.password_confirmation"
        required
      />

      <AppButton type="submit" block :loading="submitting" :disabled="submitting">
        {{ submitting ? 'Menyimpan…' : 'Simpan kata sandi baru' }}
      </AppButton>
    </form>
  </AuthLayout>
</template>
