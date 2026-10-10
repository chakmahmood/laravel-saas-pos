import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ApiError } from '@/lib/errors'
import { sessionService } from '@/services/session'
import LoginForm from './LoginForm.vue'

vi.mock('@/services/session', () => ({
  sessionService: {
    login: vi.fn(),
    register: vi.fn(),
    logout: vi.fn(),
    me: vi.fn(),
    getCurrentStore: vi.fn(),
    selectStore: vi.fn(),
  },
}))

function mountForm() {
  setActivePinia(createPinia())
  return mount(LoginForm, {
    global: { plugins: [createPinia()] },
  })
}

describe('LoginForm', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    window.localStorage.clear()
  })

  it('renders email and password fields', () => {
    const wrapper = mountForm()
    expect(wrapper.find('input[name="email"]').exists()).toBe(true)
    expect(wrapper.find('input[name="password"]').exists()).toBe(true)
  })

  it('shows a validation error when submitting empty', async () => {
    const wrapper = mountForm()
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('Email wajib diisi.')
    expect(sessionService.login).not.toHaveBeenCalled()
  })

  it('calls the API and emits success on valid credentials', async () => {
    vi.mocked(sessionService.login).mockResolvedValue({
      user: { id: 1, name: 'Budi', email: 'budi@example.com' },
      token: 'token-123',
      token_type: 'Bearer',
    })

    const wrapper = mountForm()
    await wrapper.find('input[name="email"]').setValue(' budi@example.com ')
    await wrapper.find('input[name="password"]').setValue('secret123')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(sessionService.login).toHaveBeenCalledWith('budi@example.com', 'secret123')
    expect(wrapper.emitted('success')).toHaveLength(1)
    expect(window.localStorage.getItem('saas_pos.admin.token')).toBe('token-123')
  })

  it('surfaces invalid credentials as a form error', async () => {
    vi.mocked(sessionService.login).mockRejectedValue(
      new ApiError({
        message: 'Email atau password salah.',
        status: 422,
        code: 'validation_error',
        errors: { email: ['Email atau password salah.'] },
      }),
    )

    const wrapper = mountForm()
    await wrapper.find('input[name="email"]').setValue('x@example.com')
    await wrapper.find('input[name="password"]').setValue('wrong')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.emitted('success')).toBeUndefined()
    expect(wrapper.text()).toContain('tidak valid')
  })
})
