import type { RouteRecordRaw } from 'vue-router'

const AdminLayout = () => import('@/layouts/AdminLayout.vue')
const ModulePlaceholderPage = () => import('@/pages/ModulePlaceholderPage.vue')

export const routes: RouteRecordRaw[] = [
  {
    path: '/login',
    name: 'login',
    component: () => import('@/pages/LoginPage.vue'),
    meta: { title: 'Masuk', guestOnly: true },
  },
  {
    path: '/register',
    name: 'register',
    component: () => import('@/pages/RegisterPage.vue'),
    meta: { title: 'Daftar', guestOnly: true },
  },
  {
    path: '/select-store',
    name: 'select-store',
    component: () => import('@/pages/StoreSelectPage.vue'),
    meta: { title: 'Pilih Toko', requiresAuth: true },
  },
  {
    path: '/',
    component: AdminLayout,
    meta: { requiresAuth: true, requiresStore: true },
    children: [
      { path: '', redirect: { name: 'dashboard' } },
      {
        path: 'dashboard',
        name: 'dashboard',
        component: () => import('@/pages/DashboardPage.vue'),
        meta: { title: 'Dasbor' },
      },
      {
        path: 'produk',
        name: 'products',
        component: ModulePlaceholderPage,
        meta: {
          title: 'Produk',
          moduleTitle: 'Produk',
          moduleDescription: 'Kelola katalog produk, jasa, menu, dan paket.',
          moduleStage: 'Tahap 2',
        },
      },
      {
        path: 'kategori',
        name: 'categories',
        component: ModulePlaceholderPage,
        meta: {
          title: 'Kategori',
          moduleTitle: 'Kategori',
          moduleDescription: 'Kelompokkan produk untuk memudahkan pencarian.',
          moduleStage: 'Tahap 2',
        },
      },
      {
        path: 'pelanggan',
        name: 'customers',
        component: ModulePlaceholderPage,
        meta: {
          title: 'Pelanggan',
          moduleTitle: 'Pelanggan',
          moduleDescription: 'Data pelanggan dan riwayat transaksi.',
          moduleStage: 'Tahap 2',
        },
      },
      {
        path: 'transaksi',
        name: 'orders',
        component: ModulePlaceholderPage,
        meta: {
          title: 'Transaksi',
          moduleTitle: 'Transaksi',
          moduleDescription: 'Buat dan kelola order penjualan.',
          moduleStage: 'Tahap 2',
        },
      },
      {
        path: 'pembayaran',
        name: 'payments',
        component: ModulePlaceholderPage,
        meta: {
          title: 'Pembayaran',
          moduleTitle: 'Pembayaran',
          moduleDescription: 'Catat dan tinjau pembayaran order.',
          moduleStage: 'Tahap 2',
        },
      },
      {
        path: 'sesi-kas',
        name: 'cash-sessions',
        component: ModulePlaceholderPage,
        meta: {
          title: 'Sesi Kas',
          moduleTitle: 'Sesi Kas',
          moduleDescription: 'Buka/tutup shift kasir dan rekonsiliasi kas.',
          moduleStage: 'Tahap 2',
        },
      },
      {
        path: 'inventory',
        name: 'inventory',
        component: ModulePlaceholderPage,
        meta: {
          title: 'Inventory',
          moduleTitle: 'Inventory',
          moduleDescription: 'Lokasi, saldo, ledger, dan stok masuk.',
          moduleStage: 'Tahap 3',
        },
      },
      {
        path: 'pengaturan',
        name: 'settings',
        component: ModulePlaceholderPage,
        meta: {
          title: 'Pengaturan',
          moduleTitle: 'Pengaturan',
          moduleDescription: 'Profil toko dan preferensi operasional.',
          moduleStage: 'Tahap 2',
        },
      },
    ],
  },
  {
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: () => import('@/pages/NotFoundPage.vue'),
    meta: { title: 'Tidak ditemukan' },
  },
]
