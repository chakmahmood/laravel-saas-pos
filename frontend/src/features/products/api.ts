import { http } from '@/lib/http'
import { cleanParams } from '@/lib/query'
import type { DataEnvelope, MessageEnvelope, Paginated } from '@/types/api'
import type { Product, ProductListParams, ProductPayload } from './types'

/**
 * Product (catalog "item") API calls, verified against
 * `App\Http\Controllers\Api\ItemController`, `docs/api/openapi.yaml`, and the
 * backend feature tests.
 */
async function list(params: ProductListParams = {}): Promise<Paginated<Product>> {
  const response = await http.get<Paginated<Product>>('/items', {
    params: cleanParams(params),
  })
  return response.data
}

async function show(id: number): Promise<Product> {
  const response = await http.get<DataEnvelope<Product>>(`/items/${id}`)
  return response.data.data
}

async function create(payload: ProductPayload): Promise<Product> {
  const response = await http.post<MessageEnvelope<Product>>('/items', payload)
  return response.data.data
}

async function update(id: number, payload: ProductPayload): Promise<Product> {
  const response = await http.put<MessageEnvelope<Product>>(`/items/${id}`, payload)
  return response.data.data
}

async function remove(id: number): Promise<void> {
  await http.delete(`/items/${id}`)
}

export const productsService = { list, show, create, update, remove }
