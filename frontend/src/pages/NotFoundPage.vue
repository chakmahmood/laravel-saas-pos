<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import AppIcon from '@/components/ui/AppIcon.vue'
import { env } from '@/lib/env'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

const homeTarget = computed(() =>
  auth.isAuthenticated ? { name: 'dashboard' } : { name: 'login' },
)
</script>

<template>
  <div class="flex min-h-full flex-col items-center justify-center bg-canvas px-4 py-16 text-center">
    <span
      class="flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-500/15 text-brand-300"
    >
      <AppIcon name="store" :size="26" />
    </span>
    <p class="mt-6 text-sm font-semibold uppercase tracking-widest text-slate-500">404</p>
    <h1 class="mt-2 text-2xl font-semibold text-white">Halaman tidak ditemukan</h1>
    <p class="mt-2 max-w-md text-sm text-slate-400">
      Halaman yang Anda cari tidak tersedia atau telah dipindahkan.
    </p>
    <RouterLink
      :to="homeTarget"
      class="mt-6 inline-flex h-10 items-center gap-2 rounded-lg bg-brand-500 px-4 text-sm font-medium text-white transition-colors hover:bg-brand-600"
    >
      <AppIcon name="dashboard" :size="16" />
      Kembali
    </RouterLink>
    <p class="mt-10 text-xs text-slate-600">{{ env.appName }}</p>
  </div>
</template>
