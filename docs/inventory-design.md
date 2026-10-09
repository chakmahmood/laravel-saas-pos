# Inventory & Stock Management — Design

> Status: **Checkpoint 1 (Inventory Foundation)** — fondasi database, model,
> enum, relasi, provisioning, dan test dasar.
> Belum ada endpoint, integrasi order, reservasi, komit stok, adjustment,
> transfer, atau receipt. Dokumen ini menjelaskan apa yang **sudah** ada dan apa
> yang **sengaja ditunda**.
>
> Pendamping: `docs/universal-pos-architecture.md`, `docs/universal-pos-roadmap.md`.

---

## 1. Prinsip

1. **Ledger append-only adalah sumber kebenaran.** Tabel `stock_movements`
   mencatat setiap pergerakan; koreksi dilakukan dengan movement baru (mis.
   `reversal` yang menunjuk movement asal lewat `reversal_of_id`), **bukan**
   dengan mengedit atau menghapus baris.
2. **Saldo adalah proyeksi operasional.** `stock_balances` adalah cache yang
   harus selalu diperbarui bersamaan dengan ledger dalam satu transaksi
   database. Saldo bukan sumber kebenaran dan bisa direkonsiliasi dari ledger.
3. **Saldo memisahkan fisik dan reservasi.**
   `available = quantity_on_hand - quantity_reserved`.
4. **Semua kuantitas `decimal(12,3)`.** Tidak pernah memakai floating point
   untuk perhitungan stok.
5. **Kuantitas movement selalu positif.** Arah ditentukan oleh `type`.
6. **Tenant dari token, bukan request.** Setiap baris inventory punya `store_id`;
   lokasi, item, dan store harus satu tenant. FK saja **tidak** menjamin ini,
   sehingga validasi konsistensi tenant dilakukan di service (checkpoint
   transaksi berikutnya).
7. **Gating ganda.** Stok hanya diproses bila `BusinessType::usesInventory()`
   benar **dan** `items.tracks_stock = true`.

---

## 2. Skema

### 2.1 `items.tracks_stock` (migrasi `2026_10_10_000400`)

| Kolom | Tipe | Catatan |
|-------|------|---------|
| `tracks_stock` | boolean, default `false` | index `(store_id, tracks_stock)` |

- Item lama dan item baru default `false`; **tidak ada** migrasi data yang
  mengaktifkan stok secara otomatis (itu akan membuat saldo palsu untuk item
  yang stoknya tak pernah dilacak).

### 2.2 `stock_locations` (migrasi `2026_10_10_000500`)

| Kolom | Tipe | Catatan |
|-------|------|---------|
| `id` | bigint | |
| `store_id` | FK stores | cascadeOnDelete |
| `name` | string(100) | unique per store |
| `code` | string(30) nullable | unique per store (NULL boleh berulang) |
| `type` | string(20) | enum `StockLocationType`: `warehouse`/`outlet`/`other` |
| `is_default` | boolean, default false | |
| `is_active` | boolean, default true | |
| `default_guard` | string(80) nullable | **unique**; jaminan satu default per store |
| timestamps | | |

Index: `unique(store_id, name)`, `unique(store_id, code)`, `unique(default_guard)`,
`index(store_id, is_active)`.

### 2.3 `stock_balances` (migrasi `2026_10_10_000600`)

| Kolom | Tipe | Catatan |
|-------|------|---------|
| `id` | bigint | |
| `store_id` | FK stores | cascadeOnDelete (denormalisasi untuk query tenant) |
| `stock_location_id` | FK stock_locations | restrictOnDelete |
| `item_id` | FK items | restrictOnDelete |
| `quantity_on_hand` | decimal(12,3), default 0 | stok fisik |
| `quantity_reserved` | decimal(12,3), default 0 | ditahan order belum final |
| timestamps | | |

Unique `(stock_location_id, item_id)`; index `(store_id, item_id)`.

### 2.4 `stock_movements` (migrasi `2026_10_10_000700`)

