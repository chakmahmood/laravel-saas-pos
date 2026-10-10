<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import AppBadge from '@/components/ui/AppBadge.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppConfirmDialog from '@/components/ui/AppConfirmDialog.vue'
import AppEmptyState from '@/components/ui/AppEmptyState.vue'
import AppErrorState from '@/components/ui/AppErrorState.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import AppInput from '@/components/ui/AppInput.vue'
import AppPagination from '@/components/ui/AppPagination.vue'
import AppSelect from '@/components/ui/AppSelect.vue'
import AppSkeleton from '@/components/ui/AppSkeleton.vue'
import CategoryFormModal from '@/features/categories/CategoryFormModal.vue'
import { categoriesService } from '@/features/categories/api'
import type { Category } from '@/features/categories/types'
import { debounce } from '@/lib/debounce'
import { ApiError, humanMessage } from '@/lib/errors'
import { formatDateTime } from '@/lib/format'
import { useCurrentStoreStore } from '@/stores/currentStore'
import { useToastStore } from '@/stores/toast'
import type { PaginationMeta } from '@/types/api'

const store = useCurrentStoreStore()
const toast = useToastStore()

const rows = ref<Category[]>([])
const meta = ref<PaginationMeta | null>(null)
const loading = ref(false)
const error = ref('')

const searchInput = ref('')
const search = ref('')
const activeFilter = ref<'all' | 'true' | 'false'>('all')
const sort = ref<'name' | 'created_at'>('name')
const direction = ref<'asc' | 'desc'>('asc')
const page = ref(1)
const perPage = ref(15)

const showForm = ref(false)
const editing = ref<Category | null>(null)
const confirmTarget = ref<Category | null>(null)
const deleting = ref(false)

const canManage = computed(() => store.canManage)
const hasFilters = computed(() => search.value !== '' || activeFilter.value !== 'all')

const activeOptions = [
  { value: 'all', label: 'Semua status' },
  { value: 'true', label: 'Aktif' },
  { value: 'false', label: 'Nonaktif' },
]

let loadSeq = 0

async function load(): Promise<void> {
  if (!store.current) {
    return
  }
  const requestedStoreId = store.current.id
  const requestId = ++loadSeq

  loading.value = true
  error.value = ''
  try {
    const result = await categoriesService.list({
      page: page.value,
      per_page: perPage.value,
      search: search.value || undefined,
      is_active: activeFilter.value === 'all' ? undefined : activeFilter.value === 'true',
      sort: sort.value,
      direction: direction.value,
    })
    if (requestId !== loadSeq || store.current?.id !== requestedStoreId) {
      return
    }
    rows.value = result.data
    meta.value = result.meta
  } catch (caught) {
    if (requestId !== loadSeq) {
      return
    }
    error.value = caught instanceof ApiError ? humanMessage(caught) : 'Gagal memuat data kategori.'
    rows.value = []
    meta.value = null
  } finally {
    if (requestId === loadSeq) {
      loading.value = false
    }
  }
}

const applySearch = debounce((value: string) => {
  search.value = value
}, 350)

watch(searchInput, (value) => applySearch(value))
watch([search, activeFilter, sort, direction], () => {
  page.value = 1
  void load()
})
watch(page, () => void load())

watch(
  () => store.current?.id,
  () => {
    rows.value = []
    meta.value = null
    page.value = 1
    searchInput.value = ''
    search.value = ''
    activeFilter.value = 'all'
    void load()
  },
)

function openCreate(): void {
  editing.value = null
  showForm.value = true
}

function openEdit(category: Category): void {
  editing.value = category
  showForm.value = true
}

function onSaved(): void {
  const wasEdit = editing.value !== null
  showForm.value = false
  editing.value = null
  toast.success(wasEdit ? 'Kategori berhasil diperbarui.' : 'Kategori berhasil dibuat.')
  void load()
}

function askDelete(category: Category): void {
  confirmTarget.value = category
}

