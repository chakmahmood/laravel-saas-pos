import type { ItemType } from '@/types/enums'

/**
 * POS (order + payment) types, mirrored from the backend contract documented in
 * `docs/order-api.md`, `App\Http\Resources\OrderResource`,
 * `App\Http\Resources\OrderItemResource`, `App\Http\Resources\PaymentResource`,
 * and the enums in `App\Enums\*`.
 *
 * Money is always an integer number of rupiah. The POS only previews totals;
 * the server computes and validates the authoritative values.
 */

export type OrderPaymentStatus = 'unpaid' | 'partially_paid' | 'paid' | 'refunded'
export type OrderFulfillmentStatus = 'pending' | 'processing' | 'completed' | 'cancelled'
export type PaymentMethod = 'cash' | 'bank_transfer' | 'card' | 'e_wallet' | 'other'
export type PaymentRecordStatus = 'completed' | 'voided'

export interface OrderItem {
  id: number
  item_id: number | null
  item_name: string
  item_sku: string | null
  item_type: ItemType
  unit: string
  quantity: number
  unit_price: number
  line_subtotal: number
  discount_amount: number
  line_total: number
  notes: string | null
}

export interface OrderCustomer {
  id: number
  name: string
}

export interface OrderPayment {
  id: number
  order_id: number
  payment_method: PaymentMethod
  amount: number
  status: PaymentRecordStatus
  reference_number: string | null
  notes: string | null
  paid_at: string | null
  recorded_by: number | null
  voided_at: string | null
  voided_by: number | null
  void_reason: string | null
  created_at: string | null
  updated_at: string | null
}

export interface Order {
  id: number
  order_number: string
  customer_id: number | null
  cashier_id: number | null
  subtotal: number
  discount_amount: number
  tax_amount: number
  total_amount: number
  paid_amount: number
  payment_status: OrderPaymentStatus
  fulfillment_status: OrderFulfillmentStatus
  notes: string | null
  placed_at: string | null
  completed_at: string | null
  cancelled_at: string | null
  cancel_reason: string | null
  customer?: OrderCustomer | null
  items?: OrderItem[]
  payments?: OrderPayment[]
  created_at: string | null
  updated_at: string | null
}

export interface OrderCreateItemPayload {
  item_id: number
  quantity: number
  discount_amount?: number
}

/**
 * `POST /api/orders`. The tenant (store) is resolved from the bearer token by
 * the `current.store` middleware and is never sent by the client.
 */
export interface OrderCreatePayload {
  customer_id?: number | null
  items: OrderCreateItemPayload[]
  tax_amount?: number
  notes?: string | null
}

/** `POST /api/orders/{order}/payments`. */
export interface PaymentCreatePayload {
  payment_method: PaymentMethod
  amount: number
  reference_number?: string | null
  notes?: string | null
  paid_at?: string | null
}

export const PAYMENT_METHODS: ReadonlyArray<{ value: PaymentMethod; label: string }> = [
  { value: 'cash', label: 'Tunai' },
  { value: 'bank_transfer', label: 'Transfer Bank' },
  { value: 'card', label: 'Kartu' },
  { value: 'e_wallet', label: 'E-Wallet' },
  { value: 'other', label: 'Lainnya' },
]

const PAYMENT_STATUS_LABELS: Record<OrderPaymentStatus, string> = {
  unpaid: 'Belum dibayar',
  partially_paid: 'Dibayar sebagian',
  paid: 'Lunas',
  refunded: 'Dikembalikan',
}

const FULFILLMENT_STATUS_LABELS: Record<OrderFulfillmentStatus, string> = {
  pending: 'Menunggu',
  processing: 'Diproses',
  completed: 'Selesai',
  cancelled: 'Dibatalkan',
}

export function paymentStatusLabel(status: OrderPaymentStatus): string {
  return PAYMENT_STATUS_LABELS[status] ?? status
}

export function fulfillmentStatusLabel(status: OrderFulfillmentStatus): string {
  return FULFILLMENT_STATUS_LABELS[status] ?? status
}

export function paymentStatusVariant(
  status: OrderPaymentStatus,
): 'success' | 'warning' | 'danger' | 'neutral' {
  switch (status) {
    case 'paid':
      return 'success'
    case 'partially_paid':
      return 'warning'
    case 'refunded':
      return 'danger'
    default:
      return 'neutral'
  }
}
