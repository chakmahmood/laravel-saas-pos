<script setup lang="ts">
defineProps<{
  title?: string
  subtitle?: string
}>()
</script>

<template>
  <section class="overflow-hidden rounded-xl border border-hairline bg-panel shadow-panel">
    <header
      v-if="title || $slots.header || $slots.actions"
      class="flex items-start justify-between gap-4 border-b border-hairline px-5 py-4"
    >
      <div class="min-w-0">
        <slot name="header">
          <h2 v-if="title" class="truncate text-sm font-semibold text-white">{{ title }}</h2>
          <p v-if="subtitle" class="mt-0.5 text-xs text-slate-400">{{ subtitle }}</p>
        </slot>
      </div>
      <div v-if="$slots.actions" class="shrink-0">
        <slot name="actions" />
      </div>
    </header>
    <div class="px-5 py-4">
      <slot />
    </div>
    <footer v-if="$slots.footer" class="border-t border-hairline px-5 py-3">
      <slot name="footer" />
    </footer>
  </section>
</template>
