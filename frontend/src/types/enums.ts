export type BusinessType =
  | 'retail'
  | 'restaurant'
  | 'laundry'
  | 'repair'
  | 'salon'
  | 'other'

export type StoreRole = 'owner' | 'admin' | 'cashier'

/** Universal catalog item type (mirrors `App\Enums\ItemType`). */
export type ItemType = 'product' | 'service' | 'menu' | 'package'

export const ITEM_TYPE_LABELS: Record<ItemType, string> = {
  product: 'Produk',
  service: 'Jasa',
  menu: 'Menu',
  package: 'Paket',
}

export const ITEM_TYPE_VALUES: ItemType[] = ['product', 'service', 'menu', 'package']

export function itemTypeLabel(type: ItemType | null | undefined): string {
  return type ? ITEM_TYPE_LABELS[type] : '—'
}


export const BUSINESS_TYPE_LABELS: Record<BusinessType, string> = {
  retail: 'Retail / Toko',
  restaurant: 'Kafe & Restoran',
  laundry: 'Laundry',
  repair: 'Servis & Reparasi',
  salon: 'Salon & Barbershop',
  other: 'Lainnya',
}

export const STORE_ROLE_LABELS: Record<StoreRole, string> = {
  owner: 'Pemilik',
  admin: 'Admin',
  cashier: 'Kasir',
}

/** Business types whose store supports the inventory module. */
export const INVENTORY_BUSINESS_TYPES: BusinessType[] = ['retail', 'restaurant']

export function usesInventory(type: BusinessType | null | undefined): boolean {
  return type !== null && type !== undefined && INVENTORY_BUSINESS_TYPES.includes(type)
}

export function businessTypeLabel(type: BusinessType | null | undefined): string {
  return type ? BUSINESS_TYPE_LABELS[type] : '—'
}

export function storeRoleLabel(role: StoreRole | null | undefined): string {
  return role ? STORE_ROLE_LABELS[role] : '—'
}
