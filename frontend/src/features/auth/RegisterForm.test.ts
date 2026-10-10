import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { sessionService } from '@/services/session'
import RegisterForm from './RegisterForm.vue'

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

function mountForm() {
  const pinia = createPinia()
  setActivePinia(pinia)
  return mount(RegisterForm, { global: { plugins: [pinia], stubs: { teleport: true } } })
}

async function fillBase(wrapper: ReturnType<typeof mount>) {
  await wrapper.find('input[name="name"]').setValue('Budi')
  await wrapper.find('input[name="email"]').setValue('budi@example.com')
  await wrapper.find('input[name="password"]').setValue('password123')
  await wrapper.find('input[name="password_confirmation"]').setValue('password123')
  await wrapper.find('input[name="store_name"]').setValue('Toko Budi')
  await wrapper.find('input[name="store_slug"]').setValue('toko-budi')
}

describe('RegisterForm business type', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    localStorage.clear()
  })

  it('offers only the two canonical business groups', () => {
    const wrapper = mountForm()

    const options = wrapper
      .find('select[name="business_type"]')
      .findAll('option')
      .map((option) => option.element.value)
      .filter((value) => value !== '')

    expect(options).toEqual(['retail', 'service'])
  })

  it('requires a business type before submitting', async () => {
    const wrapper = mountForm()

    await fillBase(wrapper)
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(sessionService.register).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('Jenis usaha wajib dipilih.')
  })

  it('submits the chosen business type', async () => {
    vi.mocked(sessionService.register).mockResolvedValue({
      user: { id: 1, name: 'Budi', email: 'budi@example.com' },
      token: 'token',
      token_type: 'Bearer',
      store: { id: 1, name: 'Toko Budi', slug: 'toko-budi', business_type: 'service' },
    })
    const wrapper = mountForm()

    await fillBase(wrapper)
    await wrapper.find('select[name="business_type"]').setValue('service')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(sessionService.register).toHaveBeenCalledWith(
      expect.objectContaining({ business_type: 'service' }),
    )
  })
})
