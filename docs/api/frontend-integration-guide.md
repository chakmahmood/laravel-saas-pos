# Frontend Integration Guide

> Checkpoint 6. Panduan integrasi FE Admin Panel dengan backend Universal POS.
> Spesifikasi lengkap: `docs/api/openapi.yaml`. Endpoint & payload di sini
> mengikuti implementasi aktual (51 operasi).

## 1. Base URL & prefix

- Semua endpoint bisnis: `{BASE_URL}/api`.
- Tanpa versioning path.
- Semua request/response JSON.

## 2. Autentikasi (Sanctum Bearer token)

### Alur
1. **Registrasi** `POST /api/auth/register` — membuat user, store pertama,
   membership `owner`, langganan Free, dan token. `business_type` opsional.
2. **Login** `POST /api/auth/login` — mengembalikan token baru.
3. **Logout** `POST /api/auth/logout` — mencabut token yang sedang dipakai.

Endpoint `register` dan `login` **rate-limited** (per-IP 30/menit, per
email+IP 5/menit). Pelanggaran → `429` `{ message, code: "too_many_requests" }`.

### Menggunakan token
Kirim header pada setiap request terproteksi:

```
Authorization: Bearer <plainTextToken>
Accept: application/json
```

Token bersifat opaque. Simpan di secure storage FE. Jangan parsing isi token.

### Respons 401
`{ "message": "Tidak terautentikasi.", "code": "unauthenticated" }` untuk token
tidak ada/invalid/kedaluwarsa. FE harus mengarahkan ke halaman login.

## 3. Current store

Konteks tenant **disimpan per token** (`personal_access_tokens.current_store_id`),
bukan per user. FE tidak mengirim `store_id` pada endpoint bisnis.

| Aksi | Endpoint |
|------|----------|
| Daftar store yang dapat diakses | `GET /api/me` |
| Lihat store aktif token | `GET /api/current-store` |
| Ubah store aktif (token ini saja) | `PUT /api/current-store` `{ "store_id": <id> }` |

Perilaku:
- Token tanpa store aktif → endpoint bisnis membalas `409`
  `current_store_not_selected`. FE harus memilih store via PUT.
- Store aktif tidak lagi valid (dihapus/nonaktif/membership dicabut) → `409`
  `current_store_unavailable`; referensi pada token dibersihkan otomatis.
- Memilih store tanpa membership aktif → `403` `store_not_accessible`.
- Store **nonaktif** tidak dapat dipilih/dipakai (lihat
  `docs/store-lifecycle.md`).

## 4. Konvensi data

| Konsep | Representasi |
|--------|--------------|
| Uang | integer rupiah (minor unit). Contoh `18000` = Rp 18.000. Tidak ada float. |
| Kuantitas order/inventory | string desimal 3 angka. Contoh `"1.125"`. |
| Tanggal/waktu | ISO-8601 UTC dengan sufiks `Z` atau `null`. Tampilkan di timezone user. |
| Boolean | JSON `true`/`false`. |
| Enum | string lowercase (lihat daftar di §7). |
| Nullable | field dapat `null`. |

Catatan: `cost_price = null` berarti HPP tidak dilacak (bukan nol).

## 5. Kontrak error

Semua error JSON berisi `message` dan `code` (kecuali 5xx yang bersifat generik).

| Status | `code` (contoh) | Arti |
|--------|-----------------|------|
| 401 | `unauthenticated`, `token_required` | Perlu login / token API dengan konteks toko |
| 403 | `forbidden`, `inventory_not_available`, `store_not_accessible` | Tidak diizinkan / capability / store |
| 404 | `not_found` | Resource tidak ada pada tenant aktif |
| 409 | `current_store_not_selected`, `current_store_unavailable`, `category_in_use`, `insufficient_stock`, `opening_stock_conflict`, `idempotency_conflict`, `adjustment_below_reserved`, `inventory_item_not_tracked`, `item_has_stock_history`, `item_inventory_in_use`, `default_stock_location_protected`, `stock_location_in_use`, `cash_session_already_open`, `cash_session_already_closed`, `cash_session_required`, `cash_session_closed`, `order_cancelled`, `payment_already_voided`, `inventory_not_supported`, `stock_location_unavailable`, `stock_invalid_quantity`, `stock_inconsistent`, `stock_tenant_mismatch` | Konflik state bisnis |
| 422 | `validation_error` | Validasi gagal; lihat `errors` |
| 429 | `too_many_requests` | Rate limit |
| 5xx | - | Error server (generik) |

Bentuk:
```json
// 409 / 403 / 404
{ "message": "...", "code": "..." }

// 422
{ "message": "The name field is required.",
  "code": "validation_error",
  "errors": { "name": ["The name field is required."] } }
```

Response tidak pernah memuat nama class model, SQL, stack trace, token, atau
data tenant lain.

## 6. Pagination

List endpoint memakai paginator Laravel:
```json
{
  "data": [ ... ],
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
  "meta": {
    "current_page": 1, "from": 1, "last_page": 3,
    "per_page": 15, "to": 15, "total": 42,
    "path": "http://host/api/items", "links": [ ... ]
  }
}
```
Query umum: `page`, `per_page` (1..100). Filter/sort spesifik ada di OpenAPI
per endpoint.

