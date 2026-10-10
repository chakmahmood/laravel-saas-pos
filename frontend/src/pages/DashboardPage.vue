<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { NAV_SECTIONS } from '@/app/navigation'
import AppBadge from '@/components/ui/AppBadge.vue'
import AppCard from '@/components/ui/AppCard.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { useAuthStore } from '@/stores/auth'
import { useCurrentStoreStore } from '@/stores/currentStore'
import { businessTypeLabel, storeRoleLabel } from '@/types/enums'

const auth = useAuthStore()
const store = useCurrentStoreStore()
const router = useRouter()

const modules = computed(() =>
  NAV_SECTIONS.flatMap((section) => section.items)
    .filter((item) => item.name !== 'dashboard')
    .filter((item) => !item.inventoryOnly || store.inventoryEnabled)
    .filter((item) => !item.managerOnly || store.canManage)
    .map((item) => {
      const meta = router.resolve({ name: item.name }).meta
      return {
        ...item,
        stage: meta.moduleStage ?? 'Mendatang',
        description: meta.moduleDescription ?? '',
      }
    }),
)
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-xl font-semibold text-white">
        Halo, {{ auth.user?.name?.split(' ')[0] ?? 'Pengguna' }}
      </h1>
      <p class="mt-1 text-sm text-slate-400">
        Selamat datang di panel admin. Beberapa modul masih dalam pengembangan.
      </p>
    </header>

    <div class="grid gap-4 lg:grid-cols-3">
      <AppCard title="Toko aktif" class="lg:col-span-2">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div class="flex items-start gap-4">
            <span
              class="flex h-12 w-12 items-center justify-center rounded-xl bg-brand-500/15 text-brand-300"
            >
              <AppIcon name="store" :size="24" />
            </span>
            <div>
              <p class="text-base font-semibold text-white">
                {{ store.current?.name ?? 'Belum dipilih' }}
              </p>
              <p class="mt-0.5 text-sm text-slate-400">
                {{ businessTypeLabel(store.current?.business_type) }}
                <span class="text-slate-600">·</span>
                {{ storeRoleLabel(store.current?.role) }}
              </p>
            </div>
          </div>
          <div class="flex items-center gap-2">
            <AppBadge :variant="store.current?.is_active ? 'success' : 'warning'" dot>
              {{ store.current?.is_active ? 'Aktif' : 'Nonaktif' }}
            </AppBadge>
            <AppBadge v-if="store.inventoryEnabled" variant="brand">Inventory</AppBadge>
          </div>
        </div>
        <dl class="mt-5 grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
          <div class="rounded-lg border border-hairline bg-panel-2 px-3 py-2">
            <dt class="text-xs text-slate-500">Slug</dt>
            <dd class="mt-0.5 truncate text-slate-200">{{ store.current?.slug ?? '—' }}</dd>
          </div>
          <div class="rounded-lg border border-hairline bg-panel-2 px-3 py-2">
            <dt class="text-xs text-slate-500">Peran Anda</dt>
            <dd class="mt-0.5 text-slate-200">{{ storeRoleLabel(store.current?.role) }}</dd>
          </div>
          <div class="rounded-lg border border-hairline bg-panel-2 px-3 py-2">
            <dt class="text-xs text-slate-500">ID Toko</dt>
            <dd class="mt-0.5 text-slate-200">#{{ store.current?.id ?? '—' }}</dd>
          </div>
        </dl>
      </AppCard>

      <AppCard title="Tentang checkpoint ini">
        <p class="text-sm text-slate-300">
          Ini adalah fondasi panel admin. Login, pemilihan toko, navigasi, dan
          penanganan error sudah terhubung ke API. Modul bisnis akan dibangun
          bertahap.
        </p>
        <ul class="mt-4 space-y-2 text-sm text-slate-400">
          <li class="flex items-center gap-2">
            <AppIcon name="checkCircle" :size="16" class="text-emerald-400" />
            Autentikasi &amp; sesi toko
          </li>
          <li class="flex items-center gap-2">
            <AppIcon name="checkCircle" :size="16" class="text-emerald-400" />
            Navigasi &amp; proteksi rute
          </li>
          <li class="flex items-center gap-2">
            <AppIcon name="info" :size="16" class="text-sky-400" />
            Modul bisnis menyusul
          </li>
        </ul>
      </AppCard>
    </div>

    <section>
      <div class="mb-3 flex items-center justify-between">
        <h2 class="text-sm font-semibold text-slate-200">Modul</h2>
        <span class="text-xs text-slate-500">Status implementasi</span>
      </div>
      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <RouterLink
          v-for="module in modules"
          :key="module.name"
          :to="{ name: module.name }"
          class="group rounded-xl border border-hairline bg-panel p-5 transition-colors hover:border-hairline-strong focus-visible:outline-none"
        >
          <div class="flex items-center justify-between">
            <span
              class="flex h-10 w-10 items-center justify-center rounded-lg bg-panel-3 text-slate-300 group-hover:text-brand-300"
            >
              <AppIcon :name="module.icon" :size="20" />
            </span>
            <AppBadge variant="neutral">{{ module.stage }}</AppBadge>
          </div>
          <p class="mt-4 text-sm font-semibold text-slate-100">{{ module.label }}</p>
          <p class="mt-1 text-xs text-slate-400">{{ module.description }}</p>
        </RouterLink>
      </div>
    </section>

    <AppCard>
      <div class="flex items-start gap-3">
        <span class="mt-0.5 text-sky-400">
          <AppIcon name="info" :size="20" />
        </span>
        <div>
          <p class="text-sm font-semibold text-slate-100">Ringkasan analitik belum tersedia</p>
          <p class="mt-1 text-sm text-slate-400">
            Dasbor ini sengaja tidak menampilkan omzet, jumlah transaksi, atau grafik
            karena endpoint analitik belum tersedia di backend. Angka tidak akan
            ditampilkan sampai berasal dari data nyata.
          </p>
        </div>
      </div>
    </AppCard>
  </div>
</template>
