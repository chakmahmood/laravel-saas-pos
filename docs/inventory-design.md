# Inventory & Stock Management — Design

> Status: **Checkpoint 3 (Inventory API, Provisioning & Data Integrity)**.
> Checkpoint 1 (fondasi DB/model/enum/relasi) + Checkpoint 2 (ledger service,
> reservasi/komit/pelepasan terintegrasi `OrderService`) **plus** endpoint
> inventory read/kelola lokasi, provisioning lokasi default pada registrasi
> store, dan proteksi delete item/lokasi.
> Balance dan movement **read-only**. Stock receipt, opening stock, adjustment,
> transfer, dan return **belum tersedia**.
> Dokumen ini menjelaskan apa yang **sudah** ada dan apa yang **sengaja ditunda**.
>
> API: `docs/inventory-api.md`.
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
   sehingga `StockLedgerService` memvalidasi konsistensi tenant (item/lokasi
   selalu diambil lewat relasi store; saldo di-query dengan filter `store_id`).
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
- FK tidak menjamin `item`, `store`, dan `location` satu tenant. `StockLedgerService`
  memvalidasi:
  - item order diambil lewat `$store->items()` (item tenant lain → ditolak
    dengan `stock_tenant_mismatch`);
  - lokasi default diambil lewat `$store->stockLocations()`;
  - saldo di-query dengan filter `store_id` + `stock_location_id` + `item_id`.
- Record tenant lain diperlakukan seperti tidak ada (tidak pernah dipakai).

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

## 7. Provisioning Lokasi Default

**Pilihan: service provisioning + artisan command** (bukan migration/seeder).

Implementasi:
- `App\Services\StockLocationProvisioner::ensureDefaultForStore(Store)`:
  - Menjalankan transaksi dan **mengunci baris store** (`lockForUpdate`) lebih
    dulu, sehingga provisioning per store terserialisasi (lock order kanonik:
    store dulu). Ini menghilangkan race duplicate-insert.
  - Idempotent; unique `default_guard` tetap menjadi backstop database.
  - Bila unique violation tetap terjadi (pemanggil melewatkan lock), recovery
    memakai **locking read** (`FOR UPDATE`) karena MySQL default REPEATABLE READ
    tidak melihat baris yang commit setelah snapshot.
  - `DB::transaction(..., 3)` untuk retry deadlock sementara.
- `provisionMissingLocations()` — memproses hanya store yang **mendukung
  inventory** (`BusinessType::usesInventory`) dan belum punya default (chunked).
- `php artisan stock:provision-locations` — command tipis yang memanggilnya.
- **Registrasi store** (`AuthController::register`) memanggil
  `ensureDefaultForStore` **di dalam transaksi registrasi** bila store mendukung
  inventory. Store non-inventory **tidak** mendapat lokasi inventory.

Sifat:
- Idempotent; dua proses bersamaan menghasilkan tepat satu default
  (dibuktikan pada harness MySQL skenario 8).
- Tidak mengubah histori order; tidak mengaktifkan `tracks_stock`; tidak membuat
  saldo palsu.
- **Belum dijalankan** terhadap development database (menunggu persetujuan).

---

## 8. Integrasi Order (Checkpoint 2)

`StockLedgerService` adalah satu-satunya jalur tulis inventory order. Ia dipanggil
dari `OrderService` **di dalam transaksi yang sama** dengan perubahan order.

### 8.1 Gating kapabilitas

Inventory hanya memproses item bila:
- `store.business_type->usesInventory()` benar **dan** `items.tracks_stock` benar.

Aturan gating berada di `StockLedgerService` (tidak tersebar). Jika order berisi
item `tracks_stock = true` pada store yang **tidak** mendukung inventory, order
ditolak dengan 409 `inventory_not_supported`. Item `tracks_stock = false` tidak
menghasilkan movement dan tidak membutuhkan saldo.

### 8.2 Pemilihan lokasi

- Untuk order dengan item ber-stok, lokasi fulfillment = lokasi **default aktif**
  milik store (`is_default = true`, `is_active = true`), disimpan ke
  `orders.stock_location_id`.
