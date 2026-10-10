# Order & Order Items API — Universal POS

> Status: **Sudah diimplementasikan (Phase 2)**.
> Semua endpoint berada di bawah `auth:sanctum` + `current.store`.

Order adalah header transaksi. Seluruh perhitungan uang dilakukan **server-side**;
harga, total, dan status dari client tidak pernah dipercaya.

---

## 1. Endpoint

| Method | Path | Kemampuan | Role |
|--------|------|-----------|------|
| GET | `/api/orders` | List (paginate + filter) | owner, admin, cashier |
| POST | `/api/orders` | Buat order + item (atomik) | owner, admin, cashier |
| GET | `/api/orders/{order}` | Detail (item, customer, pembayaran) | owner, admin, cashier |
| PATCH | `/api/orders/{order}/fulfillment` | Ubah status pemenuhan | lihat §6 |

Tidak ada endpoint menghapus order. Order dibatalkan (cancelled), tidak dihapus.

---

## 2. Field Order

| Field | Tipe DB | Catatan |
|-------|---------|---------|
| `id` | bigint | |
| `store_id` | FK stores | server-set |
| `order_number` | string(40) | unik per toko; format `TRX-YYYYMMDD-0001` |
| `customer_id` | FK customers, nullable | walk-in boleh null |
| `cashier_id` | FK users, nullable | pembuat order (server-set) |
| `subtotal` | unsignedBigInteger | jumlah `line_subtotal` |
| `discount_amount` | unsignedBigInteger | jumlah diskon baris |
| `tax_amount` | unsignedBigInteger | input manual (belum ada tarif pajak) |
| `total_amount` | unsignedBigInteger | `subtotal - discount_amount + tax_amount` |
| `paid_amount` | unsignedBigInteger | jumlah pembayaran valid (di-maintain PaymentService) |
| `payment_status` | string(20) | `unpaid`/`partially_paid`/`paid`/`refunded` (derived) |
| `fulfillment_status` | string(20) | `pending`/`processing`/`completed`/`cancelled` |
| `notes` | text, nullable | |
| `placed_at` | timestamp | waktu transaksi |
| `completed_at` | timestamp, nullable | |
| `cancelled_at` | timestamp, nullable | |
| `cancel_reason` | string(255), nullable | |
| timestamps | | |

### Field Order Item (snapshot)

| Field | Tipe DB | Catatan |
|-------|---------|---------|
| `order_id` | FK orders | cascade |
| `item_id` | FK items, nullable | nullOnDelete (histori tetap) |
| `item_name` | string(150) | snapshot |
| `item_sku` | string(64), nullable | snapshot |
| `item_type` | string(20) | snapshot |
| `unit` | string(20) | snapshot |
| `quantity` | decimal(12,3) | |
| `unit_price` | unsignedBigInteger | harga katalog saat transaksi |
| `line_subtotal` | unsignedBigInteger | `round(unit_price * quantity)` |
| `discount_amount` | unsignedBigInteger | diskon baris |
| `line_total` | unsignedBigInteger | `line_subtotal - discount_amount` |
| `notes` | string(255), nullable | |

Perubahan nama/harga/tipe item di katalog **tidak** mengubah order historis.
Menghapus item mengosongkan `item_id` (`nullOnDelete`) tetapi snapshot tetap.

---

## 3. Membuat Order

`POST /api/orders`:

```json
{
  "customer_id": 1,
  "items": [
    { "item_id": 10, "quantity": 2, "discount_amount": 1000 },
    { "item_id": 11, "quantity": 1 }
  ],
  "tax_amount": 2000,
  "notes": "Tanpa gula"
}
```

Aturan server:

1. Customer (jika ada) harus milik toko aktif dan tidak diarsipkan.
2. Setiap item harus milik toko aktif dan `is_active = true`.
3. Harga diambil dari `items.selling_price`; `unit_price`/`line_total`/
   `total_amount` dari client **diabaikan**.
4. `line_subtotal = round(unit_price * quantity)` (integer rupiah).
5. Diskon baris tidak boleh melebihi `line_subtotal`.
6. `subtotal = Σ line_subtotal`; `discount_amount = Σ diskon baris`;
   `total_amount = subtotal - discount + tax_amount`.
7. Order + semua item + history status dibuat dalam **satu transaksi**. Jika ada
   satu item invalid, seluruh order dibatalkan (tidak ada order parsial).
8. `fulfillment_status` selalu `pending`; `payment_status` `unpaid`
   (atau `paid` bila total 0). Client tidak dapat menetapkan status awal.
9. `order_number` dibuat dari **counter persisten per toko** (`store_sequences`)
   di dalam transaksi yang memegang lock baris store.

Respons `201`: `OrderResource` dengan `items` dan `customer`.

