import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useCurrentStoreStore } from '@/stores/currentStore'
import { resolveNavigation } from './guards'
import { routes } from './routes'

export const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior: () => ({ top: 0 }),
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  const currentStore = useCurrentStoreStore()

  if (!auth.bootstrapped) {
    await auth.bootstrap()
  }

  if (auth.isAuthenticated && !currentStore.loaded) {
    try {
      await currentStore.refresh()
    } catch {
      // Errors are surfaced by the target page; the guard only needs the
      // resulting auth/store state to avoid redirect loops.
    }
  }

  const decision = resolveNavigation(to.meta, {
    isAuthenticated: auth.isAuthenticated,
    hasStore: currentStore.hasStore,
    isManager: currentStore.canManage,
    mustChangePassword: auth.mustChangePassword,
  })

  if (decision.type === 'redirect') {
    if (decision.name === 'login' && to.fullPath !== '/') {
      return { name: 'login', query: { redirect: to.fullPath } }
    }
    return { name: decision.name }
  }

  return true
})

export default router
