<script setup lang="ts">
import { computed, ref } from 'vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import AppInput from '@/components/ui/AppInput.vue'
import AppPasswordInput from '@/components/ui/AppPasswordInput.vue'
import AppSelect from '@/components/ui/AppSelect.vue'
import { ApiError, humanMessage } from '@/lib/errors'
import { useAuthStore } from '@/stores/auth'
import {
  BUSINESS_TYPE_DESCRIPTIONS,
  BUSINESS_TYPE_LABELS,
  BUSINESS_TYPE_VALUES,
  type BusinessType,
} from '@/types/enums'
import type { RegisterPayload } from '@/types/models'

const emit = defineEmits<{ (event: 'success'): void }>()

const auth = useAuthStore()

const name = ref('')
const email = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const storeName = ref('')
const storeSlug = ref('')
const businessType = ref('')

const submitting = ref(false)
const formError = ref('')
const fieldErrors = ref<Record<string, string>>({})

const businessOptions = computed(() =>
  BUSINESS_TYPE_VALUES.map((value) => ({
    value,
    label: BUSINESS_TYPE_LABELS[value],
  })),
)

const businessDescription = computed(() =>
  businessType.value === '' || businessType.value === null
    ? ''
    : BUSINESS_TYPE_DESCRIPTIONS[businessType.value as BusinessType],
)

function slugify(value: string): string {
  return value
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
}

function syncSlug(): void {
  if (storeSlug.value === '' || storeSlug.value === slugify(storeName.value.slice(0, -1))) {
    storeSlug.value = slugify(storeName.value)
  }
}

async function onSubmit(): Promise<void> {
  formError.value = ''
  fieldErrors.value = {}

  const required: Array<[string, string, string]> = [
    ['name', name.value, 'Nama wajib diisi.'],
    ['email', email.value, 'Email wajib diisi.'],
    ['password', password.value, 'Kata sandi wajib diisi.'],
    ['store_name', storeName.value, 'Nama toko wajib diisi.'],
    ['store_slug', storeSlug.value, 'Slug toko wajib diisi.'],
    ['business_type', businessType.value, 'Jenis usaha wajib dipilih.'],
  ]
  for (const [field, value, message] of required) {
    if (value.trim() === '') {
      fieldErrors.value[field] = message
    }
  }
  if (password.value !== '' && password.value !== passwordConfirmation.value) {
    fieldErrors.value.password_confirmation = 'Konfirmasi kata sandi tidak cocok.'
  }
  if (Object.keys(fieldErrors.value).length > 0) {
    return
  }

  submitting.value = true
  try {
    const payload: RegisterPayload = {
      name: name.value.trim(),
      email: email.value.trim(),
      password: password.value,
      password_confirmation: passwordConfirmation.value,
      store_name: storeName.value.trim(),
      store_slug: storeSlug.value.trim(),
      business_type: businessType.value as BusinessType,
    }
    await auth.register(payload)
    emit('success')
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
  <form class="space-y-4" novalidate @submit.prevent="onSubmit">
    <div
      v-if="formError"
      role="alert"
      class="flex items-start gap-2.5 rounded-lg border border-rose-500/25 bg-rose-500/5 px-3.5 py-2.5 text-sm text-rose-200"
    >
      <AppIcon name="alertCircle" :size="18" class="mt-0.5 shrink-0" />
      <span>{{ formError }}</span>
    </div>

    <AppInput
      v-model="name"
      label="Nama lengkap"
      name="name"
      autocomplete="name"
      placeholder="Nama Anda"
      :error="fieldErrors.name"
      required
    />
    <AppInput
      v-model="email"
      label="Email"
      type="email"
      name="email"
      autocomplete="username"
      inputmode="email"
      placeholder="nama@toko.com"
      :error="fieldErrors.email"
      required
    />
    <div class="grid gap-4 sm:grid-cols-2">
      <AppPasswordInput
        v-model="password"
        label="Kata sandi"
        name="password"
        autocomplete="new-password"
        placeholder="Minimal 8 karakter"
        :error="fieldErrors.password"
        required
      />
      <AppPasswordInput
        v-model="passwordConfirmation"
        label="Ulangi kata sandi"
        name="password_confirmation"
        autocomplete="new-password"
        placeholder="Ulangi kata sandi"
        :error="fieldErrors.password_confirmation"
        required
      />
    </div>

    <div class="my-1 h-px bg-hairline" />

    <AppInput
      v-model="storeName"
      label="Nama toko"
      name="store_name"
      placeholder="Contoh: Toko Berkah"
      :error="fieldErrors.store_name"
      required
      @update:model-value="syncSlug"
    />
    <AppInput
      v-model="storeSlug"
      label="Slug toko"
      name="store_slug"
      placeholder="toko-berkah"
      hint="Dipakai pada URL toko. Huruf kecil, angka, dan tanda hubung."
      :error="fieldErrors.store_slug"
      required
    />
    <AppSelect
      v-model="businessType"
      label="Jenis usaha"
      name="business_type"
      placeholder="Pilih jenis usaha"
      :options="businessOptions"
      required
      :error="fieldErrors.business_type"
    />
    <p v-if="businessDescription" class="text-xs text-slate-400">{{ businessDescription }}</p>

    <AppButton type="submit" block :loading="submitting" :disabled="submitting">
      {{ submitting ? 'Memproses…' : 'Daftar & Buat Toko' }}
    </AppButton>
  </form>
</template>
