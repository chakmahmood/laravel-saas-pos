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

## Phase 3 — Cash Sessions, Inventory & Reports

> **Catatan perubahan (2026-10-10):** sebelumnya roadmap menyebut *Phase 3B =
> Laporan penjualan* dan inventory berada di *Phase 4*. Keputusan terbaru
> menetapkan **Phase 3B = Inventory & Stock Management**; laporan penjualan
> **dipindah ke Phase 3C** dan rencananya tetap dipertahankan, tidak dihapus.

### Phase 3A — Cash Sessions

**Selesai. Reports belum dikerjakan.**

- [x] Migrasi `cash_sessions` + `cash_movements`; buka/tutup sesi; rekap kas.
- [x] Hubungkan `payments.cash_session_id` (tunai → shift kasir).
- [x] Jaminan satu shift terbuka per kasir/toko (unique `open_guard`, race-safe).
- [x] Concurrency MySQL: open-shift race (5 skenario harness PASS).
- [x] Test rekonsiliasi & isolasi (`tests/Feature/CashSessionTest.php`).
- [ ] Migrasi baru belum diterapkan ke DB development (menunggu review).

### Phase 3B — Inventory & Stock Management

**Checkpoint 1 (Inventory Foundation), Checkpoint 2 (Stock Ledger Service &
Order Integration), Checkpoint 3 (Inventory API, Provisioning & Data Integrity)
& Checkpoint 4 (Stock In, Opening Stock, Adjustment & Item Config) selesai.**
Detail desain: `docs/inventory-design.md`; API: `docs/inventory-api.md`.

- [x] Migrasi `items.tracks_stock` (default false) + index.
- [x] Migrasi `stock_locations` (jaminan portabel satu default per store via
      `default_guard` nullable unique).
- [x] Migrasi `stock_balances` (`quantity_on_hand`, `quantity_reserved`,
      unique per lokasi+item).
- [x] Migrasi `stock_movements` (ledger append-only, idempotency unik per store).
- [x] Enum `StockLocationType` & `StockMovementType` (+ helper arah movement).
- [x] Model `StockLocation`/`StockBalance`/`StockMovement` + relasi Item/Store/
      Order/OrderItem + factory.
- [x] Provisioning lokasi default idempotent + `php artisan stock:provision-locations`.
- [x] Test fondasi (item flag, lokasi, saldo, movement, enum, provisioning).
- [x] Migrasi aditif `orders.stock_location_id` + `orders.stock_committed_at`.
- [x] `StockLedgerService` (reserve/commit/release, conditional atomic update,
      lock order `store → order → balances`).
- [x] Integrasi `OrderService`: reservasi saat create, komit saat `completed`,
      pelepasan saat `cancelled` (sebelum komit).
- [x] Idempotency per order item + guard `stock_committed_at`; tenant & business
      type divalidasi di service.
- [x] Test integrasi order (reservasi, komit, batal, rollback, tenant,
      idempotency, decimal 1.125, void payment tidak mengubah stok).
- [x] Concurrency MySQL skenario 6 (berebut unit terakhir) & 7 (komit ganda)
      pada DB khusus `saas_pos_concurrency_test` — PASS.
- [x] Provisioning lokasi default terhubung ke registrasi store (inventory
      store saja); command hanya memproses store inventory-capable; race
      dihilangkan via lock baris store + harness skenario 8 — PASS.
- [x] API lokasi stok (CRUD) dengan policy owner/admin kelola, cashier baca.
- [x] API saldo stok **read-only** (`/stock/balances`, `/items/{item}/stock`).
- [x] API ledger movement **read-only** (`/stock/movements`).
- [x] Gating `EnsureInventoryEnabled` (403 `inventory_not_available`).
- [x] Proteksi delete item (`item_has_stock_history`) & lokasi
      (`default_stock_location_protected`, `stock_location_in_use`).
- [x] Test fitur API + isolasi tenant + provisioning + proteksi delete.
- [x] Konfigurasi `tracks_stock` via API item (default false, gating business
      type, proteksi `item_inventory_in_use` saat ada histori).
