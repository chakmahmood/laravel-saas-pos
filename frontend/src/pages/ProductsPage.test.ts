import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import AppConfirmDialog from '@/components/ui/AppConfirmDialog.vue'
import AppErrorState from '@/components/ui/AppErrorState.vue'
import { categoriesService } from '@/features/categories/api'
import { productsService } from '@/features/products/api'
import type { Product } from '@/features/products/types'
import { ApiError } from '@/lib/errors'
import { useCurrentStoreStore } from '@/stores/currentStore'
import { useToastStore } from '@/stores/toast'
import type { PaginationMeta } from '@/types/api'
import ProductsPage from './ProductsPage.vue'

vi.mock('@/features/products/api', () => ({
  productsService: { list: vi.fn(), show: vi.fn(), create: vi.fn(), update: vi.fn(), remove: vi.fn() },
}))
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

function product(overrides: Partial<Product> = {}): Product {
  return {
    id: 1,
    category_id: null,
    name: 'Kopi Susu',
    type: 'menu',
    sku: 'K-1',
    barcode: null,
    description: null,
    cost_price: null,
    selling_price: 18000,
    unit: 'porsi',
    is_active: true,
    tracks_stock: false,
    created_at: null,
    updated_at: null,
    ...overrides,
  }
}

function paginated(data: Product[], meta: Partial<PaginationMeta> = {}) {
  return {
    data,
    links: { first: null, last: null, prev: null, next: null },
    meta: {
      current_page: 1,
      from: data.length > 0 ? 1 : 0,
      last_page: 1,
      links: [],
      path: '/api/items',
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
  const wrapper = mount(ProductsPage, {
    global: { plugins: [pinia], stubs: { teleport: true } },
  })
  return { wrapper, store, toast }
}

function findButton(wrapper: ReturnType<typeof mount>, text: string) {
  return wrapper.findAll('button').find((button) => button.text().includes(text))
}

describe('ProductsPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(categoriesService.listAll).mockResolvedValue([])
    vi.mocked(productsService.list).mockResolvedValue(paginated([]))
  })

  it('renders products from the API with formatted price', async () => {
    vi.mocked(productsService.list).mockResolvedValue(paginated([product()]))

    const { wrapper } = mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('Kopi Susu')
    expect(wrapper.text()).toContain('18.000')
  })

  it('shows a loading state before data resolves', async () => {
    vi.mocked(productsService.list).mockReturnValue(new Promise(() => {}))

    const { wrapper } = mountPage()
    await nextTick()

    expect(wrapper.find('.animate-pulse').exists()).toBe(true)
  })

  it('shows an empty state when there are no products', async () => {
    const { wrapper } = mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('Belum Ada Produk')
  })

  it('shows an error state and retries', async () => {
    vi.mocked(productsService.list).mockRejectedValueOnce(
      new ApiError({ message: 'boom', status: 500 }),
    )

    const { wrapper } = mountPage()
    await flushPromises()

    expect(wrapper.findComponent(AppErrorState).exists()).toBe(true)

    vi.mocked(productsService.list).mockResolvedValueOnce(paginated([product()]))
    await findButton(wrapper, 'Coba lagi')!.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Kopi Susu')
  })

  it('does not treat a 403 as an empty list', async () => {
    vi.mocked(productsService.list).mockRejectedValueOnce(
      new ApiError({ message: 'Akses ditolak.', status: 403, code: 'forbidden' }),
    )

    const { wrapper } = mountPage()
    await flushPromises()

    expect(wrapper.findComponent(AppErrorState).exists()).toBe(true)
    expect(wrapper.text()).toContain('izin')
  })

  it('searches through the API after the debounce delay', async () => {
    vi.mocked(productsService.list).mockResolvedValue(paginated([product()]))
    const { wrapper } = mountPage()
    await flushPromises()
    vi.mocked(productsService.list).mockClear()

    await wrapper.find('input[name="product-search"]').setValue('kopi')

    await vi.waitFor(() => {
      expect(productsService.list).toHaveBeenCalledWith(
        expect.objectContaining({ search: 'kopi', page: 1 }),
      )
    })
  })

  it('requests the selected page', async () => {
    vi.mocked(productsService.list).mockResolvedValue(
      paginated([product()], { last_page: 3, total: 40, to: 15 }),
    )
    const { wrapper } = mountPage()
    await flushPromises()
    vi.mocked(productsService.list).mockClear()

    await wrapper.findAll('button').find((button) => button.text() === '2')!.trigger('click')
    await flushPromises()

    expect(productsService.list).toHaveBeenCalledWith(expect.objectContaining({ page: 2 }))
  })

  it('creates a product through the modal', async () => {
    vi.mocked(productsService.create).mockResolvedValue(product({ id: 10, name: 'Teh' }))
    const { wrapper } = mountPage()
    await flushPromises()

    await findButton(wrapper, 'Tambah Produk')!.trigger('click')
    await flushPromises()

    await wrapper.find('input[name="name"]').setValue('Teh')
    await wrapper.find('input[name="selling_price"]').setValue('5000')
    await wrapper.find('#product-form').trigger('submit')
    await flushPromises()

    expect(productsService.create).toHaveBeenCalledTimes(1)
    const payload = vi.mocked(productsService.create).mock.calls[0][0]
    expect(payload.name).toBe('Teh')
    expect(payload.selling_price).toBe(5000)
    expect(payload.type).toBe('product')
  })

  it('shows 422 validation errors on the related field', async () => {
    vi.mocked(productsService.create).mockRejectedValueOnce(
      new ApiError({
        message: 'invalid',
        status: 422,
        code: 'validation_error',
        errors: { name: ['Nama sudah dipakai.'] },
      }),
    )
    const { wrapper } = mountPage()
    await flushPromises()

    await findButton(wrapper, 'Tambah Produk')!.trigger('click')
    await flushPromises()
    await wrapper.find('input[name="name"]').setValue('X')
    await wrapper.find('input[name="selling_price"]').setValue('1000')
    await wrapper.find('#product-form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('Nama sudah dipakai.')
  })

  it('deletes a product after confirmation', async () => {
    vi.mocked(productsService.list).mockResolvedValue(paginated([product()]))
    vi.mocked(productsService.remove).mockResolvedValue(undefined)
    const { wrapper } = mountPage()
    await flushPromises()

    await findButton(wrapper, 'Hapus')!.trigger('click')
    await flushPromises()
    expect(wrapper.findComponent(AppConfirmDialog).props('open')).toBe(true)

    wrapper.findComponent(AppConfirmDialog).vm.$emit('confirm')
    await flushPromises()

    expect(productsService.remove).toHaveBeenCalledWith(1)
  })

  it('keeps the row and reports a conflict when delete fails', async () => {
    vi.mocked(productsService.list).mockResolvedValue(paginated([product()]))
    vi.mocked(productsService.remove).mockRejectedValueOnce(
      new ApiError({
        message: 'Item memiliki riwayat stok dan tidak dapat dihapus.',
        status: 409,
        code: 'item_has_stock_history',
      }),
    )
    const { wrapper, toast } = mountPage()
    await flushPromises()

    await findButton(wrapper, 'Hapus')!.trigger('click')
    await flushPromises()
    vi.mocked(productsService.list).mockClear()

    wrapper.findComponent(AppConfirmDialog).vm.$emit('confirm')
    await flushPromises()

    expect(wrapper.text()).toContain('Kopi Susu')
    expect(toast.toasts.some((item) => item.kind === 'error')).toBe(true)
    expect(productsService.list).not.toHaveBeenCalled()
  })

  it('clears and reloads when the current store changes', async () => {
    vi.mocked(productsService.list).mockResolvedValue(paginated([product()]))
    const { wrapper, store } = mountPage()
    await flushPromises()

    vi.mocked(productsService.list).mockClear()
    vi.mocked(productsService.list).mockResolvedValue(paginated([product({ id: 2, name: 'Teh Manis' })]))

    store.current = { ...retailStore, id: 2, name: 'Toko Kedua' }
    await flushPromises()

    expect(productsService.list).toHaveBeenCalled()
    expect(wrapper.text()).toContain('Teh Manis')
  })
})
