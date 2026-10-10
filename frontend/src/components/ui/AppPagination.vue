<script setup lang="ts">
import { computed } from 'vue'
import type { PaginationMeta } from '@/types/api'
import AppButton from './AppButton.vue'
import AppIcon from './AppIcon.vue'

const props = withDefaults(
  defineProps<{
    meta: PaginationMeta | null
    disabled?: boolean
  }>(),
  { disabled: false },
)

const emit = defineEmits<{ (event: 'change', page: number): void }>()

const current = computed(() => props.meta?.current_page ?? 1)
const lastPage = computed(() => props.meta?.last_page ?? 1)
const total = computed(() => props.meta?.total ?? 0)
const from = computed(() => props.meta?.from ?? 0)
const to = computed(() => props.meta?.to ?? 0)

const pages = computed<number[]>(() => {
  const start = Math.max(1, current.value - 2)
  const end = Math.min(lastPage.value, start + 4)
  const list: number[] = []
  for (let page = Math.max(1, end - 4); page <= end; page += 1) {
    list.push(page)
  }
  return list
})

function go(page: number): void {
  if (props.disabled || page < 1 || page > lastPage.value || page === current.value) {
    return
  }
  emit('change', page)
}
</script>

<template>
  <div
    v-if="meta"
    class="flex flex-col items-center justify-between gap-3 border-t border-hairline px-4 py-3 sm:flex-row"
  >
    <p class="text-xs text-slate-400">
      <template v-if="total > 0">
        Menampilkan {{ from }}–{{ to }} dari {{ total }}
      </template>
      <template v-else>Tidak ada data</template>
    </p>

    <div v-if="lastPage > 1" class="flex items-center gap-1">
      <AppButton
        variant="secondary"
        size="sm"
        :disabled="disabled || current <= 1"
        aria-label="Halaman sebelumnya"
        @click="go(current - 1)"
      >
        <AppIcon name="chevronRight" :size="16" class="rotate-180" />
      </AppButton>

      <button
        v-for="page in pages"
        :key="page"
        type="button"
        class="h-9 min-w-9 rounded-lg px-2 text-sm font-medium transition-colors focus-visible:outline-none disabled:opacity-50"
        :class="
          page === current
            ? 'bg-brand-500 text-white'
            : 'text-slate-300 hover:bg-white/5'
        "
        :aria-current="page === current ? 'page' : undefined"
        :disabled="disabled"
        @click="go(page)"
      >
        {{ page }}
      </button>

      <AppButton
        variant="secondary"
        size="sm"
        :disabled="disabled || current >= lastPage"
        aria-label="Halaman berikutnya"
        @click="go(current + 1)"
      >
        <AppIcon name="chevronRight" :size="16" />
      </AppButton>
    </div>
  </div>
</template>
