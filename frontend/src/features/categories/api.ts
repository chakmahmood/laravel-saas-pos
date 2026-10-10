import { http } from '@/lib/http'
import { cleanParams } from '@/lib/query'
import type { MessageEnvelope, Paginated } from '@/types/api'
import type { Category, CategoryListParams, CategoryPayload } from './types'

/**
 * Category API calls, verified against `App\Http\Controllers\Api\CategoryController`
 * and `docs/api/openapi.yaml`.
 */
async function list(params: CategoryListParams = {}): Promise<Paginated<Category>> {
  const response = await http.get<Paginated<Category>>('/categories', {
    params: cleanParams(params),
  })
  return response.data
}

/**
 * Fetch every category of the current store by following pagination. Bounded to
 * avoid an unbounded loop on pathological data.
 */
async function listAll(maxPages = 20): Promise<Category[]> {
  const all: Category[] = []

  for (let page = 1; page <= maxPages; page += 1) {
    const result = await list({ page, per_page: 100, sort: 'name', direction: 'asc' })
    all.push(...result.data)

    if (page >= result.meta.last_page) {
      break
    }
  }

  return all
}

async function create(payload: CategoryPayload): Promise<Category> {
  const response = await http.post<MessageEnvelope<Category>>('/categories', payload)
  return response.data.data
}

async function update(id: number, payload: CategoryPayload): Promise<Category> {
  const response = await http.put<MessageEnvelope<Category>>(`/categories/${id}`, payload)
  return response.data.data
}

async function remove(id: number): Promise<void> {
  await http.delete(`/categories/${id}`)
}

export const categoriesService = { list, listAll, create, update, remove }
