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

**Selesai (Phase 1a & 1b).**

### Category API
- [x] Migrasi `categories` (store_id, name, description, is_active) + unique `(store_id, name)`.
- [x] Model `Category` + relasi `store()` & `items()`.
- [x] `CategoryController`: index/store/show/update/destroy.
- [x] Validasi: `store_id` dari token; hapus kategori dipakai item → 409.
- [x] Policy `CategoryPolicy` (owner/admin boleh, cashier tidak).
- [x] Feature test isolasi tenant + validasi.

### Catalog Item API (`items`)
- [x] Migrasi `items` + `ItemType` enum.
- [x] Unique `(store_id, sku)` & `(store_id, barcode)`.
- [x] Model `Item` + relasi.
- [x] `ItemController`: CRUD + filter (type, category, is_active) + search.
- [x] Validasi kategori satu toko; SKU/barcode opsional; harga integer.
- [x] Integrasi kuota `max_products` + strategi lock (race MySQL belum diuji).
- [x] Policy `ItemPolicy`.
- [x] Feature test: CRUD, isolasi, unique per toko, kuota, validasi tipe.

Exit criteria: `/api/categories` & `/api/items` stabil, terisolasi, teruji.

---

## Phase 2 — Customers, Orders, Payments

**Selesai.** Tanpa gateway/refund/stok.

### Customers
- [x] Migrasi `customers` (soft delete), model, API CRUD, policy, test.
- [x] Tanpa unique phone/email (kebijakan duplikasi terdokumentasi).

### Orders & Order Items
- [x] Migrasi `orders` + `order_items` (snapshot).
- [x] Enum `FulfillmentStatus`, `PaymentStatus`.
- [x] `OrderController` (create/get/list/fulfillment) — **tanpa** gateway.
- [x] Nomor order unik per toko via `store_sequences` + `lockForUpdate`
      (test konkurensi MySQL nyata belum ada).
- [x] Perhitungan total server-side (harga/total client diabaikan).
- [x] Snapshot nama/sku/tipe/unit/harga item.

### Payments
- [x] Migrasi `payments` + `order_status_histories` (`refunds` belum).
- [x] Enum `PaymentMethod`, `PaymentRecordStatus`.
- [x] Endpoint catat pembayaran + void; recompute `paid_amount` & `payment_status`.
- [x] Multi-metode & pembayaran sebagian; overpayment ditolak.
- [ ] Kembalian tunai/`change_due` belum dimodelkan.
- [ ] Refund belum diimplementasikan (status `refunded` reserved).
- [x] Feature test: unpaid, partial, paid, overpay, void, isolasi tenant.

Exit criteria: siklus transaksi lengkap tanpa gateway, audit status tercatat.

---

## Phase 2.1 — Audit, Concurrency & Hardening

**Selesai.** Audit database/foreign key, perhitungan order, nomor order, dan
payment/void.

- [x] Audit migration order, FK, indeks, strategi delete (read-only).
- [x] Guard overflow/precision uang di `OrderService` (batas 9e15, perkalian
      integer eksak untuk kuantitas bulat).
- [x] `OrderNumberService` membaca nilai counter terpersist (mulai `0001`).
- [x] Harness concurrency MySQL nyata (`tests/Concurrency/`) — 4 skenario
      **PASS** pada MySQL 8.4.3: nomor order, overpayment, void race, kuota item.
- [x] Dokumentasi `docs/concurrency-testing.md`.
- [x] Test regresi overflow & sekuens nomor order.
- [ ] Penerapan migrasi ke DB development (menunggu persetujuan pengguna).
- [ ] Batasan: harness 1 mesin/multi-proses; belum uji terdistribusi.

---

## Phase 3 — Cash Sessions & Reports

**Phase 3A (Cash Sessions) selesai. Reports belum dikerjakan.**

- [x] Migrasi `cash_sessions` + `cash_movements`; buka/tutup sesi; rekap kas.
- [x] Hubungkan `payments.cash_session_id` (tunai → shift kasir).
- [x] Jaminan satu shift terbuka per kasir/toko (unique `open_guard`, race-safe).
- [x] Concurrency MySQL: open-shift race (5 skenario harness PASS).
- [x] Test rekonsiliasi & isolasi (`tests/Feature/CashSessionTest.php`).
- [ ] **Phase 3B — Laporan penjualan** (harian/periode, per metode, per kasir).
- [ ] Migrasi baru belum diterapkan ke DB development (menunggu review).

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
