import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ApiError } from '@/lib/errors'
import { sessionService } from '@/services/session'
import { useAuthStore } from '@/stores/auth'
import { useCurrentStoreStore } from '@/stores/currentStore'
import { useToastStore } from '@/stores/toast'
import ChangePasswordPage from './ChangePasswordPage.vue'

const replace = vi.fn()
vi.mock('vue-router', () => ({ useRouter: () => ({ replace }) }))

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

const retailStore = {
  id: 1,
  name: 'Toko Contoh',
  slug: 'toko-contoh',
  role: 'cashier' as const,
  business_type: 'retail' as const,
  is_active: true,
}

function mountPage() {
  const pinia = createPinia()
  setActivePinia(pinia)
  const auth = useAuthStore()
  auth.setMustChangePassword(true)
  const store = useCurrentStoreStore()
  store.current = { ...retailStore }
  const toast = useToastStore()
  const wrapper = mount(ChangePasswordPage, { global: { plugins: [pinia] } })
  return { wrapper, auth, toast }
}

async function fill(wrapper: ReturnType<typeof mount>) {
  await wrapper.find('input[name="current_password"]').setValue('old-password')
  await wrapper.find('input[name="password"]').setValue('new-password-1')
  await wrapper.find('input[name="password_confirmation"]').setValue('new-password-1')
}

describe('ChangePasswordPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    localStorage.clear()
  })

  it('renders the current, new and confirmation fields', () => {
    const { wrapper } = mountPage()

    expect(wrapper.find('input[name="current_password"]').exists()).toBe(true)
    expect(wrapper.find('input[name="password"]').exists()).toBe(true)
    expect(wrapper.find('input[name="password_confirmation"]').exists()).toBe(true)
  })

  it('validates the fields before calling the API', async () => {
    const { wrapper } = mountPage()

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(sessionService.changePassword).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('Kata sandi saat ini wajib diisi.')
  })

  it('submits, clears the forced flag and redirects on success', async () => {
    vi.mocked(sessionService.changePassword).mockResolvedValue(undefined)
    const { wrapper, auth, toast } = mountPage()

    await fill(wrapper)
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(sessionService.changePassword).toHaveBeenCalledWith({
      current_password: 'old-password',
      password: 'new-password-1',
      password_confirmation: 'new-password-1',
    })
    expect(auth.mustChangePassword).toBe(false)
    expect(toast.toasts.some((item) => item.kind === 'success')).toBe(true)
    expect(replace).toHaveBeenCalledWith({ name: 'dashboard' })
  })

  it('shows a field error for a wrong current password (422)', async () => {
    vi.mocked(sessionService.changePassword).mockRejectedValue(
      new ApiError({
        message: 'The current password field is invalid.',
        status: 422,
        code: 'validation_error',
        errors: { current_password: ['Kata sandi saat ini tidak cocok.'] },
      }),
    )
    const { wrapper, auth } = mountPage()

    await fill(wrapper)
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('Kata sandi saat ini tidak cocok.')
    expect(auth.mustChangePassword).toBe(true)
  })
})
