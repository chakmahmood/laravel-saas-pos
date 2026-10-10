<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { NAV_SECTIONS } from '@/app/navigation'
import AppIcon from '@/components/ui/AppIcon.vue'
import { env } from '@/lib/env'
import { useCurrentStoreStore } from '@/stores/currentStore'
import { businessTypeLabel, catalogLabel, storeRoleLabel } from '@/types/enums'

withDefaults(defineProps<{ mobile?: boolean }>(), { mobile: false })

const emit = defineEmits<{ (event: 'navigate'): void }>()

const store = useCurrentStoreStore()

const sections = computed(() =>
  NAV_SECTIONS.map((section) => ({
    label: section.label,
    items: section.items
      .filter(
        (item) =>
          (!item.inventoryOnly || store.inventoryEnabled) &&
          (!item.managerOnly || store.canManage),
      )
      .map((item) => ({
        ...item,
        label:
          item.name === 'products'
            ? catalogLabel(store.current?.business_type)
            : item.label,
      })),
  })).filter((section) => section.items.length > 0),
)
</script>

<template>
  <div class="flex h-full flex-col bg-panel">
    <div class="flex h-16 items-center gap-2.5 border-b border-hairline px-5">
      <span
        class="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-500/15 text-brand-300"
      >
        <AppIcon name="store" :size="20" />
      </span>
      <div class="min-w-0">
        <p class="truncate text-sm font-semibold text-white">{{ env.appName }}</p>
        <p class="text-[11px] uppercase tracking-wide text-slate-500">Admin Panel</p>
      </div>
    </div>

    <nav class="flex-1 overflow-y-auto px-3 py-4" aria-label="Navigasi utama">
      <div v-for="section in sections" :key="section.label" class="mb-4">
        <p class="px-2 pb-1.5 text-[11px] font-semibold uppercase tracking-wider text-slate-500">
          {{ section.label }}
        </p>
        <ul class="space-y-0.5">
          <li v-for="item in section.items" :key="item.name">
            <RouterLink v-slot="{ isActive, href, navigate }" :to="{ name: item.name }" custom>
              <a
                :href="href"
                class="group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors focus-visible:outline-none"
                :class="
                  isActive
                    ? 'bg-brand-500/15 text-white'
                    : 'text-slate-400 hover:bg-white/5 hover:text-slate-100'
                "
                :aria-current="isActive ? 'page' : undefined"
                @click="
                  (event) => {
                    emit('navigate')
                    navigate(event)
                  }
                "
              >
                <AppIcon
                  :name="item.icon"
                  :size="18"
                  :class="isActive ? 'text-brand-300' : 'text-slate-500 group-hover:text-slate-300'"
                />
                <span>{{ item.label }}</span>
              </a>
            </RouterLink>
          </li>
        </ul>
      </div>
    </nav>

    <div class="border-t border-hairline p-3">
      <div class="rounded-lg border border-hairline bg-panel-2 px-3 py-2.5">
        <p class="text-[11px] uppercase tracking-wide text-slate-500">Toko aktif</p>
        <p class="truncate text-sm font-semibold text-slate-100">
          {{ store.current?.name ?? 'Belum dipilih' }}
        </p>
        <p class="mt-0.5 truncate text-xs text-slate-400">
          {{ store.current ? storeRoleLabel(store.current.role) : '—' }}
          <span class="text-slate-600">·</span>
          {{ businessTypeLabel(store.current?.business_type) }}
        </p>
      </div>
    </div>
  </div>
</template>
