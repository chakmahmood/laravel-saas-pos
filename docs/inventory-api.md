# Inventory API — Locations, Balances & Ledger

> Status: **Tersedia (Checkpoint 3)**.
> Lokasi dapat dikelola; **saldo dan movement read-only**.
> Semua endpoint di bawah `auth:sanctum` + `current.store` + gating inventory.
> Prefix `/api`, tanpa versioning.

Inventory hanya tersedia untuk store yang `BusinessType::usesInventory()`
(`retail`, `restaurant`). Store lain menerima **403 `inventory_not_available`**
pada semua endpoint di bawah ini (termasuk read-only).

Desain & aturan internal: `docs/inventory-design.md`.

---

## 1. Endpoint

| Method | Path | Kemampuan | Role |
|--------|------|-----------|------|
| GET | `/api/stock/locations` | List lokasi | owner, admin, cashier |
| POST | `/api/stock/locations` | Buat lokasi | owner, admin |
| GET | `/api/stock/locations/{stockLocation}` | Detail lokasi | owner, admin, cashier |
| PUT | `/api/stock/locations/{stockLocation}` | Ganti lokasi (name+type wajib) | owner, admin |
| PATCH | `/api/stock/locations/{stockLocation}` | Update sebagian | owner, admin |
| DELETE | `/api/stock/locations/{stockLocation}` | Hapus lokasi | owner, admin |
| GET | `/api/stock/balances` | List saldo | owner, admin, cashier |
| GET | `/api/items/{item}/stock` | Saldo per item | owner, admin, cashier |
| GET | `/api/stock/movements` | Ledger (read-only) | owner, admin, cashier |

`{stockLocation}` adalah **ID lokasi**, `{item}` adalah **ID item**. ID milik
tenant lain diperlakukan sama seperti ID tidak ada (**404**).

Tidak ada endpoint create/update/delete untuk balance maupun movement. Ledger
hanya bertambah lewat service internal (order flow; nanti stock receipt /
adjustment).

---

## 2. Lokasi Stok

Field lokasi pada response: `id`, `name`, `code`, `type`, `is_default`,
`is_active`, `created_at`, `updated_at`.
Tipe: `warehouse`, `outlet`, `other`.

`store_id`, `is_default`, dan `default_guard` **tidak pernah** diambil dari
input. Nilai `is_default`/`default_guard` dikelola server (provisioning).

### Buat
```bash
curl -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"name":"Gudang Belakang","code":"GDG","type":"warehouse"}' \
  http://localhost:8000/api/stock/locations
```
Respons `201`:
```json
{
  "message": "Lokasi stok berhasil dibuat.",
  "data": {
    "id": 3, "name": "Gudang Belakang", "code": "GDG",
    "type": "warehouse", "is_default": false, "is_active": true,
    "created_at": "2026-10-10T03:00:00.000000Z",
    "updated_at": "2026-10-10T03:00:00.000000Z"
  }
}
```

### Filter & Pagination (list)
`search` (name/code), `type`, `is_active`, `per_page` (1..100), `page`,
`sort` (`name`/`created_at`), `direction` (`asc`/`desc`).

### Proteksi delete
| Kondisi | Status | `code` |
|---------|--------|--------|
| Lokasi default aktif | 409 | `default_stock_location_protected` |
| Punya saldo / movement / direferensikan order | 409 | `stock_location_in_use` |
| Lokasi tidak terpakai (non-default) | 200 | - |

FK `restrictOnDelete` menjadi backstop; pelanggarannya diterjemahkan ke 409 yang
sama (bukan 500).

---

## 3. Saldo Stok (read-only)

Field saldo: `id`, `item_id`, `stock_location_id`, `quantity_on_hand`,
`quantity_reserved`, `quantity_available`, `updated_at`, plus `item` dan
`stock_location` (ringkas).

`quantity_available = quantity_on_hand - quantity_reserved`, dihitung desimal
eksak (tanpa floating point).

