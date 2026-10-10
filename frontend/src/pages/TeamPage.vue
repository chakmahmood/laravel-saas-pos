<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import AppBadge from '@/components/ui/AppBadge.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppConfirmDialog from '@/components/ui/AppConfirmDialog.vue'
import AppEmptyState from '@/components/ui/AppEmptyState.vue'
import AppErrorState from '@/components/ui/AppErrorState.vue'
import AppInput from '@/components/ui/AppInput.vue'
import AppPagination from '@/components/ui/AppPagination.vue'
import AppSelect from '@/components/ui/AppSelect.vue'
import AppSkeleton from '@/components/ui/AppSkeleton.vue'
import MemberFormModal from '@/features/team/MemberFormModal.vue'
import { teamService } from '@/features/team/api'
import { memberErrorMessage } from '@/features/team/errors'
import type { AssignableRole, StoreMember } from '@/features/team/types'
import { debounce } from '@/lib/debounce'
import { ApiError } from '@/lib/errors'
import { formatDateTime } from '@/lib/format'
import { useCurrentStoreStore } from '@/stores/currentStore'
import { useToastStore } from '@/stores/toast'
import type { PaginationMeta } from '@/types/api'
import type { MembershipStatus, StoreRole } from '@/types/enums'
import { membershipStatusLabel, storeRoleLabel } from '@/types/enums'

const store = useCurrentStoreStore()
const toast = useToastStore()

const rows = ref<StoreMember[]>([])
const meta = ref<PaginationMeta | null>(null)
const loading = ref(false)
const error = ref('')

const searchInput = ref('')
const search = ref('')
const roleFilter = ref<string>('all')
const statusFilter = ref<string>('all')
const page = ref(1)
const perPage = ref(15)

const activeAdminExists = ref(false)

const showForm = ref(false)
const formMode = ref<'admin' | 'cashier'>('cashier')

const confirmTarget = ref<StoreMember | null>(null)
const confirmKind = ref<'status' | 'role'>('status')
const confirmNextActive = ref(false)
const confirmNextRole = ref<AssignableRole | null>(null)
const acting = ref(false)

const canManage = computed(() => store.canManage)
const isOwner = computed(() => store.current?.role === 'owner')
const isAdmin = computed(() => store.current?.role === 'admin')
const canCreateAdmin = computed(() => isOwner.value && !activeAdminExists.value)
const canCreateCashier = computed(() => isOwner.value || isAdmin.value)
const hasFilters = computed(
  () => search.value !== '' || roleFilter.value !== 'all' || statusFilter.value !== 'all',
)

const roleOptions = [
  { value: 'all', label: 'Semua role' },
  { value: 'owner', label: 'Pemilik' },
  { value: 'admin', label: 'Admin' },
  { value: 'cashier', label: 'Kasir' },
]

const statusOptions = [
  { value: 'all', label: 'Semua status' },
  { value: 'active', label: 'Aktif' },
  { value: 'inactive', label: 'Nonaktif' },
]

let loadSeq = 0

async function load(): Promise<void> {
  if (!store.current || !canManage.value) {
    return
  }
  const requestedStoreId = store.current.id
  const requestId = ++loadSeq

  loading.value = true
  error.value = ''
  try {
    const result = await teamService.list({
      page: page.value,
      per_page: perPage.value,
      search: search.value || undefined,
      role: roleFilter.value === 'all' ? undefined : (roleFilter.value as StoreRole),
      status: statusFilter.value === 'all' ? undefined : (statusFilter.value as MembershipStatus),
      sort: 'created_at',
      direction: 'asc',
    })
    if (requestId !== loadSeq || store.current?.id !== requestedStoreId) {
      return
    }
    rows.value = result.data
    meta.value = result.meta
    if (isOwner.value) {
      await refreshAdminSlot(requestId, requestedStoreId)
    }
  } catch (caught) {
    if (requestId !== loadSeq) {
      return
    }
    error.value =
      caught instanceof ApiError ? memberErrorMessage(caught) : 'Gagal memuat daftar anggota.'
    rows.value = []
    meta.value = null
  } finally {
    if (requestId === loadSeq) {
      loading.value = false
    }
  }
}

/** Non-critical: only gates whether the "Tambah Admin" button is offered. */
async function refreshAdminSlot(requestId: number, storeId: number): Promise<void> {
  try {
    const result = await teamService.list({ role: 'admin', status: 'active', per_page: 1 })
    if (requestId !== loadSeq || store.current?.id !== storeId) {
      return
    }
    activeAdminExists.value = result.meta.total > 0
  } catch {
    // Leave the previous value; the server still enforces the limit.
  }
}

const applySearch = debounce((value: string) => {
  search.value = value
}, 350)

