import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { tokenStorage } from '@/lib/token'
import { sessionService } from '@/services/session'
import type { AuthUser, ChangePasswordPayload, RegisterPayload } from '@/types/models'

/**
 * Authentication state: the bearer token plus the authenticated user.
 * The backend remains the authority; this store only mirrors the session.
 */
export const useAuthStore = defineStore('auth', () => {
  const token = ref<string | null>(tokenStorage.get())
  const user = ref<AuthUser | null>(null)
  const bootstrapped = ref(false)
  const loading = ref(false)

  /**
   * Set when the backend refuses a business endpoint with
   * `403 password_change_required`. It is never derived from token contents,
   * only from the actual API response.
   */
  const mustChangePassword = ref(false)

  const isAuthenticated = computed(() => token.value !== null)

  function setToken(value: string | null): void {
    token.value = value
    if (value) {
      tokenStorage.set(value)
    } else {
      tokenStorage.clear()
    }
  }

  function setUser(value: AuthUser | null): void {
    user.value = value
  }

  function setMustChangePassword(value: boolean): void {
    mustChangePassword.value = value
  }

  async function login(email: string, password: string): Promise<void> {
    loading.value = true
    try {
      const result = await sessionService.login(email, password)
      setToken(result.token)
      user.value = result.user
      mustChangePassword.value = false
      bootstrapped.value = true
    } finally {
      loading.value = false
    }
  }

  async function register(payload: RegisterPayload): Promise<void> {
    loading.value = true
    try {
      const result = await sessionService.register(payload)
      setToken(result.token)
      user.value = result.user
      mustChangePassword.value = false
      bootstrapped.value = true
    } finally {
      loading.value = false
    }
  }

  /**
   * Rotate the current user's password. On success the forced-change flag is
   * cleared locally to match the backend.
   */
  async function changePassword(payload: ChangePasswordPayload): Promise<void> {
    await sessionService.changePassword(payload)
    mustChangePassword.value = false
  }

  async function fetchMe(): Promise<AuthUser | null> {
    const me = await sessionService.me()
    user.value = me.user
    return me.user
  }

  /**
   * Restore the session on app start. A 401 clears an invalid/expired token; a
   * transient (network) failure keeps the token so the user can retry.
   */
  async function bootstrap(): Promise<void> {
    if (bootstrapped.value) {
      return
    }
    if (!token.value) {
      bootstrapped.value = true
      return
    }
    try {
      await fetchMe()
    } catch (error) {
      if (isUnauthorized(error)) {
        clear()
      }
    } finally {
      bootstrapped.value = true
    }
  }

  async function logout(): Promise<void> {
    try {
      if (token.value) {
        await sessionService.logout()
      }
    } catch {
      // Logging out locally must succeed even if the server call fails.
    } finally {
      clear()
    }
  }

  /** Clear the local session without calling the server (used for 401). */
  function clear(): void {
    setToken(null)
    user.value = null
    mustChangePassword.value = false
    bootstrapped.value = true
  }

  return {
    token,
    user,
    bootstrapped,
    loading,
    mustChangePassword,
    isAuthenticated,
    setToken,
    setUser,
    setMustChangePassword,
    login,
    register,
    changePassword,
    fetchMe,
    bootstrap,
    logout,
    clear,
  }
})

function isUnauthorized(error: unknown): boolean {
  return (
    typeof error === 'object' &&
    error !== null &&
    'status' in error &&
    (error as { status?: number }).status === 401
  )
}
