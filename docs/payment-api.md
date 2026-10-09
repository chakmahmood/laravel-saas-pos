# Payment API — Universal POS (pencatatan manual)

> Status: **Sudah diimplementasikan (Phase 2)**.
> Semua endpoint berada di bawah `auth:sanctum` + `current.store`.

Fase ini **hanya mencatat pembayaran manual**. Tidak ada integrasi gateway
(Midtrans/Xendit/Stripe/dll). Sistem tidak memverifikasi transaksi ke bank atau
provider. Tidak ada refund kompleks.

---

## 1. Endpoint

| Method | Path | Kemampuan | Role |
|--------|------|-----------|------|
| GET | `/api/orders/{order}/payments` | Daftar pembayaran order | owner, admin, cashier |
| POST | `/api/orders/{order}/payments` | Catat pembayaran | owner, admin, cashier |
| GET | `/api/payments/{payment}` | Detail pembayaran | owner, admin, cashier |
| POST | `/api/payments/{payment}/void` | Batalkan (void) pembayaran | owner, admin |

Pembayaran **tidak pernah dihapus**; dibatalkan (void) agar audit terjaga.

---

## 2. Field

| Field | Tipe DB | Catatan |
|-------|---------|---------|
| `id` | bigint | |
| `store_id` | FK stores | server-set |
| `order_id` | FK orders | cascade |
| `payment_method` | string(30) | `cash`/`bank_transfer`/`card`/`e_wallet`/`other` |
| `amount` | unsignedBigInteger | integer rupiah, > 0 |
| `status` | string(20) | `completed`/`voided` |
| `reference_number` | string(100), nullable | no. referensi manual |
| `notes` | text, nullable | |
| `paid_at` | timestamp, nullable | default waktu catat |
| `recorded_by` | FK users, nullable | aktor pencatat |
| `voided_at` | timestamp, nullable | |
| `voided_by` | FK users, nullable | aktor void |
| `void_reason` | string(255), nullable | |
| timestamps | | |

---

## 3. Aturan Pembayaran

1. `amount` integer positif (`min:1`).
2. Pembayaran terkait order & toko yang benar (via relasi current store).
3. Total pembayaran valid **tidak boleh melampaui sisa tagihan**
   (overpayment → `422` pada field `amount`).
4. Pembayaran `voided` **tidak dihitung** sebagai pembayaran aktif.
5. Order `cancelled` tidak dapat menerima pembayaran → **409** `order_cancelled`.
6. `paid_amount` dan `payment_status` order **dihitung ulang server-side** dari
   pembayaran valid; client tidak mengirimnya.
7. Perubahan pembayaran dan status order **atomik** (satu transaksi).
8. Pencegahan overpayment paralel: `PaymentService` mengunci baris order
   (`lockForUpdate`) sebelum menghitung sisa tagihan. Nyata di MySQL 8 InnoDB;
   SQLite (test) tidak mendukung row lock.

### Recompute status
```
total <= 0 atau paid >= total → paid
0 < paid < total              → partially_paid
paid == 0                     → unpaid
```

---

## 4. Contoh

### Catat pembayaran
```bash
curl -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"payment_method":"cash","amount":50000,"reference_number":null}' \
  http://localhost:8000/api/orders/1/payments
```
Respons `201`:
```json
{
  "message": "Pembayaran berhasil dicatat.",
  "data": {
    "id": 1,
    "order_id": 1,
    "payment_method": "cash",
    "amount": 50000,
    "status": "completed",
    "reference_number": null,
    "notes": null,
    "paid_at": "2026-10-09T04:30:00.000000Z",
    "recorded_by": 3,
    "voided_at": null,
    "voided_by": null,
    "void_reason": null,
    "created_at": "2026-10-09T04:30:00.000000Z",
    "updated_at": "2026-10-09T04:30:00.000000Z"
  }
}
```

### Void pembayaran
```bash
curl -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"reason":"Salah input"}' \
  http://localhost:8000/api/payments/1/void
```
Respons `200`: payment dengan `status: "voided"`; order `paid_amount` dan
`payment_status` ikut dihitung ulang.

---

## 5. Error Codes

| Status | `code` | Kondisi |
|--------|--------|---------|
| 403 | - | Cashier mencoba void pembayaran |
| 404 | - | Order/payment tidak ada atau milik tenant lain |
| 409 | `order_cancelled` | Mencatat pembayaran pada order yang dibatalkan |
| 409 | `order_conflict` | Membatalkan order yang masih punya pembayaran aktif |
| 409 | `payment_already_voided` | Void pembayaran yang sudah void |
| 422 | - | `amount` ≤ 0 atau melebihi sisa tagihan |

---

## 6. Konkurensi Overpayment

- `record()` dan `void()` mengunci baris `orders` (`lockForUpdate`) sebelum
  menghitung sisa tagihan dan menyimpan.
- Dua pembayaran bersamaan untuk order yang sama akan ter-serialisasi di MySQL;
  request kedua melihat pembayaran pertama dan tidak dapat overpay.
- **Terverifikasi (Phase 2.1):** pada MySQL 8.4.3, 8 pembayaran paralel sebesar
  5.000 atas order 10.000 menghasilkan tepat 2 sukses, `paid_amount = 10.000`,
  status `paid` (tidak overpaid); 4 void paralel atas payment yang sama
  menghasilkan tepat 1 void. Lihat `docs/concurrency-testing.md`.

---

## 7. Keterbatasan

- Tanpa gateway; tanpa verifikasi bank/provider.
- Tanpa refund/partial refund (status `refunded` disediakan tapi belum
  reachable).
- Tanpa rekonsiliasi kas / cash session (Phase 3).
- Kasir boleh mencatat pembayaran, tetapi tidak boleh void.