### 3.1 Idempotency (opsional, direkomendasikan untuk POS)

`POST /api/orders` dan `POST /api/orders/{order}/payments` menerima field
opsional `idempotency_key` (string, maks 100) dari client.

- Key disimpan per toko dengan unique index `(store_id, idempotency_key)`,
  dikombinasikan dengan `request_fingerprint` (sha256 payload kanonik).
- Mengirim ulang key **dan** payload yang sama mengembalikan **hasil yang sama**
  (order / pembayaran yang sudah ada), bukan duplikat. Aman untuk retry setelah
  timeout atau koneksi putus.
- Menggunakan key yang sama dengan payload **berbeda** ditolak `409`
  `idempotency_conflict`.
- Key berbeda untuk payload berbeda menghasilkan dokumen baru (perilaku biasa).
- Tanpa key, perilaku lama tetap berlaku (tidak ada jaminan idempotensi).

Client POS membuat satu key per upaya checkout dan memakainya ulang untuk retry
order **dan** pembayaran. **Timeout bukan berarti gagal:** retry dengan key yang
sama menyelesaikan ke hasil yang sama. Ringkasan alur di frontend: retry
memakai order yang sudah dibuat (bila ada), dan kasir dapat memilih "mulai
transaksi baru" untuk membuang key.

---

## 4. Nomor Order & Concurrency

- Format `TRX-{YYYYMMDD}-{urut}`; unique `(store_id, order_number)`.
- Sumber nomor: tabel `store_sequences` (kolom `last_value`), di-`increment`
  secara atomik. **Bukan** turunan dari jumlah order.
- `OrderService::create()` mengunci baris `stores` (`lockForUpdate`) sebelum
  membuat nomor dan order, sehingga pembuatan order per toko ter-serialisasi.
- **Terverifikasi (Phase 2.1):** pada MySQL 8.4.3, 10 worker paralel membuat
  order untuk toko yang sama menghasilkan 10 nomor unik (lihat
  `docs/concurrency-testing.md`). Row lock nyata di MySQL 8 InnoDB; SQLite
  (test suite) hanya memverifikasi protokol sekuensial.

---

## 5. Status

### Payment status (`PaymentStatus`)
`unpaid` → `partially_paid` → `paid`. `refunded` disediakan untuk alur refund
yang belum dibangun; tidak ada endpoint Phase 2 yang menetapkannya.

### Fulfillment status (`FulfillmentStatus`) & transisi
```
pending    → processing, cancelled
processing → completed, cancelled
completed  → (final)
cancelled  → (final)
```
Transisi lain ditolak (`422`).

### PATCH `/api/orders/{order}/fulfillment`
```json
{ "fulfillment_status": "processing", "reason": "Mulai dikerjakan" }
```
- `processing`/`completed`: semua anggota aktif (owner/admin/cashier).
- `cancelled`: **owner/admin saja** (cashier → 403).
- Order dengan **pembayaran aktif** tidak dapat dibatalkan → **409**
  `order_conflict` (void pembayaran dulu).
- `completed_at`/`cancelled_at` diisi otomatis; perubahan status tercatat di
  `order_status_histories` (aktor + waktu + alasan).

---

## 6. Authorization

Policy `App\Policies\OrderPolicy`:
- `viewAny`, `view`, `create`, `updateFulfillment`: semua anggota aktif.
- `cancel`: owner/admin.

Tenant isolation: semua query via `$store->orders()`, order tenant lain → 404.

---

## 7. List, Filter, Pagination

| Parameter | Tipe | Default | Aturan |
|-----------|------|---------|--------|
| `search` | string | - | mencari `order_number` |
| `payment_status` | enum | - | filter |
| `fulfillment_status` | enum | - | filter |
| `customer_id` | integer | - | filter |
| `cashier_id` | integer | - | filter |
| `date_from`/`date_to` | date | - | filter `placed_at` |
| `per_page` | integer | 15 | min 1, max 100 |
| `sort` | `placed_at`/`created_at`/`order_number`/`total_amount` | `placed_at` | whitelist |
| `direction` | `asc`/`desc` | `desc` | whitelist |

---

## 8. Audit

`order_status_histories` mencatat perubahan `payment_status` dan
`fulfillment_status` beserta `changed_by`, `reason`, dan `created_at`. Ini
audit terbatas pada status order, bukan framework audit umum.

---

## 9. Keterbatasan

- Belum ada perhitungan pajak otomatis (input manual `tax_amount`).
- Belum ada diskon level order (hanya diskon per baris).
- Belum ada integrasi stok; item tidak mengurangi stok (modul stok terpisah).
- Belum ada refund; `refunded` belum reachable.
- Belum ada edit bebas order setelah dibuat (hanya transisi status).
- Row locking kosmetik di SQLite.
