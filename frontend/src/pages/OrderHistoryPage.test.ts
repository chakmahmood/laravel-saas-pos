import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ordersService } from '@/features/pos/api'
import type { Order, OrderPayment } from '@/features/pos/types'
import { ApiError } from '@/lib/errors'
import { useCurrentStoreStore } from '@/stores/currentStore'
import type { PaginationMeta } from '@/types/api'
import OrderHistoryPage from './OrderHistoryPage.vue'

const routerMock = vi.hoisted(() => ({ push: vi.fn() }))
vi.mock('vue-router', () => ({ useRouter: () => routerMock }))

vi.mock('@/features/pos/api', () => ({
  ordersService: { create: vi.fn(), list: vi.fn(), show: vi.fn(), reconcile: vi.fn() },
  paymentsService: { record: vi.fn() },
}))

const retailStore = {
  id: 1,
  name: 'Toko Contoh',
  slug: 'toko-contoh',
  role: 'cashier' as const,
  business_type: 'retail' as const,
  is_active: true,
}

function order(overrides: Partial<Order> = {}): Order {
  const merged: Order = {
    id: 10,
    order_number: 'TRX-20260101-0001',
    customer_id: null,
    cashier_id: 9,
    subtotal: 20000,
    discount_amount: 0,
    tax_amount: 0,
    total_amount: 20000,
    paid_amount: 20000,
    remaining_amount: 0,
    payment_status: 'paid',
    fulfillment_status: 'completed',
    notes: null,
    placed_at: '2026-01-01T08:00:00.000000Z',
    completed_at: '2026-01-01T09:00:00.000000Z',
    cancelled_at: null,
    cancel_reason: null,
    customer: null,
    created_at: '2026-01-01T08:00:00.000000Z',
    updated_at: '2026-01-01T08:00:00.000000Z',
    ...overrides,
  }
  return {
    ...merged,
    remaining_amount:
      overrides.remaining_amount ?? Math.max(0, merged.total_amount - merged.paid_amount),
  }
}

function payment(overrides: Partial<OrderPayment> = {}): OrderPayment {
  return {
    id: 1,
    order_id: 10,
    payment_method: 'cash',
    amount: 20000,
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

function paginated(data: Order[], meta: Partial<PaginationMeta> = {}) {
  return {
    data,
    links: { first: null, last: null, prev: null, next: null },
    meta: {
      current_page: 1,
      from: data.length > 0 ? 1 : 0,
      last_page: 1,
      links: [],
      path: '/api/orders',
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
  const wrapper = mount(OrderHistoryPage, {
    global: { plugins: [pinia], stubs: { teleport: true } },
  })
  return { wrapper, store }
}

describe('OrderHistoryPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(ordersService.list).mockResolvedValue(paginated([]))
  })

  it('loads and renders orders from the API with server statuses', async () => {
    vi.mocked(ordersService.list).mockResolvedValue(
      paginated([order({ customer: { id: 1, name: 'Budi' } })]),
    )

    const { wrapper } = mountPage()
    await flushPromises()

    expect(ordersService.list).toHaveBeenCalledWith(
      expect.objectContaining({ page: 1, per_page: 15 }),
    )
    expect(wrapper.text()).toContain('TRX-20260101-0001')
    expect(wrapper.text()).toContain('Budi')
    expect(wrapper.text()).toContain('Lunas')
    expect(wrapper.text()).toContain('Selesai')
  })

  it('shows an empty state when there are no orders', async () => {
    const { wrapper } = mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('Belum ada transaksi')
  })

  it('shows an error state and retries', async () => {
    vi.mocked(ordersService.list).mockRejectedValueOnce(
      new ApiError({ message: 'boom', status: 500 }),
    )

    const { wrapper } = mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('server')

    vi.mocked(ordersService.list).mockResolvedValueOnce(paginated([order()]))
    const retry = wrapper.findAll('button').find((b) => b.text().includes('Coba lagi'))!
    await retry.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('TRX-20260101-0001')
  })

  it('searches by order number after the debounce delay', async () => {
    const { wrapper } = mountPage()
    await flushPromises()
    vi.mocked(ordersService.list).mockClear()

    await wrapper.find('input[name="order-search"]').setValue('TRX')

    await vi.waitFor(() => {
      expect(ordersService.list).toHaveBeenCalledWith(
        expect.objectContaining({ search: 'TRX', page: 1 }),
      )
    })
  })

  it('applies the payment status filter', async () => {
    const { wrapper } = mountPage()
    await flushPromises()
    vi.mocked(ordersService.list).mockClear()

    await wrapper.find('select[name="order-payment-status"]').setValue('paid')
    await flushPromises()

    expect(ordersService.list).toHaveBeenCalledWith(
      expect.objectContaining({ payment_status: 'paid', page: 1 }),
    )
  })

  it('opens a detail modal with real order data', async () => {
    vi.mocked(ordersService.list).mockResolvedValue(paginated([order()]))
    vi.mocked(ordersService.show).mockResolvedValue(
      order({
        payment_status: 'partially_paid',
        paid_amount: 5000,
        remaining_amount: 15000,
        items: [
          {
            id: 1,
            item_id: 5,
            item_name: 'Kopi Susu',
            item_sku: 'K-1',
            item_type: 'product',
            unit: 'pcs',
            quantity: 2,
            unit_price: 10000,
            line_subtotal: 20000,
            discount_amount: 0,
            line_total: 20000,
            notes: null,
          },
        ],
        payments: [payment({ amount: 5000 })],
      }),
    )

    const { wrapper } = mountPage()
    await flushPromises()

    await wrapper.findAll('button').find((b) => b.text().includes('Detail'))!.trigger('click')
    await flushPromises()

    expect(ordersService.show).toHaveBeenCalledWith(10)
    const text = wrapper.text()
    expect(text).toContain('Kopi Susu')
    expect(text).toContain('Dibayar sebagian')
    expect(text).toContain('Tunai')
    // Remaining balance comes from the server payload (15.000).
    expect(text).toContain('15.000')
  })

  it('navigates back to the cashier', async () => {
    const { wrapper } = mountPage()
    await flushPromises()

    await wrapper.findAll('button').find((b) => b.text().includes('Kembali ke kasir'))!.trigger('click')

    expect(routerMock.push).toHaveBeenCalledWith({ name: 'orders' })
  })
})
