<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppCheckbox from '@/components/ui/AppCheckbox.vue'
import AppCurrencyInput from '@/components/ui/AppCurrencyInput.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import AppInput from '@/components/ui/AppInput.vue'
import AppModal from '@/components/ui/AppModal.vue'
import AppSelect from '@/components/ui/AppSelect.vue'
import AppTextarea from '@/components/ui/AppTextarea.vue'
import { productsService } from '@/features/products/api'
import type { Product, ProductPayload } from '@/features/products/types'
import type { Category } from '@/features/categories/types'
import { ApiError, humanMessage } from '@/lib/errors'
import { ITEM_TYPE_LABELS, ITEM_TYPE_VALUES, type ItemType } from '@/types/enums'

const props = defineProps<{
  open: boolean
  product: Product | null
  categories: Category[]
  inventoryEnabled: boolean
}>()

const emit = defineEmits<{ (event: 'close'): void; (event: 'saved', product: Product): void }>()

const FORM_ID = 'product-form'

const name = ref('')
const type = ref<ItemType>('product')
const categoryId = ref('')
const sku = ref('')
const barcode = ref('')
const unit = ref('pcs')
const costPrice = ref<number | null>(null)
const sellingPrice = ref<number | null>(null)
const description = ref('')
const isActive = ref(true)
const tracksStock = ref(false)

const submitting = ref(false)
const formError = ref('')
const fieldErrors = ref<Record<string, string>>({})

const isEdit = computed(() => props.product !== null)
const title = computed(() => (isEdit.value ? 'Ubah Produk' : 'Tambah Produk'))

const typeOptions = ITEM_TYPE_VALUES.map((value) => ({ value, label: ITEM_TYPE_LABELS[value] }))
const categoryOptions = computed(() => [
  { value: '', label: 'Tanpa kategori' },
  ...props.categories.map((category) => ({ value: String(category.id), label: category.name })),
])

watch(
  () => props.open,
  (open) => {
    if (!open) {
      return
    }
    const product = props.product
    name.value = product?.name ?? ''
    type.value = product?.type ?? 'product'
    categoryId.value = product?.category_id ? String(product.category_id) : ''
    sku.value = product?.sku ?? ''
    barcode.value = product?.barcode ?? ''
    unit.value = product?.unit ?? 'pcs'
    costPrice.value = product?.cost_price ?? null
    sellingPrice.value = product?.selling_price ?? null
    description.value = product?.description ?? ''
    isActive.value = product?.is_active ?? true
    tracksStock.value = product?.tracks_stock ?? false
    formError.value = ''
    fieldErrors.value = {}
    submitting.value = false
  },
)

function validate(): boolean {
  fieldErrors.value = {}
  if (name.value.trim() === '') {
    fieldErrors.value.name = 'Nama produk wajib diisi.'
  }
  if (sellingPrice.value === null || sellingPrice.value < 0) {
    fieldErrors.value.selling_price = 'Harga jual wajib diisi.'
  }
  return Object.keys(fieldErrors.value).length === 0
}

async function onSubmit(): Promise<void> {
  if (submitting.value || !validate()) {
    return
  }

  formError.value = ''
  const payload: ProductPayload = {
    name: name.value.trim(),
    type: type.value,
    category_id: categoryId.value === '' ? null : Number(categoryId.value),
    sku: sku.value.trim() === '' ? null : sku.value.trim(),
    barcode: barcode.value.trim() === '' ? null : barcode.value.trim(),
    description: description.value.trim() === '' ? null : description.value.trim(),
    cost_price: costPrice.value,
    selling_price: sellingPrice.value ?? 0,
    unit: unit.value.trim() === '' ? 'pcs' : unit.value.trim(),
    is_active: isActive.value,
  }
  if (props.inventoryEnabled) {
    payload.tracks_stock = tracksStock.value
  }

  submitting.value = true
  try {
    const saved = props.product
      ? await productsService.update(props.product.id, payload)
      : await productsService.create(payload)
    emit('saved', saved)
  } catch (caught) {
    if (caught instanceof ApiError) {
      const mapped: Record<string, string> = {}
      for (const [field, messages] of Object.entries(caught.errors ?? {})) {
        if (messages[0]) {
          mapped[field] = messages[0]
        }
      }
      fieldErrors.value = mapped
      if (Object.keys(mapped).length === 0 || caught.status !== 422) {
        formError.value = humanMessage(caught)
      }
    } else {
      formError.value = 'Terjadi kesalahan. Silakan coba lagi.'
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <AppModal :open="open" :title="title" max-width="lg" @close="emit('close')">
    <form :id="FORM_ID" class="space-y-4" novalidate @submit.prevent="onSubmit">
      <div
        v-if="formError"
        role="alert"
        class="flex items-start gap-2.5 rounded-lg border border-rose-500/25 bg-rose-500/5 px-3.5 py-2.5 text-sm text-rose-200"
      >
        <AppIcon name="alertCircle" :size="18" class="mt-0.5 shrink-0" />
        <span>{{ formError }}</span>
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <AppInput
          v-model="name"
          label="Nama produk"
          name="name"
          placeholder="Contoh: Kopi Susu"
          :maxlength="150"
          :error="fieldErrors.name"
          required
        />
        <AppSelect
          v-model="type"
          label="Tipe"
          name="type"
          :options="typeOptions"
          :error="fieldErrors.type"
          required
        />
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <AppSelect
          v-model="categoryId"
          label="Kategori"
          name="category_id"
          :options="categoryOptions"
          :error="fieldErrors.category_id"
        />
        <AppInput
          v-model="unit"
          label="Satuan"
          name="unit"
          placeholder="pcs / kg / porsi"
          :maxlength="20"
          :error="fieldErrors.unit"
        />
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <AppCurrencyInput
          v-model="sellingPrice"
          label="Harga jual"
          name="selling_price"
          :error="fieldErrors.selling_price"
          required
        />
        <AppCurrencyInput
          v-model="costPrice"
          label="Harga pokok (opsional)"
          name="cost_price"
          :error="fieldErrors.cost_price"
          hint="Kosongkan bila tidak dilacak."
        />
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <AppInput
          v-model="sku"
          label="SKU (opsional)"
          name="sku"
          placeholder="KOPI-01"
          :maxlength="64"
          :error="fieldErrors.sku"
        />
        <AppInput
          v-model="barcode"
          label="Barcode (opsional)"
          name="barcode"
          placeholder="8990000000000"
          :maxlength="64"
          :error="fieldErrors.barcode"
        />
      </div>

      <AppTextarea
        v-model="description"
        label="Deskripsi"
        name="description"
        placeholder="Deskripsi singkat (opsional)"
        :maxlength="2000"
        :error="fieldErrors.description"
      />

      <div class="space-y-3 rounded-lg border border-hairline bg-panel-2 px-4 py-3">
        <AppCheckbox
          v-model="isActive"
          label="Aktif"
          description="Produk nonaktif tidak dapat dijual di kasir."
        />
        <AppCheckbox
          v-if="inventoryEnabled"
          v-model="tracksStock"
          label="Lacak stok"
          description="Aktifkan bila produk ini memiliki saldo stok yang perlu dipantau."
        />
      </div>
    </form>

    <template #footer>
      <AppButton variant="secondary" :disabled="submitting" @click="emit('close')">Batal</AppButton>
      <AppButton type="submit" :form="FORM_ID" :loading="submitting" :disabled="submitting">
        {{ submitting ? 'Menyimpan…' : 'Simpan' }}
      </AppButton>
    </template>
  </AppModal>
</template>
