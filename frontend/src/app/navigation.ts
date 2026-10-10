import type { IconName } from '@/components/ui/icons'

export interface NavItem {
  /** Route name (must exist in the router). */
  name: string
  label: string
  icon: IconName
  /** Only shown for stores that support inventory. */
  inventoryOnly?: boolean
  /** Only shown to owners/admins (the route is guarded as well). */
  managerOnly?: boolean
}

export interface NavSection {
  label: string
  items: NavItem[]
}

export const NAV_SECTIONS: NavSection[] = [
  {
    label: 'Utama',
    items: [{ name: 'dashboard', label: 'Dasbor', icon: 'dashboard' }],
  },
  {
    label: 'Katalog & Pelanggan',
    items: [
      { name: 'products', label: 'Produk', icon: 'box' },
      { name: 'categories', label: 'Kategori', icon: 'tag' },
      { name: 'customers', label: 'Pelanggan', icon: 'users' },
    ],
  },
  {
    label: 'Penjualan',
    items: [
      { name: 'orders', label: 'Kasir (POS)', icon: 'receipt' },
      { name: 'order-history', label: 'Riwayat Transaksi', icon: 'history' },
      { name: 'payments', label: 'Pembayaran', icon: 'card' },
      { name: 'cash-sessions', label: 'Sesi Kas', icon: 'wallet' },
    ],
  },
  {
    label: 'Operasional',
    items: [{ name: 'inventory', label: 'Inventory', icon: 'layers', inventoryOnly: true }],
  },
  {
    label: 'Sistem',
    items: [
      { name: 'team', label: 'Tim', icon: 'users', managerOnly: true },
      { name: 'settings', label: 'Pengaturan', icon: 'settings' },
    ],
  },
]
