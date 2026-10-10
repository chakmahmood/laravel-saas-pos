<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import AppBadge from '@/components/ui/AppBadge.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppEmptyState from '@/components/ui/AppEmptyState.vue'
import AppErrorState from '@/components/ui/AppErrorState.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import AppInput from '@/components/ui/AppInput.vue'
import AppModal from '@/components/ui/AppModal.vue'
import AppPagination from '@/components/ui/AppPagination.vue'
import AppSkeleton from '@/components/ui/AppSkeleton.vue'
import { ordersService, paymentsService } from '@/features/pos/api'
import {
  PAYMENT_METHODS,
  fulfillmentStatusLabel,
  paymentStatusLabel,
  paymentStatusVariant,
  type Order,
  type PaymentMethod,
} from '@/features/pos/types'
import { productsService } from '@/features/products/api'
import type { Product } from '@/features/products/types'
import { debounce } from '@/lib/debounce'
import { ApiError, humanMessage } from '@/lib/errors'
import { formatCurrency } from '@/lib/format'
import { useCurrentStoreStore } from '@/stores/currentStore'
import { useToastStore } from '@/stores/toast'
import type { PaginationMeta } from '@/types/api'
import { catalogLabel, itemTypeLabel } from '@/types/enums'

/**
 * POS retail checkout.
 *
 * The backend is the source of truth for prices, totals and status. The POS
 * creates an order (`POST /api/orders`) and then records the payment
 * (`POST /api/orders/{order}/payments`) — the documented, tested two-step flow.
 * Frontend amounts are display-only previews and are never sent as authority.
 */

/** Backend accepts up to 999999 per line; retail POS uses whole quantities. */
const MAX_QUANTITY = 999999
const PER_PAGE = 24

interface CartLine {
  id: number
  name: string
  unit: string
  unitPrice: number
  quantity: number
  tracksStock: boolean
}

const store = useCurrentStoreStore()
const toast = useToastStore()

const storeName = computed(() => store.current?.name ?? 'Toko')
const catalogTitle = computed(() => catalogLabel(store.current?.business_type))

/* --- Product catalog ----------------------------------------------------- */

const products = ref<Product[]>([])
const meta = ref<PaginationMeta | null>(null)
const loading = ref(false)
const catalogError = ref('')
const searchInput = ref('')
const search = ref('')
const page = ref(1)

let loadSeq = 0

async function loadProducts(): Promise<void> {
  if (!store.current) {
    return
  }
  const requestedStoreId = store.current.id
  const requestId = ++loadSeq

  loading.value = true
  catalogError.value = ''
  try {
    const result = await productsService.list({
      page: page.value,
      per_page: PER_PAGE,
      search: search.value || undefined,
      is_active: true,
      sort: 'name',
      direction: 'asc',
    })
    if (requestId !== loadSeq || store.current?.id !== requestedStoreId) {
      return
    }
    products.value = result.data
    meta.value = result.meta
  } catch (caught) {
    if (requestId !== loadSeq) {
      return
    }
    catalogError.value =
      caught instanceof ApiError ? humanMessage(caught) : 'Gagal memuat produk.'
    products.value = []
    meta.value = null
  } finally {
    if (requestId === loadSeq) {
      loading.value = false
    }
  }
}

const applySearch = debounce((value: string) => {
  search.value = value
}, 300)

watch(searchInput, (value) => applySearch(value))
watch(search, () => {
  page.value = 1
  void loadProducts()
})
watch(page, () => void loadProducts())

/* --- Cart ---------------------------------------------------------------- */

const cart = ref<CartLine[]>([])

const itemCount = computed(() => cart.value.reduce((sum, line) => sum + line.quantity, 0))
const subtotal = computed(() =>
  cart.value.reduce((sum, line) => sum + line.unitPrice * line.quantity, 0),
)
// No tax or order-level discount is offered: the domain has no automatic tax
// and the POS does not invent one. So the previewed total equals the subtotal.
const total = computed(() => subtotal.value)
const isEmpty = computed(() => cart.value.length === 0)