watch(searchInput, (value) => applySearch(value))
watch([search, roleFilter, statusFilter], () => {
  page.value = 1
  void load()
})
watch(page, () => void load())

watch(
  () => store.current?.id,
  () => {
    rows.value = []
    meta.value = null
    activeAdminExists.value = false
    page.value = 1
    searchInput.value = ''
    search.value = ''
    roleFilter.value = 'all'
    statusFilter.value = 'all'
    showForm.value = false
    confirmTarget.value = null
    void load()
  },
)

function roleVariant(role: StoreRole): 'brand' | 'info' | 'neutral' {
  if (role === 'owner') {
    return 'brand'
  }
  return role === 'admin' ? 'info' : 'neutral'
}

function canChangeRole(member: StoreMember): boolean {
  return isOwner.value && member.role !== 'owner'
}

function canToggleStatus(member: StoreMember): boolean {
  if (member.role === 'owner') {
    return false
  }
  return isOwner.value || (isAdmin.value && member.role === 'cashier')
}

function openCreate(mode: 'admin' | 'cashier'): void {
  formMode.value = mode
  showForm.value = true
}

function onSaved(member: StoreMember): void {
  showForm.value = false
  toast.success(
    member.role === 'admin' ? 'Akun admin berhasil dibuat.' : 'Akun kasir berhasil dibuat.',
  )
  void load()
}

function askToggleStatus(member: StoreMember): void {
  confirmTarget.value = member
  confirmKind.value = 'status'
  confirmNextActive.value = !member.is_active
}

function askChangeRole(member: StoreMember, role: AssignableRole): void {
  confirmTarget.value = member
  confirmKind.value = 'role'
  confirmNextRole.value = role
}

const confirmTitle = computed(() => {
  if (confirmKind.value === 'role') {
    return 'Ubah role anggota?'
  }
  return confirmNextActive.value ? 'Aktifkan kembali anggota?' : 'Nonaktifkan akses?'
})

const confirmMessage = computed(() => {
  const member = confirmTarget.value
  if (!member) {
    return ''
  }
  const who = member.name ?? member.email ?? 'anggota ini'
  if (confirmKind.value === 'role') {
    const target = confirmNextRole.value === 'admin' ? 'Admin' : 'Kasir'
    return `Ubah role ${who} menjadi ${target}?`
  }
  return confirmNextActive.value
    ? `Aktifkan kembali akses ${who} ke toko ini?`
    : `Nonaktifkan akses ${who}? Anggota tidak akan bisa mengakses toko sampai diaktifkan kembali.`
})

const confirmLabel = computed(() => {
  if (confirmKind.value === 'role') {
    return 'Ubah role'
  }
  return confirmNextActive.value ? 'Aktifkan' : 'Nonaktifkan'
})

const confirmVariant = computed<'danger' | 'primary'>(() =>
  confirmKind.value === 'status' && !confirmNextActive.value ? 'danger' : 'primary',
)

function closeConfirm(): void {
  if (acting.value) {
    return
  }
  confirmTarget.value = null
  confirmNextRole.value = null
}