- Lokasi **tidak** pernah diambil dari input client dan **tidak** dibuat
  otomatis saat checkout. Bila tidak ada, order gagal 409
  `stock_location_unavailable`.
- Order tanpa item ber-stok: `stock_location_id` tetap `NULL`.

### 8.3 Reservasi (order dibuat)

- Transaksi order yang sama: item ber-stok diagregasi per item, saldo dikunci
  (`lockForUpdate` urut `(location_id, item_id)`), ketersediaan
  (`quantity_on_hand - quantity_reserved >= diminta`) diperiksa lewat
  **conditional atomic UPDATE** dan jumlah baris terpengaruh diverifikasi.
- `quantity_reserved` bertambah; `quantity_on_hand` **tidak** berubah.
- Satu movement `reservation` per order item (kunci idempotensi per order item).
- Jika satu item saja tidak cukup, seluruh transaksi rollback: order, order
  items, saldo, dan movement tidak tersisa. Error 409 `insufficient_stock`.

### 8.4 Komit (fulfillment `completed`)

- Memproses **hanya** reservasi yang benar-benar ada (dari ledger
  `reservation`), bukan dari status `tracks_stock` item saat ini.
- Saldo dikunci; `quantity_on_hand` dan `quantity_reserved` masing-masing
  dikurangi (conditional, diverifikasi).
- Ledger mencatat `sale_out` **dan** `reservation_release` per order item.
- `orders.stock_committed_at` diisi tepat sekali setelah semua komit berhasil.
- Idempotent: order yang sudah komit (`stock_committed_at` terisi) menjadi
  no-op; retry tidak mengurangi saldo dua kali.

### 8.5 Pembatalan sebelum komit

- Reservasi yang ada dilepas: `quantity_reserved` berkurang, `quantity_on_hand`
  **tidak** berubah.
- Ledger mencatat `reservation_release` per order item (kunci `cancel_release`).
- Order tanpa reservasi → no-op. Pembatalan berulang → no-op (idempotent).
- `completed` tetap final; tidak ada pembatalan setelah komit atau retur
  otomatis pada checkpoint ini.

### 8.6 Pembayaran tidak menyentuh stok

Reservasi/komit **tidak** terikat pada `payment_status`, pembuatan payment, void
payment, atau cash session. Void payment tidak menghasilkan movement inventory.

### 8.7 Idempotency key

Deterministik per order item & peristiwa, unik per store:

| Peristiwa | Key |
|-----------|-----|
| Reservasi | `order:{id}:item:{orderItemId}:reserve` |
| Komit penjualan | `order:{id}:item:{orderItemId}:sale` |
| Pelepasan saat komit | `order:{id}:item:{orderItemId}:commit_release` |
| Pelepasan saat batal | `order:{id}:item:{orderItemId}:cancel_release` |

Guard primer = baris order yang dikunci + `stock_committed_at` (komit) atau
keberadaan movement pelepasan (batal). Unique `(store_id, idempotency_key)`
adalah lapisan kedua; pelanggarannya membatalkan transaksi (bukan diam-diam
dianggap sukses), sehingga retry identik dan konflik data sesungguhnya
dibedakan.

### 8.8 Locking & rollback

- Urutan kunci kanonik: **store → order → stock_balances (location_id ASC,
  item_id ASC)**. Order creation sudah mengunci baris store; komit/pelepasan
  mengunci baris order lalu saldo; transfer (nanti) mengunci saldo urut id.
- Setiap perubahan saldo memakai conditional atomic UPDATE + pemeriksaan
  affected rows; tidak ada read-modify-write tanpa proteksi.
- Semua perubahan saldo + movement + timestamp order berada dalam satu
  transaksi; kegagalan apa pun me-rollback seluruhnya.
- Aritmetika kuantitas memakai integer milli (`App\Support\Quantity`), bukan
  float, untuk menghindari drift desimal.

---

## 9. Inventory API (Checkpoint 3)

Semua endpoint berada di bawah `auth:sanctum` + `current.store` + gating
`EnsureInventoryEnabled` (`BusinessType::usesInventory`). Detail lengkap:
`docs/inventory-api.md`.

- **Lokasi** (`/api/stock/locations`): list/create/show/update/delete.
  Owner/admin kelola; cashier baca. `store_id`, `is_default`, `default_guard`
  tidak pernah diambil dari input. Lokasi tenant lain → 404.
