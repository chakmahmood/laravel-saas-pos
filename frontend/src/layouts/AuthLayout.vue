<script setup lang="ts">
import AppIcon from '@/components/ui/AppIcon.vue'
import { env } from '@/lib/env'

defineProps<{
  title: string
  subtitle?: string
}>()

const highlights = [
  'Kelola katalog, pesanan, dan pembayaran dalam satu tempat.',
  'Pantau sesi kas dan rekonsiliasi kasir dengan mudah.',
  'Kontrol stok untuk toko retail dan restoran.',
]
</script>

<template>
  <div class="flex min-h-full bg-canvas">
    <section
      class="relative hidden w-1/2 flex-col justify-between overflow-hidden border-r border-hairline bg-panel p-10 lg:flex"
      aria-hidden="true"
    >
      <div
        class="pointer-events-none absolute -right-24 -top-24 h-72 w-72 rounded-full bg-brand-500/10 blur-3xl"
      />
      <div class="relative flex items-center gap-3">
        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-500/15 text-brand-300">
          <AppIcon name="store" :size="22" />
        </span>
        <div>
          <p class="text-sm font-semibold text-white">{{ env.appName }}</p>
          <p class="text-[11px] uppercase tracking-wider text-slate-500">Admin Panel</p>
        </div>
      </div>

      <div class="relative space-y-6">
        <h2 class="max-w-sm text-2xl font-semibold leading-snug text-white">
          Platform POS untuk semua jenis bisnis.
        </h2>
        <ul class="space-y-3">
          <li v-for="line in highlights" :key="line" class="flex items-start gap-3 text-sm text-slate-300">
            <span class="mt-0.5 text-brand-400">
              <AppIcon name="checkCircle" :size="18" />
            </span>
            {{ line }}
          </li>
        </ul>
      </div>

      <p class="relative text-xs text-slate-600">Multi-tenant · Aman · Terintegrasi</p>
    </section>

    <section class="flex w-full flex-col items-center justify-center px-4 py-10 lg:w-1/2">
      <div class="w-full max-w-md">
        <div class="mb-7 flex items-center gap-3 lg:hidden">
          <span
            class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-500/15 text-brand-300"
          >
            <AppIcon name="store" :size="22" />
          </span>
          <p class="text-sm font-semibold text-white">{{ env.appName }}</p>
        </div>

        <h1 class="text-xl font-semibold text-white">{{ title }}</h1>
        <p v-if="subtitle" class="mt-1 text-sm text-slate-400">{{ subtitle }}</p>

        <div class="mt-6">
          <slot />
        </div>
      </div>

      <p class="mt-10 text-center text-xs text-slate-600">
        © {{ new Date().getFullYear() }} {{ env.appName }}
      </p>
    </section>
  </div>
</template>
