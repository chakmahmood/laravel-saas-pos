<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, useRoute } from 'vue-router'

interface Crumb {
  label: string
  to?: string
}

const route = useRoute()

const crumbs = computed<Crumb[]>(() => {
  const title = (route.meta.title as string | undefined) ?? ''
  if (route.name === 'dashboard' || !title) {
    return [{ label: 'Dasbor' }]
  }
  return [{ label: 'Dasbor', to: '/dashboard' }, { label: title }]
})
</script>

<template>
  <nav aria-label="Breadcrumb" class="min-w-0">
    <ol class="flex items-center gap-1.5 text-sm">
      <li v-for="(crumb, index) in crumbs" :key="crumb.label" class="flex items-center gap-1.5">
        <RouterLink
          v-if="crumb.to"
          :to="crumb.to"
          class="rounded text-slate-400 transition-colors hover:text-slate-100 focus-visible:outline-none"
        >
          {{ crumb.label }}
        </RouterLink>
        <span v-else class="truncate font-medium text-slate-100" aria-current="page">
          {{ crumb.label }}
        </span>
        <span v-if="index < crumbs.length - 1" class="text-slate-600" aria-hidden="true">/</span>
      </li>
    </ol>
  </nav>
</template>
