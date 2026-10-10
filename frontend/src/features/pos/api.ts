import { http } from '@/lib/http'
import { cleanParams } from '@/lib/query'
import type { MessageEnvelope, Paginated } from '@/types/api'
import type {
  Order,
  OrderCreatePayload,
  OrderListParams,
  OrderPayment,
  PaymentCreatePayload,
} from './types'

/**
 * Order API calls, verified against `App\Http\Controllers\Api\OrderController`,
 * `App\Http\Requests\Order\StoreOrderRequest` and `docs/order-api.md`.
 *
 * The store is resolved from the token by the backend; no store id is sent.
 */
async function create(payload: OrderCreatePayload): Promise<Order> {
  const response = await http.post<MessageEnvelope<Order>>('/orders', payload)
  return response.data.data
}

/** Server-side paginated list with filters (transaction history). */
async function list(params: OrderListParams = {}): Promise<Paginated<Order>> {
  const response = await http.get<Paginated<Order>>('/orders', { params: cleanParams(params) })
  return response.data
}

async function show(id: number): Promise<Order> {
  const response = await http.get<{ data: Order }>(`/orders/${id}`)
  return response.data.data
}

/**
 * Read-only reconciliation by the client idempotency key. Returns the order when
 * it exists in the active store; a `404` (`order_not_found`) means no order was
 * stored under that key (so a retry is safe). Never creates or mutates data.
 */
async function reconcile(idempotencyKey: string): Promise<Order> {
  const response = await http.get<{ data: Order }>('/orders/reconcile', {
    params: { idempotency_key: idempotencyKey },
  })
  return response.data.data
}

export const ordersService = { create, list, show, reconcile }

/**
 * Payment API calls, verified against
 * `App\Http\Controllers\Api\PaymentController` and
 * `App\Http\Requests\Payment\StorePaymentRequest`.
 *
 * Cash payments require the recorder to have an open shift; the backend
 * rejects them with `409 cash_session_required`. Non-cash methods do not need a
 * shift. The POS surfaces that server decision instead of guessing.
 */
async function record(orderId: number, payload: PaymentCreatePayload): Promise<OrderPayment> {
  const response = await http.post<MessageEnvelope<OrderPayment>>(
    `/orders/${orderId}/payments`,
    payload,
  )
  return response.data.data
}

export const paymentsService = { record }
