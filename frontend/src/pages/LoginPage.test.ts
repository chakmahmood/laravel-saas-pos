import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import { routes } from '@/router/routes'
import LoginPage from './LoginPage.vue'

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

async function mountPage() {
  const router = createRouter({ history: createMemoryHistory(), routes })
  router.push('/login')
  await router.isReady()
  setActivePinia(createPinia())
  return mount(LoginPage, {
    global: { plugins: [createPinia(), router] },
  })
}

describe('LoginPage', () => {
  beforeEach(() => {
    window.localStorage.clear()
  })

  it('renders the login heading and form', async () => {
    const wrapper = await mountPage()
    expect(wrapper.text()).toContain('Masuk ke akun Anda')
    expect(wrapper.find('input[name="email"]').exists()).toBe(true)
    expect(wrapper.find('input[name="password"]').exists()).toBe(true)
  })

  it('links to the registration page', async () => {
    const wrapper = await mountPage()
    const link = wrapper.findAll('a').find((anchor) => anchor.text().includes('Daftar toko'))
    expect(link?.attributes('href')).toBe('/register')
  })
})
