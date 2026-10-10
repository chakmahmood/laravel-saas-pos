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
import ProductFormModal from '@/features/products/ProductFormModal.vue'
import { productsService } from '@/features/products/api'
import type { Product } from '@/features/products/types'
import { categoriesService } from '@/features/categories/api'
import type { Category } from '@/features/categories/types'
import { debounce } from '@/lib/debounce'
import { ApiError, humanMessage } from '@/lib/errors'
import { formatCurrency } from '@/lib/format'
import { useCurrentStoreStore } from '@/stores/currentStore'
import { useToastStore } from '@/stores/toast'
import type { PaginationMeta } from '@/types/api'
import { ITEM_TYPE_LABELS, ITEM_TYPE_VALUES, catalogLabel, itemTypeLabel, type ItemType } from '@/types/enums'

const store = useCurrentStoreStore()
const catalogTitle = computed(() => catalogLabel(store.current?.business_type))
const toast = useToastStore()

const rows = ref<Product[]>([])
const meta = ref<PaginationMeta | null>(null)
const loading = ref(false)
const error = ref('')

const categories = ref<Category[]>([])
const categoryMap = computed(() => new Map(categories.value.map((c) => [c.id, c.name])))

const searchInput = ref('')
const search = ref('')
const typeFilter = ref<'all' | ItemType>('all')
const categoryFilter = ref<'all' | string>('all')
const activeFilter = ref<'all' | 'true' | 'false'>('all')
const sort = ref<'name' | 'created_at' | 'selling_price'>('name')
const direction = ref<'asc' | 'desc'>('asc')
const page = ref(1)
const perPage = ref(15)

const showForm = ref(false)
const editing = ref<Product | null>(null)
const confirmTarget = ref<Product | null>(null)
const deleting = ref(false)

const canManage = computed(() => store.canManage)
const inventoryEnabled = computed(() => store.inventoryEnabled)
const hasFilters = computed(
  () =>
    search.value !== '' ||
    typeFilter.value !== 'all' ||
    categoryFilter.value !== 'all' ||
    activeFilter.value !== 'all',
)

const typeOptions = [
  { value: 'all', label: 'Semua tipe' },
  ...ITEM_TYPE_VALUES.map((value) => ({ value, label: ITEM_TYPE_LABELS[value] })),
]
const activeOptions = [
  { value: 'all', label: 'Semua status' },
  { value: 'true', label: 'Aktif' },
  { value: 'false', label: 'Nonaktif' },
]
const categoryOptions = computed(() => [
  { value: 'all', label: 'Semua kategori' },
  ...categories.value.map((category) => ({ value: String(category.id), label: category.name })),
])

let loadSeq = 0

async function loadCategories(): Promise<void> {
  try {
    categories.value = await categoriesService.listAll()
  } catch {
    // Category list is auxiliary (filter + name mapping); never block products.
    categories.value = []
  }
}

