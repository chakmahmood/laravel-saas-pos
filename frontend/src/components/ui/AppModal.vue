<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue'
import AppIcon from './AppIcon.vue'

const props = withDefaults(
  defineProps<{
    open: boolean
    title?: string
    maxWidth?: 'sm' | 'md' | 'lg' | 'xl'
  }>(),
  { maxWidth: 'md' },
)

const emit = defineEmits<{ (event: 'close'): void }>()

const titleId = useId()
const panel = ref<HTMLElement | null>(null)
let previouslyFocused: HTMLElement | null = null

const widthClass = computed(
  () =>
    ({
      sm: 'max-w-md',
      md: 'max-w-xl',
      lg: 'max-w-3xl',
      xl: 'max-w-5xl',
    })[props.maxWidth],
)

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    emit('close')
  }
}

watch(
  () => props.open,
  (open) => {
    if (open) {
      previouslyFocused = document.activeElement as HTMLElement | null
      document.addEventListener('keydown', onKeydown)
      document.body.style.overflow = 'hidden'
      void nextTick(() => {
        const target = panel.value?.querySelector<HTMLElement>(
          '[data-autofocus], input, select, textarea, button',
        )
        target?.focus()
      })
    } else {
      document.removeEventListener('keydown', onKeydown)
      document.body.style.overflow = ''
      previouslyFocused?.focus?.()
    }
  },
)

onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKeydown)
  document.body.style.overflow = ''
})
</script>

<template>
  <Teleport to="body">
    <Transition
      enter-active-class="transition duration-150 ease-out"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition duration-150 ease-in"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div
        v-if="open"
        class="fixed inset-0 z-[70] flex items-start justify-center overflow-y-auto p-4 sm:items-center"
      >
        <div class="fixed inset-0 bg-black/60 backdrop-blur-sm" @click="emit('close')" />
        <div
          ref="panel"
          role="dialog"
          aria-modal="true"
          :aria-labelledby="title ? titleId : undefined"
          class="relative z-10 w-full rounded-2xl border border-hairline bg-panel shadow-pop"
          :class="widthClass"
        >
          <header class="flex items-start justify-between gap-4 border-b border-hairline px-5 py-4">
            <div class="min-w-0">
              <slot name="header">
                <h2 v-if="title" :id="titleId" class="text-sm font-semibold text-white">
                  {{ title }}
                </h2>
              </slot>
            </div>
            <button
              type="button"
              class="-mr-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-slate-400 transition-colors hover:bg-white/5 hover:text-slate-100 focus-visible:outline-none"
              aria-label="Tutup"
              @click="emit('close')"
            >
              <AppIcon name="close" :size="18" />
            </button>
          </header>

          <div class="max-h-[70vh] overflow-y-auto px-5 py-4">
            <slot />
          </div>

          <footer
            v-if="$slots.footer"
            class="flex items-center justify-end gap-2 border-t border-hairline px-5 py-4"
          >
            <slot name="footer" />
          </footer>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