- [x] Migrasi aditif `stock_movements.request_fingerprint`.
- [x] `StockLedgerService`: `recordOpening`, `recordReceipt`, `recordAdjustment`
      (idempotent per request, lock `store → balance`, conditional update).
- [x] Endpoint `POST /api/stock/opening-balances`, `/stock/receipts`,
      `/stock/adjustments` (owner/admin).
- [x] Test opening/receipt/adjustment (idempotency, konflik payload, rollback,
      tenant, permission, adjustment di bawah reserved, no-op delta 0).
- [x] Concurrency MySQL skenario 9–12 (opening race, receipt bersamaan,
      adjustment bersamaan, retry idempotent bersamaan) — PASS.
- [x] **Checkpoint 5 (Backend Readiness Audit & Hardening):** audit keamanan,
      tenant isolation, order/payment/cash, inventory, DB, API contract, lock
      ordering; rate limiting auth; kontrak error 403/404 aman. Laporan:
      `docs/backend-readiness-audit.md`. 306 tests / 1274 assertions PASS.
- [x] **Checkpoint 6 (API Contract, Store Lifecycle & FE Readiness):**
      OpenAPI 3.1 (`docs/api/openapi.yaml`, 51 operasi), FE integration guide,
      deployment checklist, kebijakan store lifecycle, standarisasi error
      401/422/429, legacy Pint dibersihkan. 313 tests / 1296 assertions PASS.
- [ ] **Checkpoint berikutnya:** FE Admin Panel + transfer antarlokasi, retur,
      laporan stok.
- [ ] Ditunda: purchase order, supplier, multi-satuan, BOM/resep, konsumsi bahan
      baku, refund/retur otomatis.
- [ ] Keputusan store lifecycle: akses baca histori store nonaktif — lihat
      `docs/store-lifecycle.md` §5.
- [ ] Migrasi inventory belum diterapkan ke DB development (menunggu persetujuan).
- [ ] Catatan: retail kini dapat mencatat stok via opening/receipt.

### Phase 3C — Laporan Penjualan

*(Dipindah dari Phase 3B sebelumnya.)*

- [ ] Laporan harian/periode, per metode pembayaran, per kasir.
- [ ] (Laporan stok menyusul setelah integrasi inventory.)

---

## Frontend Admin Panel (jalur paralel)

Web admin panel terpisah di `frontend/` (Vue 3 + TypeScript + Vite + Tailwind +
Pinia + Vue Router). Tidak mengganggu struktur Laravel.

- [x] **FE Checkpoint 1 (Foundation):** struktur app, tema dark premium,
      API client terpusat + error handling, autentikasi (login/registrasi),
      pemilihan toko, route guard, layout admin (sidebar/topbar/breadcrumb),
      dashboard shell yang jujur (tanpa angka palsu), komponen UI reusable.
      Typecheck, lint, 22 unit/component test, dan production build lulus.
- [ ] **FE Checkpoint berikutnya:** modul CRUD Produk/Kategori/Pelanggan,
      lalu Transaksi/Pembayaran/Sesi Kas, disusul Inventory.
- [ ] Area Super Admin platform (menunggu dukungan backend).
- [ ] Endpoint analitik/laporan untuk dashboard (menunggu backend).

Lihat `frontend/README.md` dan `docs/api/frontend-integration-guide.md`.

---

## Phase 4 — Modul Industri (prioritas menyesuaikan bisnis)

Dibangun **di atas** `items`/`orders`/`customers`. Pilih sesuai kebutuhan.

### Retail / F&B — Inventory
- [x] Fondasi ledger/saldo/lokasi (`stock_locations`, `stock_balances`,
      `stock_movements`) — Phase 3B Checkpoint 1.
- [ ] Integrasi order (reservasi/komit) — Phase 3B Checkpoint 2.
- [ ] `suppliers`, `purchases`, `purchase_items`, `stock_opnames`, `returns`.
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
