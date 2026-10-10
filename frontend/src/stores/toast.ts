import { defineStore } from 'pinia'
import { ref } from 'vue'

export type ToastKind = 'success' | 'error' | 'info' | 'warning'

export interface Toast {
  id: number
  kind: ToastKind
  message: string
  timeout: number
}

let counter = 0

export const useToastStore = defineStore('toast', () => {
  const toasts = ref<Toast[]>([])

  function push(kind: ToastKind, message: string, timeout = 5000): number {
    const id = ++counter
    toasts.value = [...toasts.value, { id, kind, message, timeout }]
    if (timeout > 0) {
      setTimeout(() => dismiss(id), timeout)
    }
    return id
  }

  function dismiss(id: number): void {
    toasts.value = toasts.value.filter((toast) => toast.id !== id)
  }

  return {
    toasts,
    push,
    dismiss,
    success: (message: string, timeout?: number) => push('success', message, timeout),
    error: (message: string, timeout?: number) => push('error', message, timeout),
    info: (message: string, timeout?: number) => push('info', message, timeout),
    warning: (message: string, timeout?: number) => push('warning', message, timeout),
  }
})
