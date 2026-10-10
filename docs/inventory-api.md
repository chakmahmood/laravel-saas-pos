# Inventory API — Locations, Balances, Ledger & Stock-In

> Status: **Tersedia (Checkpoint 4)**.
> Lokasi dapat dikelola; saldo & ledger **read-only**; opening stock, receipt,
> dan adjustment tersedia sebagai mutasi.
> Semua endpoint di bawah `auth:sanctum` + `current.store` + gating inventory.
> Prefix `/api`, tanpa versioning.

Inventory hanya tersedia untuk store yang `BusinessType::usesInventory()`
(grup `retail`). Store grup `service` menerima **403 `inventory_not_available`**
pada semua endpoint di bawah ini (termasuk read-only).

Desain & aturan internal: `docs/inventory-design.md`.

---

## 1. Arti Kuantitas

| Field | Arti |
|-------|------|
| `quantity_on_hand` | Stok fisik yang tercatat. |
| `quantity_reserved` | Stok yang dipegang order belum final (reservasi). |
| `quantity_available` | `on_hand - reserved`, boleh dijual/dipakai. |

`quantity_reserved` **tidak** diubah oleh opening/receipt. Adjustment menghitung
selisih terhadap `on_hand` dan **tidak** membatalkan reservasi order.

---

## 2. Endpoint

| Method | Path | Kemampuan | Role |
|--------|------|-----------|------|
| GET | `/api/stock/locations` | List lokasi | owner, admin, cashier |
| POST | `/api/stock/locations` | Buat lokasi | owner, admin |
| GET | `/api/stock/locations/{stockLocation}` | Detail lokasi | owner, admin, cashier |
| PUT/PATCH | `/api/stock/locations/{stockLocation}` | Update lokasi | owner, admin |
| DELETE | `/api/stock/locations/{stockLocation}` | Hapus lokasi | owner, admin |
| GET | `/api/stock/balances` | List saldo | owner, admin, cashier |
| GET | `/api/items/{item}/stock` | Saldo per item | owner, admin, cashier |
| GET | `/api/stock/movements` | Ledger (read-only) | owner, admin, cashier |
| POST | `/api/stock/opening-balances` | Catat saldo awal | owner, admin |
| POST | `/api/stock/receipts` | Catat barang masuk | owner, admin |
| POST | `/api/stock/adjustments` | Koreksi hasil hitung fisik | owner, admin |

`{stockLocation}`/`{item}` adalah **ID**. ID milik tenant lain diperlakukan sama
seperti ID tidak ada (**404**).

Ledger **append-only**: tidak ada endpoint update/delete movement. Mutasi hanya
lewat opening/receipt/adjustment (dan order flow internal).

---

## 3. Konfigurasi `tracks_stock` pada Item

Create (`POST /api/items`) dan update (`PUT/PATCH /api/items/{item}`) menerima
field `tracks_stock` (boolean, opsional).

- Default **false**. Item lama tidak berubah otomatis.
- `tracks_stock = true` hanya diizinkan bila store mendukung inventory; jika
  tidak → **403 `inventory_not_available`**.
- Mengubah `true` → `false` **ditolak** (409 `item_inventory_in_use`) bila item
  sudah memiliki saldo atau riwayat movement. Nonaktifkan item untuk berhenti
  menggunakannya, jangan hapus histori.
- `store_id`, saldo, lokasi, movement, dan timestamp order **tidak** diterima
  dari endpoint item.
- Field `tracks_stock` muncul di response item.

Contoh:
```bash
curl -X PATCH -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"tracks_stock":true}' \
  http://localhost:8000/api/items/12
```

---

## 4. Lokasi Stok

Field: `id`, `name`, `code`, `type` (`warehouse`/`outlet`/`other`),
`is_default`, `is_active`, `created_at`, `updated_at`.

`store_id`, `is_default`, `default_guard` **tidak pernah** diambil dari input.

Delete:
| Kondisi | Status | `code` |
|---------|--------|--------|
| Lokasi default aktif | 409 | `default_stock_location_protected` |
| Punya saldo / movement / direferensikan order | 409 | `stock_location_in_use` |
| Lokasi non-default tak terpakai | 200 | - |

---

## 5. Saldo Stok (read-only)

Field: `id`, `item_id`, `stock_location_id`, `quantity_on_hand`,
`quantity_reserved`, `quantity_available`, `updated_at`, plus `item` dan
`stock_location` (ringkas).

- Hanya saldo item `tracks_stock = true` yang ditampilkan.
- `GET` **tidak** membuat baris saldo.
- Filter item/lokasi tenant lain menghasilkan hasil kosong (tidak bocor).
- `GET /api/items/{item}/stock`: item non-tracked → 409
  `inventory_item_not_tracked`; tracked tanpa saldo → `200` `balances: []`.

---

## 6. Ledger Movement (read-only)

Field: `id`, `item_id`, `stock_location_id`, `type`, `quantity`, `unit_cost`,
`order_id`, `order_item_id`, `idempotency_key`, `note`, `occurred_at`,
`created_at`, plus `item` & `stock_location`.

Filter: `item_id`, `stock_location_id`, `type`, `order_id`, `date_from`,
`date_to`, `per_page`, `page`, `sort`, `direction`.

Tipe movement & arah (kuantitas selalu positif):

| Tipe | Arah |
|------|------|
| `opening` | + on_hand |
| `purchase_in` | + on_hand |
| `adjustment_in` | + on_hand |
| `adjustment_out` | − on_hand |
| `sale_out` | − on_hand (order komit) |
| `reservation` | + reserved |
| `reservation_release` | − reserved |

---

## 7. Stock In: Opening / Receipt / Adjustment

