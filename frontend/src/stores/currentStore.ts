import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { ApiError } from '@/lib/errors'
import { sessionService } from '@/services/session'
import { usesInventory } from '@/types/enums'
import type { CurrentStore, StoreSummary } from '@/types/models'

/**
 * Tenant context: the stores the user can access and the active store attached
 * to the current token. The active store is always confirmed by the backend
 * (`GET/PUT /api/current-store`), never inferred only from the UI.
 */
export const useCurrentStoreStore = defineStore('currentStore', () => {
  const current = ref<CurrentStore | null>(null)
  const stores = ref<StoreSummary[]>([])
  const loading = ref(false)
  const loaded = ref(false)
  const error = ref<string | null>(null)

  const hasStore = computed(() => current.value !== null)
  const inventoryEnabled = computed(() => usesInventory(current.value?.business_type))
  const canManage = computed(
    () => current.value?.role === 'owner' || current.value?.role === 'admin',
  )

  async function loadStores(): Promise<StoreSummary[]> {
    const me = await sessionService.me()
    stores.value = me.stores
    return me.stores
  }

  async function loadCurrent(): Promise<CurrentStore | null> {
    try {
      current.value = await sessionService.getCurrentStore()
    } catch (caught) {
      if (caught instanceof ApiError && caught.code === 'current_store_unavailable') {
        // The backend cleared the stale reference; treat as "no store selected".
        current.value = null
      } else {
        throw caught
      }
    }
    loaded.value = true
    return current.value
  }

  async function refresh(): Promise<void> {
    loading.value = true
    error.value = null
    try {
      await loadStores()
      await loadCurrent()
    } catch (caught) {
      error.value = caught instanceof ApiError ? caught.message : 'Gagal memuat data toko.'
      throw caught
    } finally {
      loading.value = false
    }
  }

  async function select(storeId: number): Promise<CurrentStore> {
    const store = await sessionService.selectStore(storeId)
    current.value = store
    loaded.value = true
    return store
  }

  function clear(): void {
    current.value = null
    stores.value = []
    loaded.value = false
    error.value = null
  }

  return {
    current,
    stores,
    loading,
    loaded,
    error,
    hasStore,
    inventoryEnabled,
    canManage,
    loadStores,
    loadCurrent,
    refresh,
    select,
    clear,
  }
})
