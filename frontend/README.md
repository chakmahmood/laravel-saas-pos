# SaaS POS — Admin Panel (Frontend)

Web admin panel untuk SaaS POS Universal. Vue 3 + TypeScript + Vite + Tailwind
CSS + Pinia + Vue Router. Terpisah dari backend Laravel (tidak mengganggu
struktur Laravel).

> Status: **FE Checkpoint 3 — Team Management**. Login, pemilihan toko,
> navigasi, route guard, dashboard shell, API client, modul Produk & Kategori,
> serta manajemen Tim (admin/kasir) dan alur wajib ganti kata sandi sudah
> terhubung ke API nyata. Modul lain belum dibangun.

## Stack

| Bagian | Teknologi |
|--------|-----------|
| Framework | Vue 3 (Composition API, `<script setup>`) |
| Bahasa | TypeScript |
| Build | Vite 8 |
| Styling | Tailwind CSS v4 (`@tailwindcss/vite`, CSS-first `@theme`) |
| State | Pinia |
| Routing | Vue Router 4 |
| HTTP | Axios (client terpusat) |
| Test | Vitest + @vue/test-utils + happy-dom |
| Lint | ESLint (flat config) + typescript-eslint + eslint-plugin-vue |

## Prasyarat

- Node.js 22 LTS atau lebih baru
- npm 10+ (atau pnpm/yarn)
- Backend Laravel berjalan (lihat `../docs/production-deployment-checklist.md`)

## Menjalankan di Windows (PowerShell)

```powershell
# 1. Masuk folder frontend
cd F:\workspace\saas-pos\frontend

# 2. Install dependency
npm install

# 3. Siapkan environment
Copy-Item .env.example .env
# Edit .env bila API tidak berjalan di http://localhost:8000

# 4. Jalankan dev server (http://localhost:5173)
npm run dev
```

Backend harus berjalan (mis. `php artisan serve`) dan **CORS/URL** harus
mengizinkan origin FE. Lihat §CORS.

## Scripts

| Perintah | Fungsi |
|----------|--------|
| `npm run dev` | Dev server (Vite) |
| `npm run build` | Typecheck (`vue-tsc`) + production build ke `dist/` |
| `npm run preview` | Preview hasil build |
| `npm run typecheck` | `vue-tsc --noEmit` |
| `npm run lint` | ESLint (`--max-warnings 0`) |
| `npm run test` | Unit/component test (Vitest, sekali jalan) |
| `npm run test:watch` | Vitest watch |

## Environment

Hanya variabel berawalan `VITE_` yang masuk ke bundle browser. **Jangan** taruh
secret di sini.

| Variabel | Contoh | Keterangan |
|----------|--------|------------|
| `VITE_API_BASE_URL` | `http://localhost:8000/api` | URL root API (termasuk `/api`) |
| `VITE_APP_NAME` | `SaaS POS` | Nama aplikasi (opsional) |

## Konfigurasi API

- Client terpusat: `src/lib/http.ts` (Axios). Base URL dari `VITE_API_BASE_URL`
  (fallback `/api` untuk same-origin/reverse-proxy). Tidak ada host produksi
  yang di-hardcode.
- Token Sanctum dikirim sebagai `Authorization: Bearer <token>`.
- Error dinormalisasi ke `ApiError` (`src/lib/errors.ts`) dan dipetakan ke pesan
  ramah pengguna. `401` memicu pembersihan sesi + redirect ke login; `403`
  ditampilkan sebagai "akses ditolak" (tanpa logout); `422` menampilkan error
  validasi; `429` pesan rate limit; `5xx` pesan umum tanpa detail internal.
- Mutasi tidak pernah di-retry otomatis (menghindari efek ganda).

## Autentikasi & penyimpanan token

Backend memakai **Sanctum bearer token** (bukan mode cookie SPA). Token opaque
disimpan di `localStorage` (`src/lib/token.ts`) agar sesi bertahan saat reload.

**Evaluasi keamanan (Checkpoint 1):** karena bearer token harus ditempelkan
JavaScript, semua opsi persisten yang bisa dibaca JS sama-sama rentan XSS.
Mitigasi: tidak merender HTML tak tepercaya, CSP ketat saat deploy, dan token
dapat dicabut server-side via logout. Opsi jangka panjang yang lebih aman adalah
Sanctum SPA cookie mode (httpOnly) — **memerlukan perubahan backend** dan belum
diimplementasikan. Lihat `../docs/store-lifecycle.md` dan laporan checkpoint.

## Struktur

```
src/
  app/          # konfigurasi app (navigasi)
  assets/       # Tailwind + CSS tema
  components/
    layout/     # AppSidebar, AppTopbar, AppBreadcrumb
    ui/         # Button, Input, PasswordInput, Select, Textarea, CurrencyInput,
                # Card, Badge, Checkbox, Dropdown, Modal, ConfirmDialog,
                # Pagination, Skeleton, EmptyState, ErrorState, Toast, Spinner, Icon
  features/
    auth/       # LoginForm, RegisterForm
    products/   # api, types, ProductFormModal
    categories/ # api, types, CategoryFormModal
    team/       # api, types, errors, MemberFormModal
  layouts/      # AdminLayout, AuthLayout
  lib/          # env, http, errors, token, format, debounce, query
  pages/        # Login, Register, StoreSelect, ChangePassword, Dashboard,
                # Products, Categories, Team, ModulePlaceholder, 404
  router/       # routes, guards
  services/     # pemanggilan API (session)
  stores/       # Pinia: auth, currentStore, toast
  types/        # tipe API, model, enum
```

