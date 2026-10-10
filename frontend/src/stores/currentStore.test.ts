import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { ApiError } from '@/lib/errors'
import { sessionService } from '@/services/session'
import { useCurrentStoreStore } from './currentStore'

vi.mock('@/services/session', () => ({
  sessionService: {
    me: vi.fn(),
    getCurrentStore: vi.fn(),
    selectStore: vi.fn(),
    login: vi.fn(),
    register: vi.fn(),
    logout: vi.fn(),
  },
}))

function freshStore() {
  setActivePinia(createPinia())
  return useCurrentStoreStore()
}

const retailStore = {
  id: 1,
  name: 'Toko Retail',
  slug: 'toko-retail',
  role: 'owner' as const,
  business_type: 'retail' as const,
  is_active: true,
}

describe('currentStore store', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('loads accessible stores and the active store', async () => {
    vi.mocked(sessionService.me).mockResolvedValue({
      user: { id: 1, name: 'A', email: 'a@b.c' },
      stores: [retailStore],
    })
    vi.mocked(sessionService.getCurrentStore).mockResolvedValue(retailStore)

    const store = freshStore()
    await store.refresh()

    expect(store.stores).toHaveLength(1)
    expect(store.current?.id).toBe(1)
    expect(store.hasStore).toBe(true)
    expect(store.inventoryEnabled).toBe(true)
    expect(store.canManage).toBe(true)
  })

  it('treats current_store_unavailable as no active store (no throw)', async () => {
    vi.mocked(sessionService.me).mockResolvedValue({
      user: { id: 1, name: 'A', email: 'a@b.c' },
      stores: [],
    })
    vi.mocked(sessionService.getCurrentStore).mockRejectedValue(
      new ApiError({ message: 'unavailable', status: 409, code: 'current_store_unavailable' }),
    )

    const store = freshStore()
    await store.refresh()

    expect(store.current).toBeNull()
    expect(store.hasStore).toBe(false)
    expect(store.loaded).toBe(true)
  })

  it('selects a store and updates capability flags', async () => {
    vi.mocked(sessionService.selectStore).mockResolvedValue({
      id: 2,
      name: 'Toko Laundry',
      slug: 'toko-laundry',
      role: 'admin',
      business_type: 'laundry',
      is_active: true,
    })

    const store = freshStore()
    await store.select(2)

    expect(store.current?.id).toBe(2)
    expect(store.inventoryEnabled).toBe(false)
  })
})