async function confirmDelete(): Promise<void> {
  if (confirmTarget.value === null || deleting.value) {
    return
  }
  const target = confirmTarget.value
  deleting.value = true
  try {
    await categoriesService.remove(target.id)
    confirmTarget.value = null
    toast.success('Kategori berhasil dihapus.')
    void load()
  } catch (caught) {
    confirmTarget.value = null
    toast.error(
      caught instanceof ApiError ? humanMessage(caught) : 'Gagal menghapus kategori.',
    )
  } finally {
    deleting.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 class="text-xl font-semibold text-white">Kategori</h1>
        <p class="mt-1 text-sm text-slate-400">
          Kelompokkan produk untuk memudahkan pencarian di kasir.
        </p>
      </div>
      <AppButton v-if="canManage" @click="openCreate">
        <AppIcon name="plus" :size="18" />
        Tambah Kategori
      </AppButton>
    </header>

    <div class="rounded-xl border border-hairline bg-panel shadow-panel">
      <div class="flex flex-col gap-3 border-b border-hairline p-4 sm:flex-row sm:items-center">
        <div class="sm:max-w-xs sm:flex-1">
          <AppInput v-model="searchInput" name="category-search" placeholder="Cari nama kategori…" />
        </div>
        <div class="sm:w-44">
          <AppSelect v-model="activeFilter" name="category-status" :options="activeOptions" />
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
        icon="tag"
        :title="hasFilters ? 'Tidak ada kategori yang cocok' : 'Belum Ada Kategori'"
        :description="
          hasFilters
            ? 'Coba ubah kata kunci atau filter status.'
            : 'Tambahkan kategori pertama untuk mengelompokkan produk Anda.'
        "
      >
        <template #actions>
          <AppButton v-if="canManage && !hasFilters" size="sm" @click="openCreate">
            Tambah Kategori
          </AppButton>
        </template>
      </AppEmptyState>

      <div v-else class="overflow-x-auto">
        <table class="w-full min-w-[640px] border-collapse text-sm">
          <thead>
            <tr class="border-b border-hairline text-left text-xs uppercase tracking-wide text-slate-500">
              <th class="px-4 py-3 font-medium">Nama</th>
              <th class="px-4 py-3 font-medium">Deskripsi</th>
              <th class="px-4 py-3 font-medium">Status</th>
              <th class="px-4 py-3 font-medium">Dibuat</th>
              <th v-if="canManage" class="px-4 py-3 text-right font-medium">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in rows"
              :key="row.id"
              class="border-b border-hairline/60 last:border-0 hover:bg-white/[0.02]"
            >
              <td class="max-w-[16rem] truncate px-4 py-3 font-medium text-slate-100">
                {{ row.name }}
              </td>
              <td class="max-w-[24rem] truncate px-4 py-3 text-slate-400">
                {{ row.description || '—' }}
              </td>
              <td class="px-4 py-3">
                <AppBadge :variant="row.is_active ? 'success' : 'warning'" dot>
                  {{ row.is_active ? 'Aktif' : 'Nonaktif' }}
                </AppBadge>
              </td>
              <td class="px-4 py-3 text-slate-400">{{ formatDateTime(row.created_at) }}</td>
              <td v-if="canManage" class="px-4 py-3 text-right">
                <div class="inline-flex items-center gap-1">
                  <button
                    type="button"
                    class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-slate-300 transition-colors hover:bg-white/5 hover:text-white focus-visible:outline-none"
                    @click="openEdit(row)"
                  >
                    Ubah
                  </button>
                  <button
                    type="button"
                    class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-rose-300 transition-colors hover:bg-rose-500/10 focus-visible:outline-none"
                    @click="askDelete(row)"
                  >
                    Hapus
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <AppPagination v-if="!loading && !error" :meta="meta" @change="(p) => (page = p)" />
    </div>

    <CategoryFormModal
      :open="showForm"
      :category="editing"
      @close="showForm = false"
      @saved="onSaved"
    />

    <AppConfirmDialog
      :open="confirmTarget !== null"
      title="Hapus kategori?"
      :message="`Kategori “${confirmTarget?.name ?? ''}” akan dihapus permanen. Tindakan ini tidak dapat dibatalkan.`"
      confirm-label="Hapus"
      :loading="deleting"
      @close="confirmTarget = null"
      @confirm="confirmDelete"
    />
  </div>
</template>
