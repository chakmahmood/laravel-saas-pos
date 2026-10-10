/**
 * Canonical primary business group of a store (mirrors `App\Enums\BusinessType`).
 *
 * Exactly two groups exist:
 * - `retail`  → "Toko & Penjualan" (warung, toko, kafe, restoran, produk/menu).
 * - `service` → "Jasa & Servis" (laundry, bengkel, salon, reparasi, layanan).
 *
 * Laundry/workshop/salon are workflow templates inside `service`, not separate
 * business types.
 */
export type BusinessType = 'retail' | 'service'

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
  retail: 'Toko & Penjualan',
  service: 'Jasa & Servis',
}

export const BUSINESS_TYPE_DESCRIPTIONS: Record<BusinessType, string> = {
  retail: 'Untuk warung, toko, kafe, restoran, dan usaha penjualan produk atau menu.',
  service: 'Untuk laundry, bengkel, salon, reparasi, dan usaha layanan.',
}

export const BUSINESS_TYPE_VALUES: BusinessType[] = ['retail', 'service']

export const STORE_ROLE_LABELS: Record<StoreRole, string> = {
  owner: 'Pemilik',
  admin: 'Admin',
  cashier: 'Kasir',
}

/** Business groups whose store supports the inventory module. */
export const INVENTORY_BUSINESS_TYPES: BusinessType[] = ['retail']

export function usesInventory(type: BusinessType | null | undefined): boolean {
  return type !== null && type !== undefined && INVENTORY_BUSINESS_TYPES.includes(type)
}

export function businessTypeLabel(type: BusinessType | null | undefined): string {
  return type ? BUSINESS_TYPE_LABELS[type] : '—'
}

/** Catalog label adapts to the active store: "Layanan" for service, else "Produk". */
export function catalogLabel(type: BusinessType | null | undefined): string {
  return type === 'service' ? 'Layanan' : 'Produk'
}

export function storeRoleLabel(role: StoreRole | null | undefined): string {
  return role ? STORE_ROLE_LABELS[role] : '—'
}

/** Membership lifecycle status (mirrors `App\Enums\MembershipStatus`). */
export type MembershipStatus = 'active' | 'inactive'

export const MEMBERSHIP_STATUS_LABELS: Record<MembershipStatus, string> = {
  active: 'Aktif',
  inactive: 'Nonaktif',
}

export function membershipStatusLabel(status: MembershipStatus | null | undefined): string {
  return status ? MEMBERSHIP_STATUS_LABELS[status] : '—'
}