| Kolom | Tipe | Catatan |
|-------|------|---------|
| `id` | bigint | |
| `store_id` | FK stores | cascadeOnDelete |
| `stock_location_id` | FK stock_locations | restrictOnDelete |
| `item_id` | FK items | restrictOnDelete |
| `type` | string(30) | enum `StockMovementType` |
| `quantity` | decimal(12,3) | selalu positif |
| `unit_cost` | unsignedBigInteger nullable | minor units, seperti `items.cost_price` |
| `order_id` | FK orders nullable | nullOnDelete |
| `order_item_id` | FK order_items nullable | nullOnDelete |
| `reversal_of_id` | FK stock_movements nullable | nullOnDelete (self) |
| `idempotency_key` | string(120) nullable | **unique per store** |
| `note` | string(255) nullable | |
| `created_by` | FK users nullable | nullOnDelete |
| `occurred_at` | timestamp | default CURRENT_TIMESTAMP |
| timestamps | | |

Index: `unique(store_id, idempotency_key)`,
`index(store_id, item_id, occurred_at)`, `index(store_id, stock_location_id)`,
`index(store_id, order_id)`, `index(store_id, type)`.

**Tidak** ada FK ke tabel adjustment/transfer karena tabel tersebut belum
dibuat. Kolomnya akan ditambahkan aditif pada checkpoint terkait.

---

## 3. Enum

### `StockLocationType`
`warehouse`, `outlet`, `other`.

### `StockMovementType`
`opening`, `purchase_in`, `sale_out`, `adjustment_in`, `adjustment_out`,
`transfer_in`, `transfer_out`, `return_in`, `return_out`, `reservation`,
`reservation_release`, `reversal`.

Helper arah pada enum (kontrak untuk ledger service nanti):

| Kelompok | Type | Efek |
|----------|------|------|
| `increasesOnHand()` | opening, purchase_in, adjustment_in, transfer_in, return_in | menambah `quantity_on_hand` |
| `decreasesOnHand()` | sale_out, adjustment_out, transfer_out, return_out | mengurangi `quantity_on_hand` |
| `increasesReserved()` | reservation | menambah `quantity_reserved` |
| `decreasesReserved()` | reservation_release | mengurangi `quantity_reserved` |
| `isReversal()` | reversal | arah = kebalikan movement yang direferensikan |

---

## 4. Aturan Tenant

- Semua tabel inventory memiliki `store_id`; query wajib di-scope ke toko aktif
  (pola eksplisit, bukan global scope — konsisten dengan proyek).
- FK tidak menjamin `item`, `store`, dan `location` satu tenant. Service
  (checkpoint berikutnya) **wajib** memvalidasi:
  - `$store->stockLocations()->findOrFail($locationId)`
  - `$store->items()->findOrFail($itemId)`
- Record tenant lain diperlakukan seperti tidak ada (404).

---

## 5. Idempotency

- `stock_movements.idempotency_key` unik **per store** (`unique(store_id, idempotency_key)`).
- Nilai deterministik per peristiwa, mis. `order:123:reserve`,
  `order_item:456:sale`, `transfer:7:out:42`.
- Retry request atau trigger ganda tidak boleh memindahkan stok dua kali:
  pelanggaran unique ditangkap dan dianggap sudah diproses.
- `NULL` diizinkan (movement manual tanpa key alami); `NULL` distinct di MySQL
  maupun SQLite sehingga tidak bertabrakan.

---

## 6. Jaminan Satu Lokasi Default per Store

- Index unik biasa pada boolean `is_default` **tidak** bisa menjamin ini (banyak
  `false` diperbolehkan); partial index `WHERE is_default` didukung SQLite
  tetapi **tidak** MySQL.
- Solusi portabel: kolom `default_guard` nullable + unique.
  - `default_guard = "<store_id>"` saat `is_default = true`
  - `default_guard = NULL` saat bukan default
  - store id tidak pernah berulang → maksimal satu default per store; `NULL`
    distinct → lokasi non-default bebas.
