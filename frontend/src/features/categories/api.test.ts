import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { AxiosResponse } from 'axios'
import { http } from '@/lib/http'
import { categoriesService } from './api'
import type { Category, CategoryPayload } from './types'

vi.mock('@/lib/http', () => ({
  http: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))

function axiosResponse<T>(data: T): AxiosResponse<T> {
  return { data } as unknown as AxiosResponse<T>
}

function page(data: Category[], lastPage = 1) {
  return {
    data,
    links: { first: null, last: null, prev: null, next: null },
    meta: {
      current_page: 1,
      from: 1,
      last_page: lastPage,
      links: [],
      path: '/api/categories',
      per_page: 100,
      to: data.length,
      total: data.length,
    },
  }
}

const payload: CategoryPayload = { name: 'Minuman', description: null, is_active: true }

describe('categoriesService', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('lists categories via GET /categories', async () => {
    vi.mocked(http.get).mockResolvedValue(axiosResponse(page([])))

    await categoriesService.list({ page: 2, search: 'mi' })

    expect(http.get).toHaveBeenCalledWith('/categories', { params: { page: 2, search: 'mi' } })
  })

  it('follows pagination in listAll', async () => {
    vi.mocked(http.get)
      .mockResolvedValueOnce(axiosResponse(page([{ id: 1 } as Category], 2)))
      .mockResolvedValueOnce(axiosResponse(page([{ id: 2 } as Category], 2)))

    const all = await categoriesService.listAll()

    expect(all.map((c) => c.id)).toEqual([1, 2])
    expect(http.get).toHaveBeenCalledTimes(2)
  })

  it('creates via POST /categories', async () => {
    vi.mocked(http.post).mockResolvedValue(
      axiosResponse({ message: 'ok', data: { id: 3 } as Category }),
    )

    await categoriesService.create(payload)

    expect(http.post).toHaveBeenCalledWith('/categories', payload)
  })

  it('updates via PUT /categories/{id}', async () => {
    vi.mocked(http.put).mockResolvedValue(
      axiosResponse({ message: 'ok', data: { id: 3 } as Category }),
    )

    await categoriesService.update(3, payload)

    expect(http.put).toHaveBeenCalledWith('/categories/3', payload)
  })

  it('removes via DELETE /categories/{id}', async () => {
    vi.mocked(http.delete).mockResolvedValue(axiosResponse({ message: 'ok' }))

    await categoriesService.remove(3)

    expect(http.delete).toHaveBeenCalledWith('/categories/3')
  })
})
