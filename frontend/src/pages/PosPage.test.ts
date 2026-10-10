import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ordersService, paymentsService } from '@/features/pos/api'
import type { Order, OrderPayment } from '@/features/pos/types'
import { productsService } from '@/features/products/api'
import type { Product } from '@/features/products/types'
import { ApiError } from '@/lib/errors'
import { useCurrentStoreStore } from '@/stores/currentStore'
import { useToastStore } from '@/stores/toast'
import type { PaginationMeta } from '@/types/api'
import PosPage from './PosPage.vue'

vi.mock('@/features/pos/api', () => ({
  ordersService: { create: vi.fn(), show: vi.fn() },
  paymentsService: { record: vi.fn() },
}))
vi.mock('@/features/products/api', () => ({
  productsService: { list: vi.fn(), show: vi.fn(), create: vi.fn(), update: vi.fn(), remove: vi.fn() },
}))

const retailStore = {
  id: 1,
  name: 'Toko Contoh',
  slug: 'toko-contoh',
  role: 'cashier' as const,
  business_type: 'retail' as const,
  is_active: true,
}

function product(overrides: Partial<Product> = {}): Product {
  return {
    id: 1,
    category_id: null,
    name: 'Kopi Susu',
    type: 'product',
    sku: 'K-1',
    barcode: null,
    description: null,
    cost_price: null,
    selling_price: 18000,
    unit: 'pcs',
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
      per_page: 24,
      to: data.length,
      total: data.length,
      ...meta,
    },
  }
}

function order(overrides: Partial<Order> = {}): Order {
  return {
    id: 101,
    order_number: 'TRX-20260101-0001',
    customer_id: null,
    cashier_id: 9,
    subtotal: 36000,
    discount_amount: 0,
    tax_amount: 0,
    total_amount: 36000,
    paid_amount: 0,
    payment_status: 'unpaid',
    fulfillment_status: 'pending',
    notes: null,
    placed_at: '2026-01-01T08:00:00.000000Z',
    completed_at: null,
    cancelled_at: null,
    cancel_reason: null,
    created_at: '2026-01-01T08:00:00.000000Z',
    updated_at: '2026-01-01T08:00:00.000000Z',
    ...overrides,
  }
}

function payment(overrides: Partial<OrderPayment> = {}): OrderPayment {
  return {
    id: 1,
    order_id: 101,
    payment_method: 'cash',
    amount: 36000,
    status: 'completed',
    reference_number: null,
    notes: null,
    paid_at: '2026-01-01T08:00:00.000000Z',
    recorded_by: 9,
    voided_at: null,
    voided_by: null,
    void_reason: null,
    created_at: '2026-01-01T08:00:00.000000Z',
    updated_at: '2026-01-01T08:00:00.000000Z',
    ...overrides,
  }
}

function mountPage() {
  const pinia = createPinia()
  setActivePinia(pinia)
  const store = useCurrentStoreStore()
  store.current = { ...retailStore }
  const toast = useToastStore()
  const wrapper = mount(PosPage, {
    global: { plugins: [pinia], stubs: { teleport: true } },
  })
  return { wrapper, store, toast }
}

function findButton(wrapper: ReturnType<typeof mount>, text: string) {
  return wrapper.findAll('button').find((button) => button.text().includes(text))
}

async function addToCart(wrapper: ReturnType<typeof mount>, name: string, times = 1) {
  for (let i = 0; i < times; i += 1) {
    await findButton(wrapper, name)!.trigger('click')
    await flushPromises()
  }
}

