import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import AppConfirmDialog from '@/components/ui/AppConfirmDialog.vue'
import AppErrorState from '@/components/ui/AppErrorState.vue'
import { categoriesService } from '@/features/categories/api'
import type { Category } from '@/features/categories/types'
import { ApiError } from '@/lib/errors'
import { useCurrentStoreStore } from '@/stores/currentStore'
import { useToastStore } from '@/stores/toast'
import type { PaginationMeta } from '@/types/api'
import CategoriesPage from './CategoriesPage.vue'

vi.mock('@/features/categories/api', () => ({
  categoriesService: { list: vi.fn(), listAll: vi.fn(), create: vi.fn(), update: vi.fn(), remove: vi.fn() },
}))

const retailStore = {
  id: 1,
  name: 'Toko Contoh',
  slug: 'toko-contoh',
  role: 'owner' as const,
  business_type: 'retail' as const,
  is_active: true,
}

function category(overrides: Partial<Category> = {}): Category {
  return {
    id: 1,
    name: 'Minuman',
    description: 'Semua minuman',
    is_active: true,
    created_at: null,
    updated_at: null,
    ...overrides,
  }
}

function paginated(data: Category[], meta: Partial<PaginationMeta> = {}) {
  return {
    data,
    links: { first: null, last: null, prev: null, next: null },
    meta: {
      current_page: 1,
      from: data.length > 0 ? 1 : 0,
      last_page: 1,
      links: [],
      path: '/api/categories',
      per_page: 15,
      to: data.length,
      total: data.length,
      ...meta,
    },
  }
}

function mountPage() {
  const pinia = createPinia()
  setActivePinia(pinia)
  const store = useCurrentStoreStore()
  store.current = { ...retailStore }
  const toast = useToastStore()
  const wrapper = mount(CategoriesPage, {
    global: { plugins: [pinia], stubs: { teleport: true } },
  })
  return { wrapper, toast }
}

function findButton(wrapper: ReturnType<typeof mount>, text: string) {
  return wrapper.findAll('button').find((button) => button.text().includes(text))
}

describe('CategoriesPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(categoriesService.list).mockResolvedValue(paginated([]))
  })

  it('renders categories from the API', async () => {
    vi.mocked(categoriesService.list).mockResolvedValue(paginated([category()]))

    const { wrapper } = mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('Minuman')
    expect(wrapper.text()).toContain('Aktif')
  })

  it('shows an empty state when there are no categories', async () => {
    const { wrapper } = mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('Belum Ada Kategori')
  })

  it('shows an error state and retries', async () => {
    vi.mocked(categoriesService.list).mockRejectedValueOnce(
      new ApiError({ message: 'boom', status: 500 }),
    )

    const { wrapper } = mountPage()
    await flushPromises()
    expect(wrapper.findComponent(AppErrorState).exists()).toBe(true)

    vi.mocked(categoriesService.list).mockResolvedValueOnce(paginated([category()]))
    await findButton(wrapper, 'Coba lagi')!.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Minuman')
  })

  it('creates a category through the modal', async () => {
    vi.mocked(categoriesService.create).mockResolvedValue(category({ id: 5, name: 'Makanan' }))
    const { wrapper } = mountPage()
    await flushPromises()

    await findButton(wrapper, 'Tambah Kategori')!.trigger('click')
    await flushPromises()
    await wrapper.find('input[name="name"]').setValue('Makanan')
    await wrapper.find('#category-form').trigger('submit')
    await flushPromises()

    expect(categoriesService.create).toHaveBeenCalledWith({
      name: 'Makanan',
      description: null,
      is_active: true,
    })
  })

  it('deletes a category after confirmation', async () => {
    vi.mocked(categoriesService.list).mockResolvedValue(paginated([category()]))
    vi.mocked(categoriesService.remove).mockResolvedValue(undefined)
    const { wrapper } = mountPage()
    await flushPromises()

    await findButton(wrapper, 'Hapus')!.trigger('click')
    await flushPromises()
    expect(wrapper.findComponent(AppConfirmDialog).props('open')).toBe(true)

    wrapper.findComponent(AppConfirmDialog).vm.$emit('confirm')
    await flushPromises()

    expect(categoriesService.remove).toHaveBeenCalledWith(1)
  })

  it('reports a 409 business conflict when a category is in use', async () => {
    vi.mocked(categoriesService.list).mockResolvedValue(paginated([category()]))
    vi.mocked(categoriesService.remove).mockRejectedValueOnce(
      new ApiError({
        message: 'Kategori masih digunakan oleh item dan tidak dapat dihapus.',
        status: 409,
        code: 'category_in_use',
      }),
    )
    const { wrapper, toast } = mountPage()
    await flushPromises()

    await findButton(wrapper, 'Hapus')!.trigger('click')
    await flushPromises()
    vi.mocked(categoriesService.list).mockClear()

    wrapper.findComponent(AppConfirmDialog).vm.$emit('confirm')
    await flushPromises()

    expect(wrapper.text()).toContain('Minuman')
    expect(toast.toasts.some((item) => item.kind === 'error')).toBe(true)
    expect(categoriesService.list).not.toHaveBeenCalled()
  })
})
