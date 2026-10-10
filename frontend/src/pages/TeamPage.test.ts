import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import AppConfirmDialog from '@/components/ui/AppConfirmDialog.vue'
import AppErrorState from '@/components/ui/AppErrorState.vue'
import { teamService } from '@/features/team/api'
import type { MemberListParams, StoreMember } from '@/features/team/types'
import { ApiError } from '@/lib/errors'
import { useCurrentStoreStore } from '@/stores/currentStore'
import { useToastStore } from '@/stores/toast'
import type { Paginated } from '@/types/api'
import type { StoreRole } from '@/types/enums'
import TeamPage from './TeamPage.vue'

vi.mock('@/features/team/api', () => ({
  teamService: {
    list: vi.fn(),
    createAdmin: vi.fn(),
    createCashier: vi.fn(),
    updateRole: vi.fn(),
    updateStatus: vi.fn(),
  },
}))

const retailStore = {
  id: 1,
  name: 'Toko Contoh',
  slug: 'toko-contoh',
  role: 'owner' as const,
  business_type: 'retail' as const,
  is_active: true,
}

function member(overrides: Partial<StoreMember> = {}): StoreMember {
  return {
    id: 10,
    user_id: 100,
    name: 'Anggota',
    email: 'anggota@example.com',
    role: 'cashier',
    status: 'active',
    is_active: true,
    must_change_password: false,
    joined_at: null,
    updated_at: null,
    ...overrides,
  }
}

function paginated(data: StoreMember[], total = data.length): Paginated<StoreMember> {
  return {
    data,
    links: { first: null, last: null, prev: null, next: null },
    meta: {
      current_page: 1,
      from: data.length > 0 ? 1 : 0,
      last_page: 1,
      links: [],
      path: '/api/current-store/members',
      per_page: 15,
      to: data.length,
      total,
    },
  }
}

let listResponse: (params: MemberListParams) => Paginated<StoreMember> = () => paginated([])

function setMembers(list: StoreMember[], options: { hasAdmin?: boolean } = {}): void {
  listResponse = (params) => {
    if (params.role === 'admin' && params.status === 'active') {
      const admin = options.hasAdmin ? [member({ role: 'admin' })] : []
      return paginated(admin, admin.length)
    }
    return paginated(list)
  }
}

function mountPage(role: StoreRole = 'owner') {
  const pinia = createPinia()
  setActivePinia(pinia)
  const store = useCurrentStoreStore()
  store.current = { ...retailStore, role }
  const toast = useToastStore()
  const wrapper = mount(TeamPage, { global: { plugins: [pinia], stubs: { teleport: true } } })
  return { wrapper, toast, store }
}

function findButton(wrapper: ReturnType<typeof mount>, text: string) {
  return wrapper.findAll('button').find((button) => button.text().includes(text))
}

async function fillMemberForm(wrapper: ReturnType<typeof mount>) {
  await wrapper.find('input[name="name"]').setValue('Admin Baru')
  await wrapper.find('input[name="email"]').setValue('baru@example.com')
  await wrapper.find('input[name="password"]').setValue('password123')
  await wrapper.find('input[name="password_confirmation"]').setValue('password123')
  await wrapper.find('#member-form').trigger('submit')
}