## 7. Enum

- `ItemType`: product, service, menu, package
- `PaymentMethod`: cash, bank_transfer, card, e_wallet, other
- `PaymentRecordStatus`: completed, voided
- `PaymentStatus`: unpaid, partially_paid, paid, refunded
- `FulfillmentStatus`: pending, processing, completed, cancelled
- `CashSessionStatus`: open, closed
- `CashMovementType`: cash_in, cash_out
- `StockLocationType`: warehouse, outlet, other
- `StockMovementType`: opening, purchase_in, sale_out, adjustment_in,
  adjustment_out, transfer_in, transfer_out, return_in, return_out,
  reservation, reservation_release, reversal
- `BusinessType`: retail, restaurant, laundry, repair, salon, other
- `StoreRole`: owner, admin, cashier

## 8. Idempotency (inventory stock-in)

Hanya `POST /stock/opening-balances`, `/stock/receipts`, `/stock/adjustments`
memerlukan `idempotency_key` (string, wajib, unik **per store**).

- **Dibuat oleh client** (bukan server). Gunakan UUID atau string stabil per
  operasi; kirim key yang sama saat mengulang request yang hasilnya belum
  diketahui (mis. timeout).
- **Fingerprint** request = hash dari: operasi, store, lokasi, item,
  kuantitas (dinormalisasi ke milli → `"5"` == `"5.000"`), dan detail
  (note/reference/reason).
- **Retry identik** (key + payload sama) → `200`, `idempotent: true`,
  mengembalikan movement & saldo yang sama, tanpa efek ganda.
- **Key sama + payload berbeda** → `409` `idempotency_conflict`.
- **Adjustment no-op** (delta 0) → `200`, `no_op: true`, `movement: null`.

Pembayaran (`POST /orders/{order}/payments`) **belum** memiliki idempotency key.
Jangan mengandalkannya untuk idempotensi pembayaran di FE.

## 9. Matriks Permission (per store)

| Operasi | owner | admin | cashier | tanpa membership | store non-inventory |
|---------|:---:|:---:|:---:|:---:|:---:|
| Auth (register/login/logout) | - | - | - | publik/auth | - |
| Store: lihat, pilih current-store | ✓ | ✓ | ✓ | ✗ | ✗ (nonaktif ditolak) |
| Category: read | ✓ | ✓ | ✓ | ✗ | n/a |
| Category: create/update/delete | ✓ | ✓ | ✗ | ✗ | n/a |
| Item: read | ✓ | ✓ | ✓ | ✗ | n/a |
| Item: create/update/delete | ✓ | ✓ | ✗ | ✗ | n/a |
| Item: set `tracks_stock=true` | ✓ | ✓ | ✗ | ✗ | ✗ (`inventory_not_available`) |
| Customer: read | ✓ | ✓ | ✓ | ✗ | n/a |
| Customer: create/update/archive | ✓ | ✓ | ✗ | ✗ | n/a |
| Order: create/read/fulfillment | ✓ | ✓ | ✓ | ✗ | n/a |
| Order: cancel | ✓ | ✓ | ✗ | ✗ | n/a |
| Payment: record/read | ✓ | ✓ | ✓ | ✗ | n/a |
| Payment: void | ✓ | ✓ | ✗ | ✗ | n/a |
| Cash session: open/current/read | ✓ | ✓ | ✓ (shift sendiri) | ✗ | n/a |
| Cash session: close | ✓ | ✓ | shift sendiri | ✗ | n/a |
| Cash movement: record | shift sendiri | shift sendiri | shift sendiri | ✗ | n/a |
| Inventory: read (locations/balances/movements) | ✓ | ✓ | ✓ | ✗ | ✗ |
| Inventory: manage locations | ✓ | ✓ | ✗ | ✗ | ✗ |
| Inventory: opening/receipt/adjustment | ✓ | ✓ | ✗ | ✗ | ✗ |

Catatan:
- Tidak ada role **super-admin platform**. Semua role per-store. FE jangan
  mengasumsikan akses lintas store.
- FE bukan lapisan keamanan: backend memverifikasi permission setiap request.
  Sembunyikan aksi berdasarkan role, tetapi tetap tangani `403`.

## 10. Ringkasan endpoint

Lihat `docs/api/openapi.yaml` untuk detail. Kelompok:
Auth, Current store, Categories, Items, Customers, Orders, Payments,
Cash sessions, Inventory. Total **51 operasi** (lihat lampiran di
`docs/backend-readiness-audit.md`).

### Belum tersedia (jangan diintegrasikan)
- Plans/Subscriptions endpoint (model ada, endpoint belum).
- Transfer stok, retur, purchase order, supplier, multi-satuan, BOM.
- Delete/arsip store.
- Laporan penjualan.

## 11. Catatan store nonaktif

Saat ini store nonaktif tidak dapat diakses sama sekali (termasuk baca) — lihat
`docs/store-lifecycle.md` §5 untuk keputusan yang masih tertunda mengenai akses
baca histori store nonaktif.
