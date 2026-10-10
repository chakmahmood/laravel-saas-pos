<script setup lang="ts">
import { RouterLink, useRouter } from 'vue-router'
import RegisterForm from '@/features/auth/RegisterForm.vue'
import AuthLayout from '@/layouts/AuthLayout.vue'
import { useCurrentStoreStore } from '@/stores/currentStore'

const router = useRouter()
const store = useCurrentStoreStore()

async function onSuccess(): Promise<void> {
  try {
    await store.refresh()
  } catch {
    // ignore; the user can still continue to store selection
  }
  await router.replace(store.hasStore ? { name: 'dashboard' } : { name: 'select-store' })
}
</script>

<template>
  <AuthLayout
    title="Buat akun & toko Anda"
    subtitle="Satu akun untuk mengelola toko POS Anda."
  >
    <RegisterForm @success="onSuccess" />
    <p class="mt-6 text-center text-sm text-slate-400">
      Sudah punya akun?
      <RouterLink
        :to="{ name: 'login' }"
        class="font-medium text-brand-300 transition-colors hover:text-brand-200"
      >
        Masuk
      </RouterLink>
    </p>
  </AuthLayout>
</template>