## Route

| Path | Nama | Akses |
|------|------|-------|
| `/login` | login | publik (guest only) |
| `/register` | register | publik (guest only) |
| `/select-store` | select-store | butuh autentikasi |
| `/ganti-password` | change-password | autentikasi (dikecualikan dari guard wajib ganti sandi) |
| `/dashboard` | dashboard | autentikasi + current store |
| `/produk` | products | tersedia — daftar/tambah/ubah/hapus produk |
| `/kategori` | categories | tersedia — daftar/tambah/ubah/hapus kategori |
| `/tim` | team | owner/admin — daftar & kelola anggota toko |
| `/pelanggan`, `/transaksi`, `/pembayaran`, `/sesi-kas`, `/inventory`, `/pengaturan` | modul | placeholder "tersedia pada tahap berikutnya" |

Menu Inventory hanya tampil untuk store yang mendukung inventory (retail,
restoran). Penyembunyian menu **bukan** kontrol keamanan; backend tetap
memverifikasi.

## Modul tersedia

### Produk (`/produk`)
- Daftar dengan pencarian (nama/SKU/barcode), filter tipe, kategori, status, dan
  pagination server-side.
- Tambah/ubah melalui modal; hapus dengan konfirmasi.
- Field sesuai kontrak backend: nama, tipe, kategori, satuan, harga jual, harga
  pokok (opsional), SKU, barcode, deskripsi, aktif, dan `tracks_stock` (hanya
  untuk store inventory).
- Harga diinput/ditampilkan sebagai rupiah (`Rp10.000`) tetapi dikirim sebagai
  integer (`10000`).

### Kategori (`/kategori`)
- Daftar dengan pencarian + filter status + pagination.
- Tambah/ubah melalui modal; hapus dengan konfirmasi.
- Menghapus kategori yang masih dipakai produk ditolak backend → 409
  `category_in_use` ditampilkan sebagai notifikasi, bukan daftar kosong.

### Tim (`/tim`)
- Daftar anggota toko dengan pencarian + filter role/status + pagination
  server-side; badge role (Pemilik/Admin/Kasir) dan status (Aktif/Nonaktif).
- **Owner**: membuat satu akun admin (jika slot kosong), membuat kasir,
  mengubah role admin↔kasir, serta mengaktifkan/menonaktifkan admin & kasir.
- **Admin**: melihat anggota, membuat kasir, mengaktifkan/menonaktifkan kasir.
- **Kasir**: tidak memiliki akses kelola anggota (menu disembunyikan dan rute
  dijaga).
- Akun karyawan dibuat **langsung** (tanpa undangan email). Tombol yang tidak
  punya endpoint backend tidak ditampilkan. Aksi nonaktif/aktif dikonfirmasi;
  kegagalan server tidak mengubah baris secara optimistis.
- Pemetaan error: `409 admin_limit_reached` (admin aktif sudah ada),
  `409 owner_protected` (owner tidak dapat diubah),
  `409 email_already_registered` (email sudah dipakai), `422` per-field.

### Wajib ganti kata sandi awal (`/ganti-password`)
- Endpoint bisnis yang menolak dengan `403 password_change_required` menandai
  sesi wajib ganti sandi, lalu mengarahkan ke halaman ini **tanpa logout**.
- Route guard mencegah kembali ke halaman bisnis sampai selesai (tanpa redirect
  loop; `/ganti-password` dan pemilihan toko dikecualikan).
- Form: kata sandi saat ini, kata sandi baru (min. 8 karakter), konfirmasi.
  Mengirim persis kontrak `ChangePasswordRequest`. Kata sandi tidak pernah
  disimpan ke storage/log; setelah sukses diarahkan ke dashboard/pemilihan toko.

## CORS / Sanctum (integrasi)

Untuk mengembangkan dari `http://localhost:5173` ke API Laravel:

1. Tambahkan origin FE ke CORS backend (mis. `config/cors.php` `allowed_origins`)
   atau gunakan reverse proxy.
2. Karena memakai bearer token (bukan cookie SPA), `SANCTUM_STATEFUL_DOMAINS`
   tidak wajib. Bila beralih ke cookie SPA, konfigurasi tersebut diperlukan.
3. Backend endpoint mengembalikan JSON; kirim header `Accept: application/json`.

## Belum termasuk (setelah FE Checkpoint 3)

- CRUD pelanggan, transaksi, pembayaran, sesi kas, inventory.
- Modul laporan/analitik (endpoint backend belum tersedia).
- Area Super Admin platform (backend belum mendukung).
- Mode terang (light theme) — fokus pada satu dark theme premium.

## Dokumentasi terkait

- `../docs/api/openapi.yaml` — spesifikasi API (58 operasi).
- `../docs/api/frontend-integration-guide.md` — kontrak & matriks permission.
- `../docs/store-lifecycle.md` — kebijakan lifecycle store.
- `../docs/production-deployment-checklist.md` — checklist deploy.
