<script setup lang="ts">
import { ref } from 'vue'
import { RouterView } from 'vue-router'
import AppSidebar from '@/components/layout/AppSidebar.vue'
import AppTopbar from '@/components/layout/AppTopbar.vue'

const mobileOpen = ref(false)

function closeMobile(): void {
  mobileOpen.value = false
}
</script>

<template>
  <div class="min-h-full bg-canvas">
    <aside
      class="fixed inset-y-0 left-0 z-40 hidden w-64 border-r border-hairline lg:block"
      aria-label="Sidebar"
    >
      <AppSidebar />
    </aside>

    <Transition
      enter-active-class="transition duration-150 ease-out"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition duration-150 ease-in"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div v-if="mobileOpen" class="fixed inset-0 z-50 lg:hidden" role="dialog" aria-modal="true">
        <div class="absolute inset-0 bg-black/60" @click="closeMobile" />
        <div
          class="absolute inset-y-0 left-0 w-72 max-w-[85%] border-r border-hairline shadow-pop"
          @keydown.esc="closeMobile"
        >
          <AppSidebar mobile @navigate="closeMobile" />
        </div>
      </div>
    </Transition>

    <div class="lg:pl-64">
      <AppTopbar @open-mobile-nav="mobileOpen = true" />
      <main class="mx-auto w-full max-w-[1400px] px-4 py-6 sm:px-6 lg:px-8">
        <RouterView />
      </main>
    </div>
  </div>
</template>
