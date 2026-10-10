<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import AppBadge from '@/components/ui/AppBadge.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppEmptyState from '@/components/ui/AppEmptyState.vue'
import AppErrorState from '@/components/ui/AppErrorState.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import AppSkeleton from '@/components/ui/AppSkeleton.vue'
import { ApiError, humanMessage } from '@/lib/errors'
import { env } from '@/lib/env'
import { useAuthStore } from '@/stores/auth'
import { useCurrentStoreStore } from '@/stores/currentStore'
import { useToastStore } from '@/stores/toast'
import { businessTypeLabel, storeRoleLabel } from '@/types/enums'

const router = useRouter()
const auth = useAuthStore()
const store = useCurrentStoreStore()
const toast = useToastStore()

const selectingId = ref<number | null>(null)
const actionError = ref('')

const hasStores = computed(() => store.stores.length > 0)

async function load(): Promise<void> {
  actionError.value = ''
  try {
    await store.refresh()
  } catch (caught) {
    actionError.value = caught instanceof ApiError ? humanMessage(caught) : 'Gagal memuat toko.'
  }
}

async function choose(id: number): Promise<void> {
  selectingId.value = id
  actionError.value = ''
  try {
    await store.select(id)
    toast.success('Toko aktif berhasil dipilih.')
    await router.replace({ name: 'dashboard' })
  } catch (caught) {
    actionError.value = caught instanceof ApiError ? humanMessage(caught) : 'Gagal memilih toko.'
    toast.error(actionError.value)
  } finally {
    selectingId.value = null
  }
}

async function logout(): Promise<void> {
  await auth.logout()
  store.clear()
  await router.replace({ name: 'login' })
}

onMounted(load)
</script>

<template>
  <div class="min-h-full bg-canvas">
    <header class="flex h-16 items-center justify-between border-b border-hairline px-4 sm:px-6">
      <div class="flex items-center gap-2.5">
        <span
          class="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-500/15 text-brand-300"
        >
          <AppIcon name="store" :size="20" />
        </span>
        <p class="text-sm font-semibold text-white">{{ env.appName }}</p>
      </div>
      <AppButton variant="ghost" size="sm" @click="logout">
        <AppIcon name="logout" :size="16" />
        Keluar
      </AppButton>
    </header>

    <main class="mx-auto w-full max-w-3xl px-4 py-10 sm:px-6">
      <h1 class="text-xl font-semibold text-white">Pilih toko</h1>
      <p class="mt-1 text-sm text-slate-400">
        Pilih toko yang ingin Anda kelola. Konteks toko berlaku untuk perangkat ini.
      </p>

      <div v-if="actionError" class="mt-6">
        <AppErrorState :message="actionError" @retry="load" />
      </div>

      <div v-if="store.loading && !hasStores" class="mt-6 space-y-3">
        <div v-for="n in 2" :key="n" class="rounded-xl border border-hairline bg-panel px-5 py-4">
          <AppSkeleton :lines="2" />
        </div>
      </div>

      <div v-else-if="!hasStores" class="mt-6 rounded-xl border border-hairline bg-panel">
        <AppEmptyState
          icon="store"
          title="Belum ada toko yang dapat diakses"
          description="Akun Anda belum terhubung ke toko aktif mana pun. Hubungi pemilik atau admin toko untuk mengaktifkan akses."
        >
          <template #actions>
            <AppButton variant="secondary" size="sm" @click="load">
              <AppIcon name="refresh" :size="16" />
              Muat ulang
            </AppButton>
          </template>
        </AppEmptyState>
      </div>

      <ul v-else class="mt-6 space-y-3">
        <li
          v-for="item in store.stores"
          :key="item.id"
          class="flex items-center justify-between gap-4 rounded-xl border border-hairline bg-panel px-5 py-4 transition-colors hover:border-hairline-strong"
        >
          <div class="min-w-0">
            <div class="flex items-center gap-2">
              <p class="truncate text-sm font-semibold text-slate-100">{{ item.name }}</p>
              <AppBadge v-if="store.current?.id === item.id" variant="success" dot>Aktif</AppBadge>
              <AppBadge v-else-if="!item.is_active" variant="warning">Nonaktif</AppBadge>
            </div>
            <p class="mt-1 truncate text-xs text-slate-400">
              {{ businessTypeLabel(item.business_type) }}
              <span class="text-slate-600">·</span>
              {{ storeRoleLabel(item.role) }}
              <span class="text-slate-600">·</span>
              {{ item.slug }}
            </p>
          </div>
          <AppButton
            size="sm"
            :variant="store.current?.id === item.id ? 'secondary' : 'primary'"
            :loading="selectingId === item.id"
            :disabled="!item.is_active || selectingId !== null"
            @click="choose(item.id)"
          >
            {{ store.current?.id === item.id ? 'Lanjut' : 'Pilih' }}
          </AppButton>
        </li>
      </ul>
    </main>
  </div>
</template>
