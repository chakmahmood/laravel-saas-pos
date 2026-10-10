import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { AxiosResponse } from 'axios'
import { http } from '@/lib/http'
import { productsService } from './api'
import type { Product, ProductPayload } from './types'

vi.mock('@/lib/http', () => ({
  http: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))

function axiosResponse<T>(data: T): AxiosResponse<T> {
  return { data } as unknown as AxiosResponse<T>
}

const payload: ProductPayload = {
  name: 'Kopi',
  type: 'menu',
  category_id: null,
  sku: null,
  barcode: null,
  description: null,
  cost_price: null,
  selling_price: 18000,
  unit: 'porsi',
  is_active: true,
  tracks_stock: false,
}

describe('productsService', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('lists products and cleans empty params', async () => {
    vi.mocked(http.get).mockResolvedValue(axiosResponse({ data: [], meta: {}, links: {} }))

    await productsService.list({ page: 1, search: '', type: undefined, is_active: false })

    expect(http.get).toHaveBeenCalledWith('/items', {
      params: { page: 1, is_active: false },
    })
  })

  it('creates via POST /items', async () => {
    vi.mocked(http.post).mockResolvedValue(
      axiosResponse({ message: 'ok', data: { id: 9 } as Product }),
    )

    const created = await productsService.create(payload)

    expect(http.post).toHaveBeenCalledWith('/items', payload)
    expect(created).toEqual({ id: 9 })
  })

  it('updates via PUT /items/{id}', async () => {
    vi.mocked(http.put).mockResolvedValue(
      axiosResponse({ message: 'ok', data: { id: 9 } as Product }),
    )

    await productsService.update(9, payload)

    expect(http.put).toHaveBeenCalledWith('/items/9', payload)
  })

  it('removes via DELETE /items/{id}', async () => {
    vi.mocked(http.delete).mockResolvedValue(axiosResponse({ message: 'ok' }))

    await productsService.remove(9)

    expect(http.delete).toHaveBeenCalledWith('/items/9')
  })
})