async function load(): Promise<void> {
  if (!store.current) {
    return
  }
  const requestedStoreId = store.current.id
  const requestId = ++loadSeq

  loading.value = true
  error.value = ''
  try {
    const result = await productsService.list({
      page: page.value,
      per_page: perPage.value,
      search: search.value || undefined,
      type: typeFilter.value === 'all' ? undefined : typeFilter.value,
      category_id: categoryFilter.value === 'all' ? undefined : Number(categoryFilter.value),
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
    error.value = caught instanceof ApiError ? humanMessage(caught) : 'Gagal memuat data produk.'
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
watch([search, typeFilter, categoryFilter, activeFilter, sort, direction], () => {
  page.value = 1
  void load()
})
watch(page, () => void load())

watch(
  () => store.current?.id,
  () => {
    rows.value = []
    meta.value = null
    categories.value = []
    page.value = 1
    searchInput.value = ''
    search.value = ''
    typeFilter.value = 'all'
    categoryFilter.value = 'all'
    activeFilter.value = 'all'
    void loadCategories()
    void load()
  },
)

function categoryName(id: number | null): string {
  if (id === null) {
    return '—'
  }
  return categoryMap.value.get(id) ?? '—'
}

function openCreate(): void {
  editing.value = null
  showForm.value = true
}

function openEdit(product: Product): void {
  editing.value = product
  showForm.value = true
}

function onSaved(): void {
  const wasEdit = editing.value !== null
  showForm.value = false
  editing.value = null
  toast.success(wasEdit ? 'Produk berhasil diperbarui.' : 'Produk berhasil dibuat.')
  void load()
}

function askDelete(product: Product): void {
  confirmTarget.value = product
}

async function confirmDelete(): Promise<void> {
  if (confirmTarget.value === null || deleting.value) {
    return
  }
  const target = confirmTarget.value
  deleting.value = true
  try {
    await productsService.remove(target.id)
    confirmTarget.value = null
    toast.success('Produk berhasil dihapus.')
    void load()
  } catch (caught) {
    confirmTarget.value = null
    toast.error(caught instanceof ApiError ? humanMessage(caught) : 'Gagal menghapus produk.')
  } finally {
    deleting.value = false
  }
}

onMounted(() => {
  void loadCategories()
  void load()
})
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 class="text-xl font-semibold text-white">{{ catalogTitle }}</h1>
        <p class="mt-1 text-sm text-slate-400">
          Kelola katalog produk, jasa, menu, dan paket toko Anda.
        </p>
      </div>
      <AppButton v-if="canManage" @click="openCreate">Tambah {{ catalogTitle }}</AppButton>
    </header>

    <div class="rounded-xl border border-hairline bg-panel shadow-panel">
      <div class="grid gap-3 border-b border-hairline p-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="lg:col-span-2">
          <AppInput v-model="searchInput" name="product-search" placeholder="Cari nama, SKU, atau barcode…" />
        </div>
        <AppSelect v-model="typeFilter" name="product-type" :options="typeOptions" />
        <AppSelect v-model="categoryFilter" name="product-category" :options="categoryOptions" />
        <AppSelect v-model="activeFilter" name="product-status" :options="activeOptions" />
      </div>

      <div v-if="loading" class="space-y-3 p-4">
        <div v-for="n in 5" :key="n" class="rounded-lg border border-hairline bg-panel-2 px-4 py-3">
          <AppSkeleton :lines="2" />
        </div>
      </div>

      <div v-else-if="error" class="p-4">
        <AppErrorState :message="error" @retry="load" />
      </div>

      <AppEmptyState
        v-else-if="rows.length === 0"
        icon="box"
        :title="hasFilters ? `Tidak ada ${catalogTitle.toLowerCase()} yang cocok` : `Belum Ada ${catalogTitle}`"
        :description="
          hasFilters
            ? 'Coba ubah kata kunci atau filter.'
            : `Tambahkan ${catalogTitle.toLowerCase()} pertama untuk mulai berjualan.`
        "
      >
        <template #actions>
          <AppButton v-if="canManage && !hasFilters" size="sm" @click="openCreate">
            Tambah {{ catalogTitle }}
          </AppButton>
        </template>
      </AppEmptyState>

      <div v-else class="overflow-x-auto">
        <table class="w-full min-w-[820px] border-collapse text-sm">
          <thead>
            <tr class="border-b border-hairline text-left text-xs uppercase tracking-wide text-slate-500">
              <th class="px-4 py-3 font-medium">Nama</th>
              <th class="px-4 py-3 font-medium">SKU</th>
              <th class="px-4 py-3 font-medium">Kategori</th>
              <th class="px-4 py-3 font-medium">Tipe</th>
              <th class="px-4 py-3 text-right font-medium">Harga Jual</th>
              <th class="px-4 py-3 font-medium">Status</th>
              <th v-if="inventoryEnabled" class="px-4 py-3 font-medium">Stok</th>
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
              <td class="px-4 py-3 text-slate-400">{{ row.sku || '—' }}</td>
              <td class="max-w-[12rem] truncate px-4 py-3 text-slate-400">
                {{ categoryName(row.category_id) }}
              </td>
              <td class="px-4 py-3 text-slate-400">{{ itemTypeLabel(row.type) }}</td>
              <td class="px-4 py-3 text-right tabular-nums text-slate-200">
                {{ formatCurrency(row.selling_price) }}
              </td>
              <td class="px-4 py-3">
                <AppBadge :variant="row.is_active ? 'success' : 'warning'" dot>
                  {{ row.is_active ? 'Aktif' : 'Nonaktif' }}
                </AppBadge>
              </td>
              <td v-if="inventoryEnabled" class="px-4 py-3">
                <AppBadge :variant="row.tracks_stock ? 'brand' : 'neutral'">
                  {{ row.tracks_stock ? 'Dilacak' : 'Tidak dilacak' }}
                </AppBadge>
              </td>
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

    <ProductFormModal
      :open="showForm"
      :product="editing"
      :categories="categories"
      :inventory-enabled="inventoryEnabled"
      @close="showForm = false"
      @saved="onSaved"
    />

    <AppConfirmDialog
      :open="confirmTarget !== null"
      title="Hapus produk?"
      :message="`Produk “${confirmTarget?.name ?? ''}” akan dihapus permanen. Tindakan ini tidak dapat dibatalkan.`"
      confirm-label="Hapus"
      :loading="deleting"
      @close="confirmTarget = null"
      @confirm="confirmDelete"
    />
  </div>
</template>