### List
```bash
curl -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  "http://localhost:8000/api/stock/balances?item_id=12&stock_location_id=1&per_page=20"
```
Aturan:
- Hanya saldo untuk item `tracks_stock = true` yang ditampilkan.
- `GET` **tidak** membuat baris saldo.
- Filter `item_id`/`stock_location_id` yang menunjuk tenant lain cukup
  menghasilkan hasil kosong (tidak membocorkan data tenant lain).
- Pagination mengikuti format paginator Laravel (`data`, `links`, `meta`).

### Saldo per item
```bash
curl -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/api/items/12/stock"
```
Respons `200` (item melacak stok):
```json
{
  "data": {
    "item_id": 12, "name": "Beras", "unit": "kg", "tracks_stock": true,
    "balances": [
      { "id": 5, "item_id": 12, "stock_location_id": 1,
        "quantity_on_hand": "10.000", "quantity_reserved": "2.000",
        "quantity_available": "8.000",
        "updated_at": "2026-10-10T03:00:00.000000Z" }
    ]
  }
}
```
- Item **tidak** melacak stok → `409 { "code": "inventory_item_not_tracked" }`
  (bukan saldo nol, karena nol akan salah mengartikan stok fisik nol).
- Item melacak stok tetapi belum ada saldo → `200` dengan `balances: []`.
- Item tenant lain → `404`.

---

## 4. Ledger Movement (read-only)

Field movement: `id`, `item_id`, `stock_location_id`, `type`, `quantity`,
`unit_cost`, `order_id`, `order_item_id`, `idempotency_key`, `note`,
`occurred_at`, `created_at`, plus `item` dan `stock_location`.

### List & filter
```bash
curl -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/api/stock/movements?type=reservation&item_id=12&per_page=20"
```
Parameter: `item_id`, `stock_location_id`, `type` (enum `StockMovementType`),
`order_id`, `date_from`, `date_to` (rentang `occurred_at`), `per_page`, `page`,
`sort` (`occurred_at`/`created_at`/`id`), `direction`.

Aturan:
- Read-only. Tidak ada endpoint update/delete. Ledger append-only juga
  ditegakkan di model.
- Semua query dibatasi `store_id` toko aktif.
- Eager loading item & lokasi untuk menghindari N+1.

---

## 5. Error Codes

| Status | `code` | Kondisi |
|--------|--------|---------|
| 401 | `unauthenticated` | Token tidak ada/tidak valid |
| 401 | `token_required` | Bukan token API dengan konteks toko |
| 409 | `current_store_not_selected` | Token belum punya toko aktif |
| 409 | `current_store_unavailable` | Toko aktif tidak valid lagi |
| 403 | `inventory_not_available` | Store tidak mendukung inventory |
| 403 | - | Role tidak diizinkan (mutasi oleh cashier) |
| 404 | - | Resource tidak ada / milik tenant lain |
| 409 | `default_stock_location_protected` | Hapus lokasi default |
| 409 | `stock_location_in_use` | Hapus lokasi yang masih dirujuk |
| 409 | `inventory_item_not_tracked` | Minta saldo item non-tracked |
| 409 | `item_has_stock_history` | Hapus item yang punya riwayat stok |
| 422 | - | Validasi payload/query gagal |

---

## 6. Tenant Isolation

- Toko aktif dari token (`EnsureCurrentStore`), bukan request.
- Semua query memakai relasi `$store->stockLocations()/stockBalances()/
  stockMovements()`.
- Resource tenant lain → **404**.
- `store_id`, `is_default`, `default_guard` diabaikan dari input.
- Response error tidak membocorkan ID/data tenant lain.

---

## 7. Belum Tersedia

- Stock receipt / opening stock / adjustment (cara mengisi saldo awal).
- Transfer antarlokasi, retur.
- Konfigurasi `items.tracks_stock` lewat API item.
- Multi-satuan, BOM/resep.

Karena **stock-in belum ada**, retail end-to-end belum siap walau API
pembacaan/lokasi sudah tersedia.