Ketiganya owner/admin, tenant-scoped, idempotent, dan berada dalam satu
transaksi (balance + movement). Response envelope:

```json
{
  "message": "...",
  "data": {
    "movement": { "id": 9, "type": "opening", "quantity": "5.000", "...": "..." },
    "balance": {
      "quantity_on_hand": "5.000", "quantity_reserved": "0.000",
      "quantity_available": "5.000", "...": "..."
    },
    "idempotent": false,
    "no_op": false
  }
}
```

- **201** mutasi baru berhasil.
- **200** retry idempotent (`idempotent: true`) atau no-op (`no_op: true`).

### 7.1 Opening stock
`POST /api/stock/opening-balances`
```json
{ "stock_location_id": 1, "item_id": 12, "quantity": "10.000",
  "idempotency_key": "open-2026-0001", "note": "Saldo awal" }
```
- Item harus `tracks_stock = true`.
- Hanya boleh **sekali** per kombinasi lokasi+item. Jika sudah ada saldo atau
  movement apa pun → **409 `opening_stock_conflict`**.
- `quantity` harus > 0. `reserved` tidak berubah.

### 7.2 Receipt (barang masuk)
`POST /api/stock/receipts`
```json
{ "stock_location_id": 1, "item_id": 12, "quantity": "3.000",
  "idempotency_key": "rcv-0007", "reference": "DO-123" }
```
- Menambah `on_hand` sebesar `quantity` (membuat saldo bila belum ada).
- `quantity` > 0. `reserved` tidak berubah. Movement `purchase_in`.
- `reference` disimpan pada `note` movement.

### 7.3 Adjustment (koreksi hasil hitung fisik)
`POST /api/stock/adjustments`
```json
{ "stock_location_id": 1, "item_id": 12, "counted_quantity": "8.500",
  "reason": "Hasil opname mingguan", "idempotency_key": "adj-0009" }
```
- Client mengirim **hasil hitung**, bukan saldo baru. Server mengunci saldo,
  membaca `on_hand`, lalu menghitung `delta = counted - on_hand`.
- `delta > 0` → `adjustment_in`; `delta < 0` → `adjustment_out`.
- `delta == 0` → **200 no-op** tanpa movement (`no_op: true`).
- Bila `counted_quantity < quantity_reserved` → **409
  `adjustment_below_reserved`** (reservasi order tidak dibatalkan otomatis).
- `counted_quantity` ≥ 0, maksimal 3 desimal. `reason` wajib (disimpan di note).

### 7.4 Kontrak idempotency
- `idempotency_key` wajib, unik **per store** (`unique(store_id, idempotency_key)`).
- Retry dengan key **dan payload sama** → 200, mengembalikan movement yang sama,
  tanpa menambah stok lagi (`idempotent: true`).
- Key yang sama dengan **payload berbeda** → **409 `idempotency_conflict`**.
- Retry tidak pernah menggandakan saldo atau movement.

### 7.5 Error mutasi
| Status | `code` | Kondisi |
|--------|--------|---------|
| 409 | `inventory_item_not_tracked` | Item `tracks_stock = false` |
| 409 | `opening_stock_conflict` | Saldo/movement sudah ada |
| 409 | `idempotency_conflict` | Key dipakai payload berbeda |
| 409 | `adjustment_below_reserved` | Hitungan < reserved |
| 409 | `stock_invalid_quantity` | Kuantitas tidak valid |
| 409 | `stock_inconsistent` | Saldo tidak konsisten |
| 404 | - | Item/lokasi tenant lain |
| 422 | - | Validasi payload gagal |

---

## 8. Error Codes (ringkas)

| Status | `code` | Kondisi |
|--------|--------|---------|
| 401 | `unauthenticated` / `token_required` | Autentikasi |
| 409 | `current_store_not_selected` / `current_store_unavailable` | Konteks toko |
| 403 | `inventory_not_available` | Store non-inventory |
| 403 | - | Role tidak diizinkan |
| 409 | `default_stock_location_protected` | Hapus lokasi default |
| 409 | `stock_location_in_use` | Hapus lokasi terpakai |
| 409 | `item_has_stock_history` | Hapus item beriwayat |
| 409 | `item_inventory_in_use` | Nonaktifkan tracking beriwayat |
| 422 | - | Validasi gagal |

---

## 9. Tenant Isolation

- Toko aktif dari token (`EnsureCurrentStore`), bukan request.
- Query memakai relasi `$store->stockLocations()/stockBalances()/stockMovements()/items()`.
- Item/lokasi tenant lain → **404**. `store_id`/`is_default`/`default_guard`
  diabaikan dari input.
- Response error tidak membocorkan ID/data tenant lain.

---

## 10. Menjalankan Test & Concurrency

```bash
# Seluruh suite (SQLite :memory: via fail-closed guard)
php artisan test

# Test checkpoint inventory
php artisan test --filter="ItemTrackingConfigTest|StockOpeningBalanceTest|StockReceiptTest|StockAdjustmentTest"

# Guard database
php artisan test --filter="TestDatabaseGuardTest|TestEnvironmentGuardTest"

# Concurrency harness (MySQL), DB khusus test — menolak saas_pos_db
php tests/Concurrency/run.php
```

Harness menjalankan skenario 1–12 pada database `saas_pos_concurrency_test`
saja; `tests/Concurrency/boot.php` menolak database development.

---

## 11. Belum Tersedia

- Transfer antarlokasi, retur (customer/supplier), purchase order, supplier
  management.
- Multi-satuan (`item_units`), BOM/resep, konsumsi bahan baku restoran.
- Rekonsiliasi saldo-dari-ledger otomatis.
