<script setup lang="ts">
import { RouterLink, useRoute, useRouter } from 'vue-router'
import LoginForm from '@/features/auth/LoginForm.vue'
import AuthLayout from '@/layouts/AuthLayout.vue'
import { useCurrentStoreStore } from '@/stores/currentStore'

const router = useRouter()
const route = useRoute()
const store = useCurrentStoreStore()

async function onSuccess(): Promise<void> {
  try {
    await store.refresh()
  } catch {
    // If the session context cannot be loaded, fall through to store selection.
  }

  const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : null
  if (store.hasStore) {
    if (redirect && redirect.startsWith('/')) {
      await router.replace(redirect)
    } else {
      await router.replace({ name: 'dashboard' })
    }
  } else {
    await router.replace({ name: 'select-store' })
  }
}
</script>

<template>
  <AuthLayout title="Masuk ke akun Anda" subtitle="Gunakan akun toko Anda untuk melanjutkan.">
    <LoginForm @success="onSuccess" />
    <p class="mt-6 text-center text-sm text-slate-400">
      Belum punya akun?
      <RouterLink
        :to="{ name: 'register' }"
        class="font-medium text-brand-300 transition-colors hover:text-brand-200"
      >
        Daftar toko
      </RouterLink>
    </p>
  </AuthLayout>
</template>
