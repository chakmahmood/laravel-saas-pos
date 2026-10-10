<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import AppBreadcrumb from './AppBreadcrumb.vue'
import AppDropdown from '@/components/ui/AppDropdown.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { useAuthStore } from '@/stores/auth'
import { useCurrentStoreStore } from '@/stores/currentStore'
import { useToastStore } from '@/stores/toast'

defineEmits<{ (event: 'open-mobile-nav'): void }>()

const auth = useAuthStore()
const store = useCurrentStoreStore()
const toast = useToastStore()
const router = useRouter()

const initials = computed(() => {
  const name = auth.user?.name ?? ''
  return name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join('')
})

async function onLogout(close: () => void): Promise<void> {
  close()
  await auth.logout()
  store.clear()
  toast.info('Anda telah keluar.')
  await router.replace({ name: 'login' })
}
</script>

<template>
  <header
    class="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-hairline bg-canvas/85 px-4 backdrop-blur sm:px-6"
  >
    <button
      type="button"
      class="flex h-9 w-9 items-center justify-center rounded-lg border border-hairline text-slate-300 transition-colors hover:bg-white/5 lg:hidden"
      aria-label="Buka menu navigasi"
      @click="$emit('open-mobile-nav')"
    >
      <AppIcon name="menu" :size="20" />
    </button>

    <AppBreadcrumb class="hidden sm:block" />

    <div class="ml-auto flex items-center gap-2">
      <span
        v-if="store.current"
        class="hidden items-center gap-1.5 rounded-full border border-hairline bg-panel-2 px-3 py-1 text-xs text-slate-300 sm:inline-flex"
      >
        <span class="h-1.5 w-1.5 rounded-full bg-emerald-400" aria-hidden="true" />
        {{ store.current.name }}
      </span>

      <AppDropdown>
        <template #trigger="{ toggle, open }">
          <button
            type="button"
            class="flex items-center gap-2 rounded-lg border border-hairline bg-panel-2 py-1.5 pl-1.5 pr-2.5 text-sm transition-colors hover:bg-panel-3 focus-visible:outline-none"
            aria-haspopup="menu"
            :aria-expanded="open"
            @click="toggle"
          >
            <span
              class="flex h-7 w-7 items-center justify-center rounded-md bg-brand-500/15 text-xs font-semibold text-brand-300"
            >
              {{ initials || 'U' }}
            </span>
            <span class="hidden max-w-[10rem] truncate text-slate-200 sm:block">
              {{ auth.user?.name ?? 'Pengguna' }}
            </span>
            <AppIcon name="chevronDown" :size="16" class="text-slate-500" />
          </button>
        </template>

        <template #default="{ close }">
          <div class="px-3 py-2">
            <p class="truncate text-sm font-medium text-slate-100">
              {{ auth.user?.name ?? 'Pengguna' }}
            </p>
            <p class="truncate text-xs text-slate-400">{{ auth.user?.email ?? '—' }}</p>
          </div>
          <div class="my-1 h-px bg-hairline" />
          <button
            type="button"
            role="menuitem"
            class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm text-rose-300 transition-colors hover:bg-rose-500/10 focus-visible:outline-none"
            @click="onLogout(close)"
          >
            <AppIcon name="logout" :size="18" />
            Keluar
          </button>
        </template>
      </AppDropdown>
    </div>
  </header>
</template>