async function confirmAction(): Promise<void> {
  const member = confirmTarget.value
  if (!member || acting.value) {
    return
  }
  acting.value = true
  try {
    if (confirmKind.value === 'role' && confirmNextRole.value) {
      await teamService.updateRole(member.id, confirmNextRole.value)
      toast.success('Role anggota berhasil diperbarui.')
    } else {
      await teamService.updateStatus(member.id, confirmNextActive.value)
      toast.success(
        confirmNextActive.value ? 'Anggota berhasil diaktifkan.' : 'Anggota berhasil dinonaktifkan.',
      )
    }
    confirmTarget.value = null
    confirmNextRole.value = null
    void load()
  } catch (caught) {
    // Do not mutate the row optimistically; surface the server's decision.
    toast.error(
      caught instanceof ApiError ? memberErrorMessage(caught) : 'Gagal memperbarui anggota.',
    )
  } finally {
    acting.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 class="text-xl font-semibold text-white">Tim</h1>
        <p class="mt-1 text-sm text-slate-400">
          Kelola admin dan kasir toko. Setiap karyawan menggunakan akun sendiri.
        </p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <AppButton v-if="canCreateAdmin" @click="openCreate('admin')">Tambah Admin</AppButton>
        <AppButton
          v-if="canCreateCashier"
          :variant="canCreateAdmin ? 'secondary' : 'primary'"
          @click="openCreate('cashier')"
        >
          Tambah Kasir
        </AppButton>
      </div>
    </header>

    <div v-if="!canManage" class="rounded-xl border border-hairline bg-panel">
      <AppEmptyState
        icon="users"
        title="Akses ditolak"
        description="Hanya pemilik atau admin toko yang dapat mengelola anggota."
      />
    </div>

    <template v-else>
      <div class="rounded-xl border border-hairline bg-panel shadow-panel">
        <div class="flex flex-col gap-3 border-b border-hairline p-4 sm:flex-row sm:items-center">
          <div class="sm:max-w-xs sm:flex-1">
            <AppInput v-model="searchInput" name="member-search" placeholder="Cari nama atau email…" />
          </div>
          <div class="sm:w-40">
            <AppSelect v-model="roleFilter" name="member-role" :options="roleOptions" />
          </div>
          <div class="sm:w-40">
            <AppSelect v-model="statusFilter" name="member-status" :options="statusOptions" />
          </div>
        </div>

        <div v-if="loading" class="space-y-3 p-4">
          <div v-for="n in 4" :key="n" class="rounded-lg border border-hairline bg-panel-2 px-4 py-3">
            <AppSkeleton :lines="2" />
          </div>
        </div>

        <div v-else-if="error" class="p-4">
          <AppErrorState :message="error" @retry="load" />
        </div>

        <AppEmptyState
          v-else-if="rows.length === 0"
          icon="users"
          :title="hasFilters ? 'Tidak ada anggota yang cocok' : 'Belum Ada Anggota'"
          :description="
            hasFilters
              ? 'Coba ubah kata kunci atau filter.'
              : 'Tambahkan admin atau kasir untuk membantu operasional toko.'
          "
        />

        <div v-else class="overflow-x-auto">
          <table class="w-full min-w-[720px] border-collapse text-sm">
            <thead>
              <tr
                class="border-b border-hairline text-left text-xs uppercase tracking-wide text-slate-500"
              >
                <th class="px-4 py-3 font-medium">Nama</th>
                <th class="px-4 py-3 font-medium">Email</th>
                <th class="px-4 py-3 font-medium">Role</th>
                <th class="px-4 py-3 font-medium">Status</th>
                <th class="px-4 py-3 font-medium">Bergabung</th>
                <th class="px-4 py-3 text-right font-medium">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="row in rows"
                :key="row.id"
                class="border-b border-hairline/60 last:border-0 hover:bg-white/[0.02]"
              >
                <td class="px-4 py-3">
                  <p class="font-medium text-slate-100">{{ row.name ?? '—' }}</p>
                  <p
                    v-if="row.must_change_password"
                    class="mt-0.5 text-xs text-amber-300/80"
                  >
                    Wajib ganti sandi awal
                  </p>
                </td>
                <td class="max-w-[18rem] truncate px-4 py-3 text-slate-400">{{ row.email ?? '—' }}</td>
                <td class="px-4 py-3">
                  <AppBadge :variant="roleVariant(row.role)">{{ storeRoleLabel(row.role) }}</AppBadge>
                </td>
                <td class="px-4 py-3">
                  <AppBadge :variant="row.is_active ? 'success' : 'warning'" dot>
                    {{ membershipStatusLabel(row.status) }}
                  </AppBadge>
                </td>
                <td class="px-4 py-3 text-slate-400">{{ formatDateTime(row.joined_at) }}</td>
                <td class="px-4 py-3 text-right">
                  <div class="inline-flex items-center gap-1">
                    <button
                      v-if="canChangeRole(row)"
                      type="button"
                      class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-slate-300 transition-colors hover:bg-white/5 hover:text-white focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-40"
                      :disabled="row.role === 'cashier' && activeAdminExists"
                      @click="askChangeRole(row, row.role === 'admin' ? 'cashier' : 'admin')"
                    >
                      {{ row.role === 'admin' ? 'Jadikan Kasir' : 'Jadikan Admin' }}
                    </button>
                    <button
                      v-if="canToggleStatus(row)"
                      type="button"
                      class="rounded-lg px-2.5 py-1.5 text-xs font-medium transition-colors focus-visible:outline-none"
                      :class="
                        row.is_active
                          ? 'text-rose-300 hover:bg-rose-500/10'
                          : 'text-emerald-300 hover:bg-emerald-500/10'
                      "
                      @click="askToggleStatus(row)"
                    >
                      {{ row.is_active ? 'Nonaktifkan' : 'Aktifkan kembali' }}
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <AppPagination v-if="!loading && !error" :meta="meta" @change="(p) => (page = p)" />
      </div>
    </template>

    <MemberFormModal
      :open="showForm"
      :mode="formMode"
      @close="showForm = false"
      @saved="onSaved"
    />

    <AppConfirmDialog
      :open="confirmTarget !== null"
      :title="confirmTitle"
      :message="confirmMessage"
      :confirm-label="confirmLabel"
      :variant="confirmVariant"
      :loading="acting"
      @close="closeConfirm"
      @confirm="confirmAction"
    />
  </div>
</template>
