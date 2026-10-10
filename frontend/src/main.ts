import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import { setPasswordChangeRequiredHandler, setUnauthorizedHandler } from './lib/http'
import { useAuthStore } from './stores/auth'
import { useCurrentStoreStore } from './stores/currentStore'
import './assets/main.css'

const app = createApp(App)

app.use(createPinia())
app.use(router)

/*
 * A 401 on any protected request means the session is gone: clear local state
 * and send the user to the login page (unless they are already on a public
 * route). The backend remains the source of truth for authentication.
 */
setUnauthorizedHandler(() => {
  const auth = useAuthStore()
  const currentStore = useCurrentStoreStore()
  auth.clear()
  currentStore.clear()

  const current = router.currentRoute.value
  if (current.meta.guestOnly !== true && current.name !== 'login') {
    void router.replace({
      name: 'login',
      query: current.fullPath !== '/' ? { redirect: current.fullPath } : undefined,
    })
  }
})

/*
 * A business endpoint refused with `403 password_change_required` means the
 * account still uses the initial password set by its owner/admin. Keep the
 * session, flag it, and send the user to the forced-change page. The route
 * guard prevents navigation back into business pages until it is resolved.
 */
setPasswordChangeRequiredHandler(() => {
  const auth = useAuthStore()
  auth.setMustChangePassword(true)

  if (router.currentRoute.value.name !== 'change-password') {
    void router.replace({ name: 'change-password' })
  }
})

app.mount('#app')