describe('TeamPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    setMembers([])
    vi.mocked(teamService.list).mockImplementation((params = {}) =>
      Promise.resolve(listResponse(params)),
    )
  })

  it('renders members with role and status badges', async () => {
    setMembers(
      [
        member({ id: 1, name: 'Andi', role: 'admin', status: 'active' }),
        member({ id: 2, name: 'Budi', role: 'cashier', status: 'inactive', is_active: false }),
      ],
      { hasAdmin: true },
    )

    const { wrapper } = mountPage('owner')
    await flushPromises()

    expect(wrapper.text()).toContain('Andi')
    expect(wrapper.text()).toContain('Budi')
    expect(wrapper.text()).toContain('Admin')
    expect(wrapper.text()).toContain('Nonaktif')
  })

  it('shows an empty state when there are no members', async () => {
    setMembers([])
    const { wrapper } = mountPage('owner')
    await flushPromises()

    expect(wrapper.text()).toContain('Belum Ada Anggota')
  })

  it('shows an error state and retries', async () => {
    vi.mocked(teamService.list).mockRejectedValueOnce(
      new ApiError({ message: 'boom', status: 500 }),
    )
    setMembers([member({ id: 3, name: 'Citra' })])

    const { wrapper } = mountPage('owner')
    await flushPromises()
    expect(wrapper.findComponent(AppErrorState).exists()).toBe(true)

    await findButton(wrapper, 'Coba lagi')!.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Citra')
  })

  it('offers admin and cashier creation to the owner when no admin exists', async () => {
    setMembers([member()], { hasAdmin: false })
    const { wrapper } = mountPage('owner')
    await flushPromises()

    expect(findButton(wrapper, 'Tambah Admin')).toBeTruthy()
    expect(findButton(wrapper, 'Tambah Kasir')).toBeTruthy()
  })

  it('does not offer a second admin once an active admin exists', async () => {
    setMembers([member({ role: 'admin' })], { hasAdmin: true })
    const { wrapper } = mountPage('owner')
    await flushPromises()

    expect(findButton(wrapper, 'Tambah Admin')).toBeUndefined()
    expect(findButton(wrapper, 'Tambah Kasir')).toBeTruthy()
  })

  it('offers cashier but not admin creation to an admin', async () => {
    setMembers([member()], { hasAdmin: true })
    const { wrapper } = mountPage('admin')
    await flushPromises()

    expect(findButton(wrapper, 'Tambah Admin')).toBeUndefined()
    expect(findButton(wrapper, 'Tambah Kasir')).toBeTruthy()
  })

  it('denies member management to a cashier', async () => {
    const { wrapper } = mountPage('cashier')
    await flushPromises()

    expect(wrapper.text()).toContain('Akses ditolak')
    expect(teamService.list).not.toHaveBeenCalled()
  })

  it('creates an admin through the modal', async () => {
    setMembers([], { hasAdmin: false })
    vi.mocked(teamService.createAdmin).mockResolvedValue(member({ role: 'admin' }))
    const { wrapper, toast } = mountPage('owner')
    await flushPromises()

    await findButton(wrapper, 'Tambah Admin')!.trigger('click')
    await flushPromises()
    await fillMemberForm(wrapper)
    await flushPromises()

    expect(teamService.createAdmin).toHaveBeenCalledWith({
      name: 'Admin Baru',
      email: 'baru@example.com',
      password: 'password123',
      password_confirmation: 'password123',
    })
    expect(toast.toasts.some((item) => item.kind === 'success')).toBe(true)
  })

  it('shows a clear message when the admin limit is reached', async () => {
    setMembers([], { hasAdmin: false })
    vi.mocked(teamService.createAdmin).mockRejectedValue(
      new ApiError({ message: 'conflict', status: 409, code: 'admin_limit_reached' }),
    )
    const { wrapper } = mountPage('owner')
    await flushPromises()

    await findButton(wrapper, 'Tambah Admin')!.trigger('click')
    await flushPromises()
    await fillMemberForm(wrapper)
    await flushPromises()

    expect(wrapper.text()).toContain('sudah memiliki admin')
  })

  it('handles a duplicate email when creating a cashier', async () => {
    setMembers([], { hasAdmin: true })
    vi.mocked(teamService.createCashier).mockRejectedValue(
      new ApiError({ message: 'conflict', status: 409, code: 'email_already_registered' }),
    )
    const { wrapper } = mountPage('owner')
    await flushPromises()

    await findButton(wrapper, 'Tambah Kasir')!.trigger('click')
    await flushPromises()
    await fillMemberForm(wrapper)
    await flushPromises()

    expect(wrapper.text()).toContain('sudah terdaftar')
  })

  it('shows 422 validation errors on the form', async () => {
    setMembers([], { hasAdmin: true })
    vi.mocked(teamService.createCashier).mockRejectedValue(
      new ApiError({
        message: 'invalid',
        status: 422,
        code: 'validation_error',
        errors: { email: ['Email sudah dipakai.'] },
      }),
    )
    const { wrapper } = mountPage('owner')
    await flushPromises()

    await findButton(wrapper, 'Tambah Kasir')!.trigger('click')
    await flushPromises()
    await fillMemberForm(wrapper)
    await flushPromises()

    expect(wrapper.text()).toContain('Email sudah dipakai.')
  })

  it('requires confirmation before deactivating and keeps the row on server failure', async () => {
    setMembers([member({ id: 5, name: 'Budi', role: 'cashier', is_active: true })], {
      hasAdmin: false,
    })
    vi.mocked(teamService.updateStatus).mockRejectedValue(
      new ApiError({ message: 'conflict', status: 409, code: 'owner_protected' }),
    )
    const { wrapper, toast } = mountPage('owner')
    await flushPromises()

    await findButton(wrapper, 'Nonaktifkan')!.trigger('click')
    await flushPromises()
    expect(wrapper.findComponent(AppConfirmDialog).props('open')).toBe(true)
    expect(wrapper.text()).toContain('Aktif')

    const callsBefore = vi.mocked(teamService.list).mock.calls.length
    wrapper.findComponent(AppConfirmDialog).vm.$emit('confirm')
    await flushPromises()

    expect(teamService.updateStatus).toHaveBeenCalledWith(5, false)
    expect(toast.toasts.some((item) => item.kind === 'error')).toBe(true)
    expect(vi.mocked(teamService.list).mock.calls.length).toBe(callsBefore)
    expect(wrapper.text()).toContain('Aktif')
  })

  it('changes a role after confirmation', async () => {
    setMembers([member({ id: 5, name: 'Andi', role: 'admin' })], { hasAdmin: true })
    vi.mocked(teamService.updateRole).mockResolvedValue(member({ role: 'cashier' }))
    const { wrapper } = mountPage('owner')
    await flushPromises()

    await findButton(wrapper, 'Jadikan Kasir')!.trigger('click')
    await flushPromises()
    wrapper.findComponent(AppConfirmDialog).vm.$emit('confirm')
    await flushPromises()

    expect(teamService.updateRole).toHaveBeenCalledWith(5, 'cashier')
  })

  it('lets an admin toggle cashiers but not admins or the owner', async () => {
    setMembers(
      [
        member({ id: 1, name: 'Admin', role: 'admin' }),
        member({ id: 2, name: 'Kasir', role: 'cashier' }),
        member({ id: 3, name: 'Pemilik', role: 'owner' }),
      ],
      { hasAdmin: true },
    )
    const { wrapper } = mountPage('admin')
    await flushPromises()

    expect(findButton(wrapper, 'Jadikan Kasir')).toBeUndefined()
    const toggleButtons = wrapper
      .findAll('button')
      .filter((button) => button.text().includes('Nonaktifkan'))
    expect(toggleButtons).toHaveLength(1)
  })

  it('clears old members and reloads when the current store changes', async () => {
    setMembers([member({ id: 1, name: 'StoreLama' })], { hasAdmin: false })
    const { wrapper, store } = mountPage('owner')
    await flushPromises()
    expect(wrapper.text()).toContain('StoreLama')

    setMembers([member({ id: 2, name: 'StoreBaru' })], { hasAdmin: false })
    store.current = { ...store.current!, id: 99, name: 'Toko Baru' }
    await flushPromises()

    expect(wrapper.text()).toContain('StoreBaru')
    expect(wrapper.text()).not.toContain('StoreLama')
  })

  it('ignores a stale response after the store changes', async () => {
    let resolveFirst: (value: Paginated<StoreMember>) => void = () => {}
    const first = new Promise<Paginated<StoreMember>>((resolve) => {
      resolveFirst = resolve
    })
    let memberCalls = 0
    vi.mocked(teamService.list).mockImplementation((params = {}) => {
      if (params.role === 'admin' && params.status === 'active') {
        return Promise.resolve(paginated([], 0))
      }
      memberCalls += 1
      if (memberCalls === 1) {
        return first
      }
      return Promise.resolve(paginated([member({ id: 2, name: 'StoreBaru' })]))
    })

    const { wrapper, store } = mountPage('owner')
    await flushPromises()

    store.current = { ...store.current!, id: 99, name: 'Toko Baru' }
    await flushPromises()
    expect(wrapper.text()).toContain('StoreBaru')

    resolveFirst(paginated([member({ id: 1, name: 'StoreLama' })]))
    await flushPromises()

    expect(wrapper.text()).not.toContain('StoreLama')
  })
})
