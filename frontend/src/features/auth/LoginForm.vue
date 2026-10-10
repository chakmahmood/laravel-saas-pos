<script setup lang="ts">
import { ref } from 'vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import AppInput from '@/components/ui/AppInput.vue'
import AppPasswordInput from '@/components/ui/AppPasswordInput.vue'
import { ApiError, humanMessage } from '@/lib/errors'
import { useAuthStore } from '@/stores/auth'

const emit = defineEmits<{ (event: 'success'): void }>()

const auth = useAuthStore()

const email = ref('')
const password = ref('')
const submitting = ref(false)
const formError = ref('')
const emailError = ref('')
const passwordError = ref('')

async function onSubmit(): Promise<void> {
  formError.value = ''
  emailError.value = ''
  passwordError.value = ''

  if (email.value.trim() === '') {
    emailError.value = 'Email wajib diisi.'
    return
  }
  if (password.value === '') {
    passwordError.value = 'Kata sandi wajib diisi.'
    return
  }

  submitting.value = true
  try {
    await auth.login(email.value.trim(), password.value)
    emit('success')
  } catch (caught) {
    if (caught instanceof ApiError) {
      emailError.value = caught.fieldError('email') ?? ''
      passwordError.value = caught.fieldError('password') ?? ''
      formError.value = humanMessage(caught)
    } else {
      formError.value = 'Terjadi kesalahan. Silakan coba lagi.'
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <form class="space-y-4" novalidate @submit.prevent="onSubmit">
    <div
      v-if="formError"
      role="alert"
      class="flex items-start gap-2.5 rounded-lg border border-rose-500/25 bg-rose-500/5 px-3.5 py-2.5 text-sm text-rose-200"
    >
      <AppIcon name="alertCircle" :size="18" class="mt-0.5 shrink-0" />
      <span>{{ formError }}</span>
    </div>

    <AppInput
      v-model="email"
      label="Email"
      type="email"
      name="email"
      autocomplete="username"
      inputmode="email"
      placeholder="nama@toko.com"
      :error="emailError"
      required
    />

    <AppPasswordInput
      v-model="password"
      label="Kata sandi"
      name="password"
      autocomplete="current-password"
      placeholder="Masukkan kata sandi"
      :error="passwordError"
      required
    />

    <AppButton type="submit" block :loading="submitting" :disabled="submitting">
      {{ submitting ? 'Memproses…' : 'Masuk' }}
    </AppButton>
  </form>
</template>