- **Saldo** (`/api/stock/balances`, `/api/items/{item}/stock`): **read-only**.
  Hanya saldo item `tracks_stock = true`; `quantity_available` dihitung eksak
  (tanpa float). GET tidak pernah membuat baris saldo. Item non-tracked pada
  `/items/{item}/stock` → 409 `inventory_item_not_tracked`.
- **Ledger** (`/api/stock/movements`): **read-only**, filter item/lokasi/tipe/
  rentang waktu/order, paginasi, eager loading. Tidak ada endpoint mutasi.

### 9.1 Proteksi delete

- **Item** dengan saldo atau movement → 409 `item_has_stock_history` (FK
  RESTRICT sebagai backstop; tidak ada cascade ke ledger). Item tanpa histori
  tetap dapat dihapus; menghentikan penggunaan item = nonaktifkan.
- **Lokasi** default aktif → 409 `default_stock_location_protected`.
  Lokasi dengan saldo/movement atau direferensikan order → 409
  `stock_location_in_use`. Validasi domain dijalankan sebelum delete; FK adalah
  backstop.

### 9.2 Gating capability

Middleware `EnsureInventoryEnabled` mengembalikan 403 `inventory_not_available`
untuk store non-inventory pada **semua** endpoint inventory (termasuk read-only).

---

## 10. Keputusan Final

- Ledger + saldo + lokasi dengan skema Checkpoint 1.
- `tracks_stock` default `false`; order non-inventory tidak berubah perilaku.
- `available = on_hand - reserved`.
- Order membuat reservasi; `completed` mengommit; `cancelled` (sebelum komit)
  melepas; void pembayaran tidak mengubah stok. **Terimplementasi (Checkpoint 2).**
- Lokasi default per store dijamin `default_guard`; provisioning idempotent dan
  terhubung ke registrasi store.
- Tanpa DB CHECK untuk saldo (alasan portabilitas di §11).

---

## 11. Mengapa Tanpa Constraint CHECK Saldo di Database

- `Illuminate\Database\Schema\Blueprint` tidak punya API `check` portabel.
- SQLite tidak mendukung `ALTER TABLE ... ADD CONSTRAINT`; CHECK hanya bisa
  didefinisikan saat CREATE TABLE.
- Menulis DDL mentah per-driver membuat skema MySQL produksi berbeda dari skema
  SQLite test, sehingga constraint tidak teruji.
- Karena itu non-negativitas (`quantity_on_hand >= 0`,
  `quantity_reserved >= 0`, `quantity_reserved <= quantity_on_hand`) ditegakkan
  di **service layer**, dilindungi conditional UPDATE + pemeriksaan affected
  rows, dan diuji di suite.

---

## 12. Ditunda (Belum Diimplementasikan)

- Stock receipt / opening stock / adjustment (satu-satunya cara saat ini mengisi
  saldo awal adalah factory/seeder/DB; belum ada API).
- Transfer antarlokasi dan return.
- Multi-satuan (`item_units`), BOM/resep, konsumsi bahan baku restoran.
- Refund/retur otomatis (memerlukan aturan transaksi yang jelas).
- Konfigurasi `items.tracks_stock` melalui API item (saat ini di-set via
  factory/DB), sehingga **retail end-to-end belum siap** tanpa stock-in.
- Reconciler saldo-dari-ledger.

---

## 13. Batasan

- **SQLite tidak mendukung `lockForUpdate`.** Suite SQLite memverifikasi
  protokol, transaksi, dan rollback, bukan isolasi paralel sebenarnya.
  Isolasi konkuren dibuktikan pada **harness MySQL** (`tests/Concurrency/`),
  skenario 6 (berebut unit terakhir), 7 (komit ganda), & 8 (provisioning
  bersamaan), di database khusus `saas_pos_concurrency_test` (terpisah dari
  development).
- Migrasi inventory **belum diterapkan** ke development database (menunggu
  persetujuan).
- `commitForOrder`/`releaseForOrder`/`reserveForOrder` adalah jalur tulis utama;
  mengubah saldo langsung lewat model melewati jaminan di atas.

