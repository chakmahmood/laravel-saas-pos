<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import AppBadge from '@/components/ui/AppBadge.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppEmptyState from '@/components/ui/AppEmptyState.vue'
import AppErrorState from '@/components/ui/AppErrorState.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import AppInput from '@/components/ui/AppInput.vue'
import AppModal from '@/components/ui/AppModal.vue'
import AppPagination from '@/components/ui/AppPagination.vue'
import AppSelect from '@/components/ui/AppSelect.vue'
import AppSkeleton from '@/components/ui/AppSkeleton.vue'
import { ordersService } from '@/features/pos/api'
import {
  FULFILLMENT_STATUS_VALUES,
  PAYMENT_STATUS_VALUES,
  fulfillmentStatusLabel,
  fulfillmentStatusVariant,
  paymentMethodLabel,
  paymentStatusLabel,
  paymentStatusVariant,
  type Order,
  type OrderFulfillmentStatus,
  type OrderPaymentStatus,
} from '@/features/pos/types'
import { debounce } from '@/lib/debounce'
import { ApiError, humanMessage } from '@/lib/errors'
import { formatCurrency, formatDateTime } from '@/lib/format'
import { useCurrentStoreStore } from '@/stores/currentStore'
import type { PaginationMeta } from '@/types/api'

/**
 * Transaction history. Reads the real order API (`GET /api/orders` and
 * `GET /api/orders/{order}`); the server resolves the active store and returns
 * only that tenant's data. Status values shown are the server's, never inferred.
 */

const router = useRouter()
const store = useCurrentStoreStore()

const rows = ref<Order[]>([])
const meta = ref<PaginationMeta | null>(null)
const loading = ref(false)
const error = ref('')

const searchInput = ref('')
const search = ref('')
const paymentFilter = ref<'all' | OrderPaymentStatus>('all')
const fulfillmentFilter = ref<'all' | OrderFulfillmentStatus>('all')
const dateFrom = ref('')
const dateTo = ref('')
const page = ref(1)
const perPage = ref(15)

const detailOpen = ref(false)
const detail = ref<Order | null>(null)
const detailLoading = ref(false)
const detailError = ref('')

const paymentOptions = [
  { value: 'all', label: 'Semua pembayaran' },
  ...PAYMENT_STATUS_VALUES.map((value) => ({ value, label: paymentStatusLabel(value) })),
]
const fulfillmentOptions = [
  { value: 'all', label: 'Semua pemenuhan' },
  ...FULFILLMENT_STATUS_VALUES.map((value) => ({ value, label: fulfillmentStatusLabel(value) })),
]