function addProduct(product: Product): void {
  if (cartLocked.value || !product.is_active) {
    return
  }
  const existing = cart.value.find((line) => line.id === product.id)
  if (existing) {
    if (existing.quantity < MAX_QUANTITY) {
      existing.quantity += 1
    }
    return
  }
  cart.value = [
    ...cart.value,
    {
      id: product.id,
      name: product.name,
      unit: product.unit,
      unitPrice: product.selling_price,
      quantity: 1,
      tracksStock: product.tracks_stock,
    },
  ]
}

function increment(lineId: number): void {
  if (cartLocked.value) {
    return
  }
  const line = cart.value.find((item) => item.id === lineId)
  if (line && line.quantity < MAX_QUANTITY) {
    line.quantity += 1
  }
}

function decrement(lineId: number): void {
  if (cartLocked.value) {
    return
  }
  const line = cart.value.find((item) => item.id === lineId)
  if (!line) {
    return
  }
  if (line.quantity > 1) {
    line.quantity -= 1
  } else {
    removeLine(lineId)
  }
}

function removeLine(lineId: number): void {
  if (cartLocked.value) {
    return
  }
  cart.value = cart.value.filter((line) => line.id !== lineId)
}

function clearCart(): void {
  cart.value = []
}

/* --- Checkout ------------------------------------------------------------ */

const paymentMethod = ref<PaymentMethod>('cash')
const submitting = ref(false)
const checkoutError = ref('')
// True when the last failure had no trustworthy server answer (network drop or
// 5xx), so the outcome of the request is unknown.
const resultUnknown = ref(false)
// Stable key for the current checkout attempt. Generated once and reused for
// every retry (order creation AND payment) until the attempt resolves or is
// explicitly abandoned, so a lost response can never create a duplicate.
const checkoutKey = ref<string | null>(null)
// Set once the server confirms an order exists but its payment did not
// complete. Re-clicking checkout retries payment on this same order.
const pendingOrder = ref<Order | null>(null)
const completedOrder = ref<Order | null>(null)
const showSuccess = ref(false)

const hasActiveAttempt = computed(() => checkoutKey.value !== null)
const cartLocked = computed(() => submitting.value || hasActiveAttempt.value)
const canCheckout = computed(() => !isEmpty.value && !submitting.value)

function newIdempotencyKey(): string {
  const cryptoApi = globalThis.crypto
  if (cryptoApi && typeof cryptoApi.randomUUID === 'function') {
    return cryptoApi.randomUUID()
  }
  return `pos-${Date.now()}-${Math.random().toString(36).slice(2)}`
}

function describeError(error: ApiError): string {
  if (error.isValidation && error.errors) {
    const first = Object.values(error.errors)[0]?.[0]
    if (first) {
      return first
    }
  }
  return humanMessage(error)
}

/**
 * Re-read the order so the confirmation shows the server's authoritative
 * payment status. If the read fails, the payment was still accepted, so the
 * order is settled at its own server-computed total.
 */
async function settleOrder(order: Order): Promise<Order> {
  try {
    return await ordersService.show(order.id)
  } catch {
    return { ...order, payment_status: 'paid', paid_amount: order.total_amount }
  }
}

async function checkout(): Promise<void> {
  if (!canCheckout.value || submitting.value) {
    return
  }
  submitting.value = true
  checkoutError.value = ''
  resultUnknown.value = false

  // Generate the key once per attempt and reuse it on every retry.
  const idempotencyKey = checkoutKey.value ?? newIdempotencyKey()
  checkoutKey.value = idempotencyKey

  try {
    let order = pendingOrder.value

    if (order === null) {
      order = await ordersService.create({
        items: cart.value.map((line) => ({
          item_id: line.id,
          quantity: line.quantity,
        })),
        idempotency_key: idempotencyKey,
      })
      // Payment is a separate step. Keep the confirmed order so a failed
      // payment can be retried without creating a second one.
      pendingOrder.value = order
    }

    if (order.total_amount > 0) {
      await paymentsService.record(order.id, {
        payment_method: paymentMethod.value,
        amount: order.total_amount,
        idempotency_key: idempotencyKey,
      })
    }

    completedOrder.value = await settleOrder(order)
    pendingOrder.value = null
    checkoutKey.value = null
    showSuccess.value = true
    clearCart()
  } catch (caught) {
    const error = caught instanceof ApiError ? caught : new ApiError({ message: '', status: 0 })
    checkoutError.value = describeError(error)
    // A network drop or a 5xx means we do NOT know whether the request was
    // processed. The key is kept so the next attempt is idempotent; we never
    // retry blindly with a fresh key.
    resultUnknown.value = error.isNetworkError || error.isServerError
    toast.error(checkoutError.value)
  } finally {
    submitting.value = false
  }
}

