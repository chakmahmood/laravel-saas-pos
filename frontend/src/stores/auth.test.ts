import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { sessionService } from '@/services/session'
import { useAuthStore } from './auth'

vi.mock('@/services/session', () => ({
  sessionService: {
    login: vi.fn(),
    register: vi.fn(),
    logout: vi.fn(),
    me: vi.fn(),
    getCurrentStore: vi.fn(),
    selectStore: vi.fn(),
    changePassword: vi.fn(),
  },
}))

function freshStore() {
  setActivePinia(createPinia())
  return useAuthStore()
}

describe('auth store', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    localStorage.clear()
  })

  it('clears mustChangePassword after a successful password change', async () => {
    vi.mocked(sessionService.changePassword).mockResolvedValue(undefined)

    const auth = freshStore()
    auth.setMustChangePassword(true)
    expect(auth.mustChangePassword).toBe(true)

    await auth.changePassword({
      current_password: 'old-password',
      password: 'new-password-1',
      password_confirmation: 'new-password-1',
    })

    expect(sessionService.changePassword).toHaveBeenCalledWith({
      current_password: 'old-password',
      password: 'new-password-1',
      password_confirmation: 'new-password-1',
    })
    expect(auth.mustChangePassword).toBe(false)
  })

  it('keeps mustChangePassword when the change fails', async () => {
    vi.mocked(sessionService.changePassword).mockRejectedValue(new Error('nope'))

    const auth = freshStore()
    auth.setMustChangePassword(true)

    await expect(
      auth.changePassword({
        current_password: 'old-password',
        password: 'new-password-1',
        password_confirmation: 'new-password-1',
      }),
    ).rejects.toThrow()

    expect(auth.mustChangePassword).toBe(true)
  })

  it('resets mustChangePassword when the session is cleared', () => {
    const auth = freshStore()
    auth.setMustChangePassword(true)

    auth.clear()

    expect(auth.mustChangePassword).toBe(false)
    expect(auth.isAuthenticated).toBe(false)
  })
})