const hasFilters = computed(
  () =>
    search.value !== '' ||
    paymentFilter.value !== 'all' ||
    fulfillmentFilter.value !== 'all' ||
    dateFrom.value !== '' ||
    dateTo.value !== '',
)

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
    const result = await ordersService.list({
      page: page.value,
      per_page: perPage.value,
      search: search.value || undefined,
      payment_status: paymentFilter.value === 'all' ? undefined : paymentFilter.value,
      fulfillment_status: fulfillmentFilter.value === 'all' ? undefined : fulfillmentFilter.value,
      date_from: dateFrom.value || undefined,
      date_to: dateTo.value || undefined,
      sort: 'placed_at',
      direction: 'desc',
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
    error.value = caught instanceof ApiError ? humanMessage(caught) : 'Gagal memuat riwayat transaksi.'
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
watch([search, paymentFilter, fulfillmentFilter, dateFrom, dateTo], () => {
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
    paymentFilter.value = 'all'
    fulfillmentFilter.value = 'all'
    dateFrom.value = ''
    dateTo.value = ''
    void load()
  },
)

function resetFilters(): void {
  searchInput.value = ''
  search.value = ''
  paymentFilter.value = 'all'
  fulfillmentFilter.value = 'all'
  dateFrom.value = ''
  dateTo.value = ''
}

function customerName(order: Order): string {
  return order.customer?.name ?? 'Umum'
}

async function openDetail(order: Order): Promise<void> {
  detailOpen.value = true
  detail.value = order
  detailLoading.value = true
  detailError.value = ''
  try {
    detail.value = await ordersService.show(order.id)
  } catch (caught) {
    detail.value = null
    detailError.value =
      caught instanceof ApiError ? humanMessage(caught) : 'Gagal memuat detail transaksi.'
  } finally {
    detailLoading.value = false
  }
}

function closeDetail(): void {
  detailOpen.value = false
  detail.value = null
  detailError.value = ''
}

function backToPos(): void {
  void router.push({ name: 'orders' })
}

onMounted(() => void load())
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 class="text-xl font-semibold text-white">Riwayat Transaksi</h1>
        <p class="mt-1 text-sm text-slate-400">
          Daftar order toko aktif. Cari, filter, dan buka detail transaksi.
        </p>
      </div>
      <AppButton variant="secondary" @click="backToPos">
        <AppIcon name="receipt" :size="16" />
        Kembali ke kasir
      </AppButton>
    </header>

    <div class="rounded-xl border border-hairline bg-panel shadow-panel">
      <div class="grid gap-3 border-b border-hairline p-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
        <div class="lg:col-span-1">
          <AppInput
            v-model="searchInput"
            name="order-search"
            placeholder="Cari nomor transaksi…"
            inputmode="search"
          />
        </div>
        <AppSelect v-model="paymentFilter" name="order-payment-status" :options="paymentOptions" />
        <AppSelect
          v-model="fulfillmentFilter"
          name="order-fulfillment-status"
          :options="fulfillmentOptions"
        />
        <AppInput v-model="dateFrom" name="order-date-from" type="date" />
        <div class="flex items-end gap-2">
          <AppInput v-model="dateTo" name="order-date-to" type="date" class="flex-1" />
          <AppButton v-if="hasFilters" variant="ghost" size="sm" @click="resetFilters">Reset</AppButton>
        </div>
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
        icon="receipt"
        :title="hasFilters ? 'Tidak ada transaksi yang cocok' : 'Belum ada transaksi'"
        :description="
          hasFilters
            ? 'Coba ubah kata kunci atau filter.'
            : 'Transaksi yang diproses di kasir akan muncul di sini.'
        "
      >
        <template #actions>
          <AppButton v-if="!hasFilters" size="sm" @click="backToPos">Buka kasir</AppButton>
        </template>
      </AppEmptyState>

      <div v-else class="overflow-x-auto">
        <table class="w-full min-w-[820px] border-collapse text-sm">
          <thead>
            <tr class="border-b border-hairline text-left text-xs uppercase tracking-wide text-slate-500">
              <th class="px-4 py-3 font-medium">Nomor</th>
              <th class="px-4 py-3 font-medium">Waktu</th>
              <th class="px-4 py-3 font-medium">Pelanggan</th>
              <th class="px-4 py-3 text-right font-medium">Total</th>
              <th class="px-4 py-3 font-medium">Pembayaran</th>
              <th class="px-4 py-3 font-medium">Pemenuhan</th>
              <th class="px-4 py-3 text-right font-medium">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in rows"
              :key="row.id"
              class="border-b border-hairline/60 last:border-0 hover:bg-white/[0.02]"
            >
              <td class="px-4 py-3 font-medium text-slate-100">{{ row.order_number }}</td>
              <td class="whitespace-nowrap px-4 py-3 text-slate-400">
                {{ formatDateTime(row.placed_at) }}
              </td>
              <td class="max-w-[12rem] truncate px-4 py-3 text-slate-300">
                {{ customerName(row) }}
              </td>
              <td class="px-4 py-3 text-right tabular-nums text-slate-200">
                {{ formatCurrency(row.total_amount) }}
              </td>
              <td class="px-4 py-3">
                <AppBadge :variant="paymentStatusVariant(row.payment_status)">
                  {{ paymentStatusLabel(row.payment_status) }}
                </AppBadge>
              </td>
              <td class="px-4 py-3">
                <AppBadge :variant="fulfillmentStatusVariant(row.fulfillment_status)">
                  {{ fulfillmentStatusLabel(row.fulfillment_status) }}
                </AppBadge>
              </td>
              <td class="px-4 py-3 text-right">
                <button
                  type="button"
                  class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-medium text-brand-300 transition-colors hover:bg-brand-500/10 focus-visible:outline-none"
                  @click="openDetail(row)"
                >
                  <AppIcon name="eye" :size="15" />
                  Detail
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <AppPagination v-if="!loading && !error" :meta="meta" @change="(p) => (page = p)" />
    </div>

    <AppModal
      :open="detailOpen"
      :title="detail ? `Detail ${detail.order_number}` : 'Detail Transaksi'"
      max-width="lg"
      @close="closeDetail"
    >
      <div v-if="detailLoading" class="space-y-3">
        <AppSkeleton :lines="5" />
      </div>

      <div v-else-if="detailError">
        <AppErrorState :message="detailError" :show-retry="false" />
      </div>

      <div v-else-if="detail" class="space-y-5">
        <div class="flex flex-wrap items-center gap-2">
          <AppBadge :variant="paymentStatusVariant(detail.payment_status)">
            {{ paymentStatusLabel(detail.payment_status) }}
          </AppBadge>
          <AppBadge :variant="fulfillmentStatusVariant(detail.fulfillment_status)">
            {{ fulfillmentStatusLabel(detail.fulfillment_status) }}
          </AppBadge>
        </div>

        <dl class="grid grid-cols-2 gap-3 text-sm">
          <div>
            <dt class="text-xs text-slate-500">Waktu</dt>
            <dd class="text-slate-200">{{ formatDateTime(detail.placed_at) }}</dd>
          </div>
          <div>
            <dt class="text-xs text-slate-500">Pelanggan</dt>
            <dd class="text-slate-200">{{ customerName(detail) }}</dd>
          </div>
        </dl>

        <div class="overflow-hidden rounded-lg border border-hairline">
          <table class="w-full border-collapse text-sm">
            <thead>
              <tr class="border-b border-hairline text-left text-xs uppercase tracking-wide text-slate-500">
                <th class="px-3 py-2 font-medium">Item</th>
                <th class="px-3 py-2 text-right font-medium">Qty</th>
                <th class="px-3 py-2 text-right font-medium">Harga</th>
                <th class="px-3 py-2 text-right font-medium">Subtotal</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="item in detail.items ?? []"
                :key="item.id"
                class="border-b border-hairline/60 last:border-0"
              >
                <td class="px-3 py-2 text-slate-200">
                  {{ item.item_name }}
                  <span v-if="item.item_sku" class="text-xs text-slate-500">· {{ item.item_sku }}</span>
                </td>
                <td class="px-3 py-2 text-right tabular-nums text-slate-300">
                  {{ item.quantity }} {{ item.unit }}
                </td>
                <td class="px-3 py-2 text-right tabular-nums text-slate-300">
                  {{ formatCurrency(item.unit_price) }}
                </td>
                <td class="px-3 py-2 text-right tabular-nums text-slate-200">
                  {{ formatCurrency(item.line_total) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <dl class="space-y-1.5 rounded-lg border border-hairline bg-panel-2 px-3 py-3 text-sm">
          <div class="flex items-center justify-between">
            <dt class="text-slate-400">Subtotal</dt>
            <dd class="tabular-nums text-slate-200">{{ formatCurrency(detail.subtotal) }}</dd>
          </div>
          <div v-if="detail.discount_amount > 0" class="flex items-center justify-between">
            <dt class="text-slate-400">Diskon</dt>
            <dd class="tabular-nums text-slate-200">-{{ formatCurrency(detail.discount_amount) }}</dd>
          </div>
          <div v-if="detail.tax_amount > 0" class="flex items-center justify-between">
            <dt class="text-slate-400">Pajak</dt>
            <dd class="tabular-nums text-slate-200">{{ formatCurrency(detail.tax_amount) }}</dd>
          </div>
          <div class="flex items-center justify-between border-t border-hairline pt-1.5">
            <dt class="font-medium text-slate-100">Total</dt>
            <dd class="font-semibold tabular-nums text-white">
              {{ formatCurrency(detail.total_amount) }}
            </dd>
          </div>
          <div class="flex items-center justify-between">
            <dt class="text-slate-400">Dibayar</dt>
            <dd class="tabular-nums text-slate-200">{{ formatCurrency(detail.paid_amount) }}</dd>
          </div>
          <div class="flex items-center justify-between">
            <dt class="text-slate-400">Sisa tagihan</dt>
            <dd class="tabular-nums text-slate-200">{{ formatCurrency(detail.remaining_amount) }}</dd>
          </div>
        </dl>

        <div>
          <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Pembayaran</h3>
          <p v-if="(detail.payments ?? []).length === 0" class="text-sm text-slate-400">
            Belum ada pembayaran tercatat.
          </p>
          <ul v-else class="space-y-2">
            <li
              v-for="payment in detail.payments ?? []"
              :key="payment.id"
              class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-hairline px-3 py-2 text-sm"
            >
              <span class="text-slate-200">{{ paymentMethodLabel(payment.payment_method) }}</span>
              <span class="tabular-nums text-slate-200">{{ formatCurrency(payment.amount) }}</span>
              <span class="text-xs text-slate-500">{{ formatDateTime(payment.paid_at) }}</span>
              <AppBadge :variant="payment.status === 'voided' ? 'danger' : 'success'">
                {{ payment.status === 'voided' ? 'Dibatalkan' : 'Tercatat' }}
              </AppBadge>
            </li>
          </ul>
        </div>

        <div v-if="detail.notes">
          <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Catatan</h3>
          <p class="text-sm text-slate-300">{{ detail.notes }}</p>
        </div>

        <div v-if="detail.cancel_reason" class="rounded-lg border border-rose-500/25 bg-rose-500/5 px-3 py-2">
          <p class="text-xs font-semibold text-rose-200">Alasan pembatalan</p>
          <p class="text-sm text-rose-200/90">{{ detail.cancel_reason }}</p>
        </div>
      </div>

      <template #footer>
        <AppButton variant="secondary" @click="closeDetail">Tutup</AppButton>
      </template>
    </AppModal>
  </div>
</template>