/** Abandon the current attempt and start over with an empty cart. */
function startNewTransaction(): void {
  pendingOrder.value = null
  checkoutKey.value = null
  resultUnknown.value = false
  checkoutError.value = ''
  clearCart()
}

function closeSuccess(): void {
  showSuccess.value = false
  completedOrder.value = null
  checkoutError.value = ''
}

/* --- Store switching ----------------------------------------------------- */

watch(
  () => store.current?.id,
  () => {
    products.value = []
    meta.value = null
    page.value = 1
    searchInput.value = ''
    search.value = ''
    cart.value = []
    pendingOrder.value = null
    checkoutKey.value = null
    resultUnknown.value = false
    checkoutError.value = ''
    completedOrder.value = null
    showSuccess.value = false
    void loadProducts()
  },
)

onMounted(() => void loadProducts())
</script>

<template>
  <div class="space-y-5">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <div class="flex items-center gap-2">
          <span class="text-slate-400"><AppIcon name="store" :size="20" /></span>
          <h1 class="text-xl font-semibold text-white">Kasir — {{ storeName }}</h1>
        </div>
        <p class="mt-1 text-sm text-slate-400">
          Pilih {{ catalogTitle.toLowerCase() }}, susun keranjang, lalu proses pembayaran.
        </p>
      </div>
      <AppBadge variant="brand">{{ itemCount }} item</AppBadge>
    </header>

    <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_380px]">
      <!-- Catalog -->
      <section class="space-y-4">
        <div class="rounded-xl border border-hairline bg-panel p-3 shadow-panel">
          <AppInput
            v-model="searchInput"
            name="pos-search"
            placeholder="Cari nama, SKU, atau barcode…"
            inputmode="search"
          />
        </div>

        <div v-if="loading" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          <div
            v-for="n in 6"
            :key="n"
            class="rounded-xl border border-hairline bg-panel p-4 shadow-panel"
          >
            <AppSkeleton :lines="3" />
          </div>
        </div>

        <div v-else-if="catalogError" class="rounded-xl border border-hairline bg-panel p-4">
          <AppErrorState :message="catalogError" @retry="loadProducts" />
        </div>

        <div
          v-else-if="products.length === 0"
          class="rounded-xl border border-hairline bg-panel shadow-panel"
        >
          <AppEmptyState
            icon="box"
            :title="search ? `Tidak ada ${catalogTitle.toLowerCase()} yang cocok` : `Belum ada ${catalogTitle.toLowerCase()}`"
            description="Tambahkan item aktif pada menu katalog untuk mulai berjualan."
          />
        </div>

        <div v-else class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          <button
            v-for="product in products"
            :key="product.id"
            type="button"
            :disabled="cartLocked"
            class="flex flex-col items-start gap-2 rounded-xl border border-hairline bg-panel p-4 text-left shadow-panel transition-colors hover:border-brand-500/50 hover:bg-panel-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 disabled:cursor-not-allowed disabled:opacity-60"
            @click="addProduct(product)"
          >
            <div class="flex w-full items-start justify-between gap-2">
              <span class="line-clamp-2 text-sm font-semibold text-slate-100">
                {{ product.name }}
              </span>
              <AppBadge variant="neutral">{{ itemTypeLabel(product.type) }}</AppBadge>
            </div>
            <span class="text-base font-semibold text-brand-300">
              {{ formatCurrency(product.selling_price) }}
            </span>
            <div class="flex w-full items-center justify-between gap-2">
              <span class="text-xs text-slate-500">{{ product.unit }}</span>
              <AppBadge v-if="product.tracks_stock" variant="info" dot>Stok dilacak</AppBadge>
            </div>
          </button>
        </div>

        <div v-if="!loading && !catalogError && products.length > 0" class="rounded-xl border border-hairline bg-panel shadow-panel">
          <AppPagination :meta="meta" @change="(p) => (page = p)" />
        </div>
      </section>

      <!-- Cart -->
      <aside class="lg:sticky lg:top-20 lg:self-start">
        <div class="flex max-h-[calc(100vh-6rem)] flex-col overflow-hidden rounded-xl border border-hairline bg-panel shadow-panel">
          <header class="flex items-center justify-between gap-2 border-b border-hairline px-4 py-3">
            <h2 class="text-sm font-semibold text-white">Keranjang</h2>
            <button
              v-if="!isEmpty"
              type="button"
              class="rounded-lg px-2 py-1 text-xs font-medium text-slate-400 transition-colors hover:bg-white/5 hover:text-rose-300 focus-visible:outline-none"
              :disabled="cartLocked"
              @click="clearCart"
            >
              Kosongkan
            </button>
          </header>

          <div class="min-h-0 flex-1 overflow-y-auto">
            <div v-if="isEmpty" class="px-4 py-10 text-center">
              <p class="text-sm font-medium text-slate-300">Keranjang masih kosong</p>
              <p class="mt-1 text-xs text-slate-500">Klik item untuk menambahkannya.</p>
            </div>

            <ul v-else class="divide-y divide-hairline">
              <li v-for="line in cart" :key="line.id" class="px-4 py-3">
                <div class="flex items-start justify-between gap-3">
                  <div class="min-w-0">
                    <p class="truncate text-sm font-medium text-slate-100">{{ line.name }}</p>
                    <p class="mt-0.5 text-xs text-slate-500">
                      {{ formatCurrency(line.unitPrice) }} / {{ line.unit }}
                    </p>
                  </div>
                  <button
                    type="button"
                    class="shrink-0 rounded-lg p-1 text-slate-500 transition-colors hover:bg-rose-500/10 hover:text-rose-300 focus-visible:outline-none"
                    :disabled="cartLocked"
                    :aria-label="`Hapus ${line.name}`"
                    @click="removeLine(line.id)"
                  >
                    <AppIcon name="close" :size="16" />
                  </button>
                </div>

                <div class="mt-2 flex items-center justify-between gap-3">
                  <div class="inline-flex items-center rounded-lg border border-hairline">
                    <button
                      type="button"
                      class="flex h-8 w-8 items-center justify-center text-slate-300 transition-colors hover:bg-white/5 disabled:opacity-50 focus-visible:outline-none"
                      :disabled="cartLocked"
                      :aria-label="`Kurangi ${line.name}`"
                      @click="decrement(line.id)"
                    >
                      <span class="text-base leading-none">−</span>
                    </button>
                    <span class="w-10 text-center text-sm tabular-nums text-slate-100">
                      {{ line.quantity }}
                    </span>
                    <button
                      type="button"
                      class="flex h-8 w-8 items-center justify-center text-slate-300 transition-colors hover:bg-white/5 disabled:opacity-50 focus-visible:outline-none"
                      :disabled="cartLocked"
                      :aria-label="`Tambah ${line.name}`"
                      @click="increment(line.id)"
                    >
                      <span class="text-base leading-none">+</span>
                    </button>
                  </div>
                  <span class="text-sm font-semibold tabular-nums text-slate-200">
                    {{ formatCurrency(line.unitPrice * line.quantity) }}
                  </span>
                </div>
              </li>
            </ul>
          </div>

          <div class="border-t border-hairline px-4 py-4">
            <div
              v-if="resultUnknown"
              class="mb-3 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2"
            >
              <p class="text-xs text-amber-200">
                Koneksi terputus sebelum server memberi konfirmasi, jadi hasilnya belum diketahui.
                Menekan <span class="font-semibold">Proses pembayaran</span> akan mencoba lagi
                dengan permintaan yang sama (aman, tidak membuat order ganda), atau mulai transaksi
                baru.
              </p>
            </div>
            <div
              v-else-if="pendingOrder"
              class="mb-3 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2"
            >
              <p class="text-xs text-amber-200">
                Order <span class="font-semibold">{{ pendingOrder.order_number }}</span> sudah dibuat
                tetapi pembayaran belum selesai. Pesanan tetap tersimpan dan belum dibayar.
              </p>
            </div>

            <dl class="space-y-1.5">
              <div class="flex items-center justify-between text-sm">
                <dt class="text-slate-400">Subtotal</dt>
                <dd class="tabular-nums text-slate-200">{{ formatCurrency(subtotal) }}</dd>
              </div>
              <div class="flex items-center justify-between border-t border-hairline pt-2 text-base font-semibold">
                <dt class="text-slate-100">Total</dt>
                <dd class="tabular-nums text-white">{{ formatCurrency(total) }}</dd>
              </div>
            </dl>

            <div class="mt-4">
              <label class="text-xs font-medium text-slate-400">Metode pembayaran</label>
              <div class="mt-2 grid grid-cols-2 gap-2">
                <button
                  v-for="method in PAYMENT_METHODS"
                  :key="method.value"
                  type="button"
                  :disabled="submitting"
                  class="rounded-lg border px-3 py-2 text-xs font-medium transition-colors focus-visible:outline-none disabled:opacity-60"
                  :class="
                    paymentMethod === method.value
                      ? 'border-brand-500 bg-brand-500/15 text-brand-200'
                      : 'border-hairline bg-panel-2 text-slate-300 hover:bg-panel-3'
                  "
                  @click="paymentMethod = method.value"
                >
                  {{ method.label }}
                </button>
              </div>
            </div>

            <p
              v-if="paymentMethod === 'cash'"
              class="mt-2 text-xs text-slate-500"
            >
              Pembayaran tunai memerlukan shift kas yang terbuka.
            </p>

            <div v-if="checkoutError" class="mt-3 rounded-lg border border-rose-500/25 bg-rose-500/5 px-3 py-2">
              <p class="text-xs text-rose-200">{{ checkoutError }}</p>
            </div>

            <div class="mt-4 flex flex-col gap-2">
              <AppButton
                block
                size="lg"
                :loading="submitting"
                :disabled="!canCheckout"
                @click="checkout"
              >
                <AppIcon name="card" :size="18" />
                {{ pendingOrder ? 'Bayar ulang' : 'Proses pembayaran' }}
              </AppButton>
              <AppButton
                v-if="hasActiveAttempt"
                block
                variant="secondary"
                :disabled="submitting"
                @click="startNewTransaction"
              >
                Mulai transaksi baru
              </AppButton>
            </div>
          </div>
        </div>
      </aside>
    </div>

    <AppModal :open="showSuccess" title="Transaksi berhasil" max-width="sm" @close="closeSuccess">
      <div v-if="completedOrder" class="space-y-4">
        <div class="flex items-center gap-3">
          <span class="flex h-10 w-10 items-center justify-center rounded-full bg-emerald-500/15 text-emerald-300">
            <AppIcon name="checkCircle" :size="22" />
          </span>
          <div>
            <p class="text-sm font-semibold text-white">{{ completedOrder.order_number }}</p>
            <p class="text-xs text-slate-400">Pembayaran tercatat pada server.</p>
          </div>
        </div>

        <dl class="space-y-2 rounded-lg border border-hairline bg-panel-2 px-3 py-3 text-sm">
          <div class="flex items-center justify-between">
            <dt class="text-slate-400">Total</dt>
            <dd class="font-semibold tabular-nums text-slate-100">
              {{ formatCurrency(completedOrder.total_amount) }}
            </dd>
          </div>
          <div class="flex items-center justify-between">
            <dt class="text-slate-400">Status pembayaran</dt>
            <dd>
              <AppBadge :variant="paymentStatusVariant(completedOrder.payment_status)">
                {{ paymentStatusLabel(completedOrder.payment_status) }}
              </AppBadge>
            </dd>
          </div>
          <div class="flex items-center justify-between">
            <dt class="text-slate-400">Status pesanan</dt>
            <dd>
              <AppBadge variant="neutral">
                {{ fulfillmentStatusLabel(completedOrder.fulfillment_status) }}
              </AppBadge>
            </dd>
          </div>
          <div class="flex items-center justify-between">
            <dt class="text-slate-400">Dibayar</dt>
            <dd class="tabular-nums text-slate-100">
              {{ formatCurrency(completedOrder.paid_amount) }}
            </dd>
          </div>
        </dl>
      </div>

      <template #footer>
        <AppButton block @click="closeSuccess">Transaksi baru</AppButton>
      </template>
    </AppModal>
  </div>
</template>