describe('PosPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(productsService.list).mockResolvedValue(paginated([product()]))
  })

  it('loads active products from the API', async () => {
    const { wrapper } = mountPage()
    await flushPromises()

    expect(productsService.list).toHaveBeenCalledWith(
      expect.objectContaining({ is_active: true, page: 1 }),
    )
    expect(wrapper.text()).toContain('Kopi Susu')
    expect(wrapper.text()).toContain('18.000')
  })

  it('adds a product and previews subtotal and total', async () => {
    const { wrapper } = mountPage()
    await flushPromises()

    await addToCart(wrapper, 'Kopi Susu', 2)

    expect(wrapper.text()).toContain('2 item')
    // Two lines of 18.000 => subtotal and total 36.000.
    expect(wrapper.text()).toContain('36.000')
  })

  it('increments, decrements and removes cart lines', async () => {
    const { wrapper } = mountPage()
    await flushPromises()
    await addToCart(wrapper, 'Kopi Susu')

    await wrapper.find('button[aria-label="Tambah Kopi Susu"]').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('2 item')

    // Decrementing from 1 removes the line so quantity can never become 0.
    await wrapper.find('button[aria-label="Kurangi Kopi Susu"]').trigger('click')
    await flushPromises()
    await wrapper.find('button[aria-label="Kurangi Kopi Susu"]').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('Keranjang masih kosong')
  })

  it('checks out through the two-step server contract', async () => {
    vi.mocked(ordersService.create).mockResolvedValue(order())
    vi.mocked(paymentsService.record).mockResolvedValue(payment())
    vi.mocked(ordersService.show).mockResolvedValue(order({ payment_status: 'paid', paid_amount: 36000 }))

    const { wrapper } = mountPage()
    await flushPromises()
    await addToCart(wrapper, 'Kopi Susu', 2)

    await findButton(wrapper, 'Proses pembayaran')!.trigger('click')
    await flushPromises()

    // The client never sends a store id or a client-computed price/total.
    expect(ordersService.create).toHaveBeenCalledWith({
      items: [{ item_id: 1, quantity: 2 }],
    })
    // The server-computed total is what gets paid, not the previewed amount.
    expect(paymentsService.record).toHaveBeenCalledWith(101, {
      payment_method: 'cash',
      amount: 36000,
    })

    expect(wrapper.text()).toContain('Transaksi berhasil')
    expect(wrapper.text()).toContain('TRX-20260101-0001')
    expect(wrapper.text()).toContain('Lunas')
  })

  it('keeps the cart and reports the error when checkout fails', async () => {
    vi.mocked(ordersService.create).mockRejectedValueOnce(
      new ApiError({ message: 'Stok tidak cukup.', status: 409, code: 'insufficient_stock' }),
    )

    const { wrapper, toast } = mountPage()
    await flushPromises()
    await addToCart(wrapper, 'Kopi Susu')

    await findButton(wrapper, 'Proses pembayaran')!.trigger('click')
    await flushPromises()

    expect(paymentsService.record).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('Stok tidak cukup.')
    expect(wrapper.text()).toContain('Kopi Susu')
    expect(toast.toasts.some((t) => t.kind === 'error')).toBe(true)
  })

  it('shows a 422 validation message and keeps the cart', async () => {
    vi.mocked(ordersService.create).mockRejectedValueOnce(
      new ApiError({
        message: 'invalid',
        status: 422,
        code: 'validation_error',
        errors: { 'items.0.item_id': ['Item tidak aktif dan tidak dapat dijual.'] },
      }),
    )

    const { wrapper } = mountPage()
    await flushPromises()
    await addToCart(wrapper, 'Kopi Susu')

    await findButton(wrapper, 'Proses pembayaran')!.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Item tidak aktif dan tidak dapat dijual.')
    expect(wrapper.text()).toContain('Kopi Susu')
  })

  it('retries payment on the same order without creating a duplicate', async () => {
    vi.mocked(ordersService.create).mockResolvedValue(order())
    vi.mocked(paymentsService.record)
      .mockRejectedValueOnce(
        new ApiError({
          message: 'Shift kas belum dibuka.',
          status: 409,
          code: 'cash_session_required',
        }),
      )
      .mockResolvedValueOnce(payment())
    vi.mocked(ordersService.show).mockResolvedValue(order({ payment_status: 'paid', paid_amount: 36000 }))

    const { wrapper } = mountPage()
    await flushPromises()
    await addToCart(wrapper, 'Kopi Susu', 2)

    await findButton(wrapper, 'Proses pembayaran')!.trigger('click')
    await flushPromises()

    // Order created once, payment failed: the cart is kept and the pending
    // order is surfaced instead of a false success.
    expect(ordersService.create).toHaveBeenCalledTimes(1)
    expect(wrapper.text()).toContain('Shift kas belum dibuka.')
    expect(wrapper.text()).toContain('TRX-20260101-0001')
    expect(wrapper.text()).not.toContain('Transaksi berhasil')

    await findButton(wrapper, 'Bayar ulang')!.trigger('click')
    await flushPromises()

    // Retry pays the existing order; no second order is created.
    expect(ordersService.create).toHaveBeenCalledTimes(1)
    expect(paymentsService.record).toHaveBeenCalledTimes(2)
    expect(wrapper.text()).toContain('Transaksi berhasil')
  })

  it('prevents double submission while a checkout is in flight', async () => {
    vi.mocked(ordersService.create).mockReturnValue(new Promise(() => {}))

    const { wrapper } = mountPage()
    await flushPromises()
    await addToCart(wrapper, 'Kopi Susu')

    const button = findButton(wrapper, 'Proses pembayaran')!
    await button.trigger('click')
    await button.trigger('click')
    await flushPromises()

    expect(ordersService.create).toHaveBeenCalledTimes(1)
  })

  it('searches products through the API after the debounce delay', async () => {
    const { wrapper } = mountPage()
    await flushPromises()
    vi.mocked(productsService.list).mockClear()

    await wrapper.find('input[name="pos-search"]').setValue('kopi')

    await vi.waitFor(() => {
      expect(productsService.list).toHaveBeenCalledWith(
        expect.objectContaining({ search: 'kopi', page: 1 }),
      )
    })
  })

  it('clears the cart and reloads when the current store changes', async () => {
    const { wrapper, store } = mountPage()
    await flushPromises()
    await addToCart(wrapper, 'Kopi Susu')
    expect(wrapper.text()).toContain('1 item')

    store.current = { ...retailStore, id: 2, name: 'Toko Kedua' }
    await flushPromises()

    expect(wrapper.text()).toContain('Keranjang masih kosong')
    expect(wrapper.text()).toContain('Toko Kedua')
  })
})
