import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { AxiosResponse } from 'axios'
import { http } from '@/lib/http'
import { teamService } from './api'
import type { MemberCreatePayload, StoreMember } from './types'

vi.mock('@/lib/http', () => ({
  http: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

function axiosResponse<T>(data: T): AxiosResponse<T> {
  return { data } as unknown as AxiosResponse<T>
}

const member = { id: 1, user_id: 9, role: 'cashier', status: 'active' } as StoreMember

const payload: MemberCreatePayload = {
  name: 'Budi',
  email: 'budi@example.com',
  password: 'password123',
  password_confirmation: 'password123',
}

describe('teamService', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('lists members with cleaned params', async () => {
    vi.mocked(http.get).mockResolvedValue(
      axiosResponse({ data: [], links: {}, meta: { total: 0 } }),
    )

    await teamService.list({ page: 1, search: 'budi', role: undefined })

    expect(http.get).toHaveBeenCalledWith('/current-store/members', {
      params: { page: 1, search: 'budi' },
    })
  })

  it('creates an admin via POST /current-store/members/admin', async () => {
    vi.mocked(http.post).mockResolvedValue(axiosResponse({ message: 'ok', data: member }))

    await teamService.createAdmin(payload)

    expect(http.post).toHaveBeenCalledWith('/current-store/members/admin', payload)
  })

  it('creates a cashier via POST /current-store/members/cashiers', async () => {
    vi.mocked(http.post).mockResolvedValue(axiosResponse({ message: 'ok', data: member }))

    await teamService.createCashier(payload)

    expect(http.post).toHaveBeenCalledWith('/current-store/members/cashiers', payload)
  })

  it('updates a role via PATCH /current-store/members/{id}/role', async () => {
    vi.mocked(http.patch).mockResolvedValue(axiosResponse({ message: 'ok', data: member }))

    await teamService.updateRole(7, 'admin')

    expect(http.patch).toHaveBeenCalledWith('/current-store/members/7/role', { role: 'admin' })
  })

  it('updates a status via PATCH /current-store/members/{id}/status', async () => {
    vi.mocked(http.patch).mockResolvedValue(axiosResponse({ message: 'ok', data: member }))

    await teamService.updateStatus(7, false)

    expect(http.patch).toHaveBeenCalledWith('/current-store/members/7/status', { is_active: false })
  })
})
