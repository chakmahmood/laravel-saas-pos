# Universal POS SaaS — Roadmap Implementasi

> Dokumen pendamping `docs/universal-pos-architecture.md`.
> Tandai **Selesai** hanya jika kode + test benar-benar ada.

---

## Prinsip Eksekusi

- Satu fase = satu domain fitur, selalu diakhiri test + dokumentasi.
- Semua endpoint bisnis memakai `auth:sanctum` + `current.store`.
- `store_id` selalu dari `$request->attributes->get('current_store')`.
- Uang = integer minor units; kuantitas = `decimal(12,3)`.
- Tidak ada `migrate:fresh`/`db:wipe` terhadap database development tanpa
  instruksi terpisah dari pengguna.
- Tidak menambah dependency tanpa persetujuan.

---

## Phase 0 — Audit & Fondasi (fase ini)

Tujuan: memahami kondisi aktual, menetapkan model universal, dan menyiapkan
fondasi tanpa membangun fitur POS.

- [x] Audit menyeluruh (kode, migrasi, test, git).
- [x] Konfirmasi baseline test: **24 passed / 77 assertions**.
- [x] Konfirmasi `migrate:status`: 9 migrasi `Ran`.
- [x] Konfirmasi route existing: 6 route `/api`.
- [x] Tetapkan arsitektur katalog universal (`items` + `type`).
- [x] Tetapkan arsitektur transaksi (`orders`/`order_items`/`payments`/`refunds`).
- [x] Tetapkan batas modul umum vs industri.
- [x] Tambah `business_type` (enum + migrasi + factory + test) secara
      backward-compatible.
- [x] Tulis `docs/universal-pos-architecture.md` & roadmap ini.
- [ ] **Persetujuan pengguna** atas keputusan di §13 arsitektur.

Exit criteria Phase 0: test hijau, migrasi baru aman, API existing utuh,
dokumen disetujui.

---

## Phase 1 — Categories & Catalog Items

Bergantung pada persetujuan Phase 0. **Belum dikerjakan.**

### Category API
- [ ] Migrasi `categories` (store_id, parent_id, name, type, sort_order, is_active).
- [ ] Model `Category` + relasi `store()` & `items()`.
- [ ] `CategoryController`: index/store/show/update/destroy.
- [ ] Validasi: `store_id` dari token; kategori induk harus satu toko.
- [ ] Policy `CategoryPolicy` (owner/admin boleh, cashier tidak).
- [ ] Feature test isolasi tenant + validasi.

### Catalog Item API (`items`)
- [ ] Migrasi `items` + `ItemType` enum.
- [ ] Unique `(store_id, sku)` & `(store_id, barcode)`.
- [ ] Model `Item` + relasi.
- [ ] `ItemController`: CRUD + filter (type, category, is_active) + search.
- [ ] Validasi kategori satu toko; SKU/barcode opsional; harga integer.
- [ ] Integrasi kuota `max_products` (definisi §5.7) — **termasuk keputusan
      race condition**.
- [ ] Policy `ItemPolicy`.
- [ ] Feature test: CRUD, isolasi, unique per toko, kuota, validasi tipe.

Exit criteria: `/api/categories` & `/api/items` stabil, terisolasi, teruji.

---

## Phase 2 — Customers, Orders, Payments

**Belum dikerjakan.**

### Customers
- [ ] Migrasi `customers`, model, API CRUD, policy, test.

### Orders & Order Items
- [ ] Migrasi `orders` + `order_items` (snapshot).
- [ ] Enum `OrderStatus`, `PaymentStatus`.
- [ ] `OrderController` (create/get/list/cancel) — **tanpa** payment gateway.
- [ ] Nomor order unik per toko + strategi konkurensi + test.
- [ ] Perhitungan total server-side (jangan percaya total dari client).
- [ ] Simpan snapshot nama/tipe/harga item.

### Payments
- [ ] Migrasi `payments` + `refunds` + `order_status_histories`.
- [ ] Enum `PaymentMethod`, `PaymentRecordStatus`.
- [ ] Endpoint tambah pembayaran; update `paid_total` & `payment_status`.
- [ ] Dukungan tunai (kembalian), non-tunai, partial, multi-metode.
- [ ] Refund & void + audit history.
- [ ] Feature test: unpaid, partial, paid, overpay/kembalian, refund,
      multi-metode, isolasi tenant.

Exit criteria: siklus transaksi lengkap tanpa gateway, audit status tercatat.

---

## Phase 3 — Cash Sessions & Reports

**Belum dikerjakan.**

- [ ] Migrasi `cash_sessions`; buka/tutup sesi; rekap kas.
- [ ] Hubungkan `payments.cash_session_id`.
- [ ] Laporan penjualan (harian/periode, per metode, per kasir).
- [ ] Test rekonsiliasi & isolasi.

---

## Phase 4 — Modul Industri (prioritas menyesuaikan bisnis)

Dibangun **di atas** `items`/`orders`/`customers`. Pilih sesuai kebutuhan.

### Retail / F&B — Inventory
- [ ] `stock_movements`, `suppliers`, `purchases`, `stock_opnames`, `returns`.
- [ ] Stok masuk/keluar terhubung `orders` & pembelian.

### F&B
- [ ] `modifier_groups`/`modifier_options` + pivot item.
- [ ] `dining_tables`, `kitchen_tickets`.
- [ ] (Opsional) resep/ingredient.

### Laundry
- [ ] `laundry_jobs`: berat/kuantitas, satuan, status pengerjaan, estimasi,
      pengambilan.

### Servis/Reparasi
- [ ] `customer_assets`, `repair_jobs`, `repair_job_parts`, status pengerjaan.

### Salon/Barbershop
- [ ] `appointments`, `staff_services`, durasi, komisi.

### Lintas industri
- [ ] `item_variants` (retail/menu).
- [ ] `package_items` (paket/bundle).

---

## Backlog Lintas Fase

- [ ] Policies granular per role (owner/admin/cashier) + matriks final.
- [ ] Model akun (`accounts`) & penegakan `max_stores`.
- [ ] `store_settings` / konfigurasi operasional.
- [ ] Kuota varian/`max_products` lanjutan.
- [ ] Manajemen device/session.
- [ ] Billing & payment gateway langganan.
- [ ] Audit log umum.

---

## Definition of Done (setiap fase)

- [ ] Kode + migrasi backward-compatible.
- [ ] Test feature (termasuk isolasi tenant) hijau.
- [ ] `php artisan test` hijau, tidak ada regresi.
- [ ] `vendor/bin/pint` bersih.
- [ ] `migrate:status` & `route:list` diverifikasi.
- [ ] Dokumentasi diperbarui.
- [ ] `git status` bersih dari file tak sengaja.