- Pola ini identik dengan `cash_sessions.open_guard` yang sudah terbukti.
- Kolom dikelola oleh layer provisioning/service, **bukan** dari input client.

---

## 7. Backfill / Provisioning Lokasi Default

**Pilihan: service provisioning + artisan command** (bukan migration/seeder).

Alasan:
- Migrasi di proyek ini murni DDL dan tidak menyentuh data tenant.
- Seeder di sini untuk data referensi global (plans), bukan backfill per tenant.
- Logika provisioning dipakai ulang untuk store baru saat registrasi pada
  checkpoint berikutnya, sekaligus aman dijalankan berulang.

Implementasi:
- `App\Services\StockLocationProvisioner::ensureDefaultForStore(Store)` —
  idempotent; menangani race lewat unique `default_guard` (pihak yang kalah
  mengembalikan baris pemenang).
- `App\Services\StockLocationProvisioner::provisionMissingLocations()` —
  memproses semua store yang belum punya default (chunked).
- `php artisan stock:provision-locations` — command tipis yang memanggilnya.

Sifat:
- Idempotent (dijalankan berulang tidak membuat duplikat).
- Tidak mengubah histori order.
- Tidak mengaktifkan `tracks_stock`.
- Tidak membuat saldo palsu.
- **Belum dijalankan** terhadap development database (menunggu persetujuan).

---

## 8. Keputusan Final (Checkpoint 1)

- Ledger + saldo + lokasi dengan skema di atas.
- `tracks_stock` default `false`.
- Reservasi dipisah dari fisik; `available = on_hand - reserved`.
- Order membuat reservasi, `completed` mengommit, `cancelled` (sebelum komit)
  melepas reservasi; void pembayaran tidak mengubah stok. **(Perilaku ini
  disepakati, implementasinya checkpoint berikutnya.)**
- Lokasi default per store dijamin `default_guard`; provisioning idempotent.
- Tidak ada DB CHECK untuk saldo (alasan portabilitas di §9).

---

## 9. Mengapa Tanpa Constraint CHECK Saldo di Database

- `Illuminate\Database\Schema\Blueprint` tidak punya API `check` portabel.
- SQLite tidak mendukung `ALTER TABLE ... ADD CONSTRAINT`; CHECK hanya bisa
  didefinisikan saat CREATE TABLE.
- Menulis DDL mentah per-driver membuat skema MySQL produksi berbeda dari skema
  SQLite test, sehingga constraint tidak teruji.
- Karena itu non-negativitas (`quantity_on_hand >= 0`,
  `quantity_reserved >= 0`, `quantity_reserved <= quantity_on_hand`) ditegakkan
  di **service layer** pada checkpoint transaksi berikutnya, dan diuji di sana.

---

## 10. Ditunda (Belum Diimplementasikan)

- Service ledger (`StockLedgerService`), reservasi, komit stok, pelepasan.
- Integrasi `OrderService` (reservasi saat create; komit/lepas saat transisi
  fulfillment).
- Kolom `orders.stock_location_id` dan `orders.stock_committed_at`.
- Endpoint/Controller/Policy/Resource inventory.
- Adjustment, transfer, stock receipt, retur.
- Multi-satuan (`item_units`), BOM/resep, konsumsi bahan baku restoran.
- Refund/retur otomatis (memerlukan aturan transaksi yang jelas).

---

## 11. Batasan Checkpoint Ini

- Belum ada cara memindahkan stok selain akses model langsung.
- Belum ada penegakan tenant di level service untuk stok (baru rancangan).
- Belum ada lock (`lockForUpdate`) di mana pun untuk stok; concurrency stok
  akan diuji pada checkpoint service (SQLite tidak mendukung `FOR UPDATE`).
- Migrasi baru **belum diterapkan** ke development database.
- Reconciler saldo-dari-ledger belum ada.
