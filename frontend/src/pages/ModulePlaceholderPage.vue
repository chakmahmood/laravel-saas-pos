<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppBadge from '@/components/ui/AppBadge.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppCard from '@/components/ui/AppCard.vue'
import AppIcon from '@/components/ui/AppIcon.vue'

const route = useRoute()
const router = useRouter()

const title = computed(() => (route.meta.moduleTitle as string | undefined) ?? 'Modul')
const description = computed(() => (route.meta.moduleDescription as string | undefined) ?? '')
const stage = computed(() => (route.meta.moduleStage as string | undefined) ?? 'Mendatang')
</script>

<template>
  <div class="space-y-6">
    <header>
      <div class="flex items-center gap-2">
        <h1 class="text-xl font-semibold text-white">{{ title }}</h1>
        <AppBadge variant="neutral">{{ stage }}</AppBadge>
      </div>
      <p class="mt-1 text-sm text-slate-400">{{ description }}</p>
    </header>

    <AppCard>
      <div class="flex flex-col items-center justify-center gap-3 px-6 py-12 text-center">
        <span
          class="flex h-12 w-12 items-center justify-center rounded-full border border-hairline bg-panel-2 text-slate-400"
        >
          <AppIcon name="sparkle" :size="22" />
        </span>
        <p class="text-sm font-semibold text-slate-100">Modul akan tersedia pada tahap berikutnya</p>
        <p class="mx-auto max-w-md text-sm text-slate-400">
          Antarmuka untuk modul {{ title }} belum diimplementasikan pada checkpoint ini.
          Endpoint backend terkait sudah tersedia dan akan diintegrasikan pada tahap
          pengembangan berikutnya.
        </p>
        <div class="mt-2">
          <AppButton variant="secondary" size="sm" @click="router.replace({ name: 'dashboard' })">
            <AppIcon name="dashboard" :size="16" />
            Kembali ke dasbor
          </AppButton>
        </div>
      </div>
    </AppCard>
  </div>
</template>
