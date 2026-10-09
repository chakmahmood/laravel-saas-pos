# Cash Sessions / Shift Kasir API — Universal POS

> Status: **Sudah diimplementasikan (Phase 3A)**.
> Semua endpoint di bawah `auth:sanctum` + `current.store`. Prefix `/api`.
> Tanpa payment gateway, refund, inventory, atau pajak otomatis.

Shift kasir (cash session) melacak uang fisik di laci kasir selama satu periode
kerja: modal awal, kas masuk/keluar manual, pembayaran tunai, dan rekonsiliasi
saat tutup.

---

## 1. Konsep

- Satu shift milik **satu toko** dan **satu kasir**.
- Kasir hanya boleh memiliki **satu shift terbuka** per toko (dijamin database).
- Pembayaran **tunai** otomatis terhubung ke shift terbuka milik pencatat.
- Pembayaran **non-tunai tidak** menambah saldo kas fisik.
- Shift yang **sudah ditutup** bersifat read-only: tidak menerima movement atau
  pembayaran tunai baru, dan pembayaran tunai di dalamnya tidak dapat di-void.

---

## 2. Perhitungan Kas

```
expected_cash = opening_cash + cash_in_total - cash_out_total + cash_sales_total
difference    = actual_cash - expected_cash
```

- `opening_cash`: modal awal saat membuka shift.
- `cash_in_total`: total `cash_movements` tipe `cash_in`.
- `cash_out_total`: total `cash_movements` tipe `cash_out`.
- `cash_sales_total`: total pembayaran tunai **aktif** (status `completed`) yang
  terhubung ke shift. Pembayaran `voided` tidak dihitung; pembayaran non-tunai
  tidak dihitung; pembayaran tanpa `cash_session_id` (legacy) tidak dihitung.

Interpretasi `difference`: positif = uang fisik berlebih; negatif = kurang; nol =
sesuai. Saat shift ditutup, `expected_cash`, `actual_cash`, dan `difference`
disimpan sebagai snapshot dan tidak dihitung ulang setelahnya.

Semua nilai **integer rupiah** (tanpa floating point), dibatasi `Money::MAX`.

---

## 3. Role & Tenant

| Aksi | owner | admin | cashier |
|------|:---:|:---:|:---:|
| List shift | semua di toko | semua di toko | hanya miliknya |
| Lihat detail | semua di toko | semua di toko | hanya miliknya |
| Buka shift (milik sendiri) | ya | ya | ya |
| Tutup shift | semua di toko | semua di toko | hanya miliknya |
| Catat movement | hanya shift sendiri | hanya shift sendiri | hanya shift sendiri |

- `store_id`, `cashier_id`, `user_id`, `status`, `expected_cash`, `difference`
  **tidak pernah** diambil dari request.
- Shift tenant lain → **404**. Shift kasir lain di toko yang sama → **403**.
- Anggota toko tidak aktif ditolak oleh middleware `current.store`.

---

## 4. Endpoint

| Method | Path | Kemampuan |
|--------|------|-----------|
| GET | `/api/cash-sessions/current` | Shift terbuka milik pengguna (atau null) |
| GET | `/api/cash-sessions` | List (pagination + filter) |
| POST | `/api/cash-sessions/open` | Buka shift |
| GET | `/api/cash-sessions/{cashSession}` | Detail + ringkasan |
| POST | `/api/cash-sessions/{cashSession}/close` | Tutup + rekonsiliasi |
| GET | `/api/cash-sessions/{cashSession}/movements` | List movement |
| POST | `/api/cash-sessions/{cashSession}/movements` | Catat movement |

Tidak ada endpoint edit/delete movement (append-only).

### Filter list
`cashier_id` (hanya owner/admin), `status` (`open`/`closed`),
`date_from`, `date_to`, `per_page` (1–100), `page`.

---

## 5. Contoh

### Buka shift
```bash
curl -X POST -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"opening_cash":100000,"opening_notes":"Modal pagi"}' \
  http://localhost:8000/api/cash-sessions/open
```
Respons `201`:
```json
{
  "message": "Shift kas berhasil dibuka.",
  "data": {
    "id": 1, "cashier_id": 3, "status": "open",
    "opening_cash": 100000,
    "cash_in_total": 0, "cash_out_total": 0, "cash_sales_total": 0,
    "expected_cash": 100000, "actual_cash": null, "difference": null,
    "opened_at": "2026-10-10T01:00:00.000000Z", "closed_at": null
  }
}
```

### Catat kas masuk/keluar
```bash
curl -X POST -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"type":"cash_out","amount":20000,"reason":"Beli galon"}' \
  http://localhost:8000/api/cash-sessions/1/movements
```

### Tutup shift
```bash
curl -X POST -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"actual_cash":130000,"closing_notes":"Kas sesuai"}' \
  http://localhost:8000/api/cash-sessions/1/close
```
Respons `200`: status `closed`, `expected_cash`, `actual_cash`, `difference`.

### Shift saat ini
```bash
curl -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  http://localhost:8000/api/cash-sessions/current
```
Jika tidak ada: `{"data":{"current_cash_session":null}}`.

---

## 6. Kebijakan Payment Tunai & Void

- Pembayaran tunai **wajib** memiliki shift terbuka milik pencatat
  (`cash_session_required` bila tidak).
- `payments.cash_session_id` diisi server (tidak dari request).
- Overpayment, perhitungan `paid_amount`, dan `payment_status` tetap tidak
  berubah.
- Void pembayaran tunai pada shift **terbuka**: diperbolehkan (mengurangi
  `cash_sales_total`).
- Void pembayaran tunai pada shift **tertutup**: **ditolak** (`cash_session_closed`)
  agar rekonsiliasi historis tidak berubah diam-diam.
- Pembayaran non-tunai tidak terkait shift dan tidak terpengaruh aturan tutup.

---

## 7. Kode Error

| Status | `code` | Kondisi |
|--------|--------|---------|
| 401 | `unauthenticated` / `token_required` | Auth |
| 409 | `current_store_not_selected` / `current_store_unavailable` | Konteks toko |
| 409 | `cash_session_already_open` | Kasir sudah punya shift terbuka |
| 409 | `cash_session_already_closed` | Menutup shift yang sudah ditutup |
| 409 | `cash_session_closed` | Movement/void/payment pada shift tertutup |
| 409 | `cash_session_required` | Pembayaran tunai tanpa shift terbuka |
| 403 | - | Kasir mengakses shift kasir lain; movement bukan milik sendiri |
| 404 | - | Shift/movement tenant lain atau tidak ada |
| 422 | - | Validasi (uang negatif, reason kosong, tipe invalid, dll.) |

---

## 8. Migration

Baru (belum diterapkan ke DB development):

```
2026_10_10_000100_create_cash_sessions_table
2026_10_10_000200_create_cash_movements_table
2026_10_10_000300_add_cash_session_id_to_payments_table
```

Urutan dependency: `cash_sessions` → `cash_movements` → `payments.cash_session_id`.

### Jaminan satu shift terbuka
Kolom `cash_sessions.open_guard` (nullable, unique) diisi `"<store_id>:<cashier_id>"`
oleh `CashSessionService` saat buka dan `NULL` saat tutup. Unique index
mencegah dua shift terbuka untuk kasir/toko yang sama, tetapi mengizinkan
riwayat shift tertutup tanpa batas. Race dup-key ditangkap → **409**, bukan 500.

> Catatan: pendekatan generated column MySQL sempat dicoba tetapi InnoDB
> menolak menambahkan foreign key pada tabel dengan stored generated column
> (error 1215), sehingga dipakai kolom guard transaksional.

### Penerapan manual (setelah review)
```powershell
php artisan migrate:status
php artisan migrate
```

> **Penting:** DB development saat ini memiliki tabel `cash_sessions` sisa dari
> kegagalan migration parsial (lihat laporan Phase 3A). Lihat bagian recovery di
> laporan; kemungkinan perlu `DROP TABLE IF EXISTS cash_sessions;` sebelum
> `php artisan migrate`.

---

## 9. Menjalankan Test

Feature tests (SQLite in-memory):
```powershell
php artisan test --filter=CashSessionTest
php artisan test
```

Concurrency (MySQL khusus, lihat `docs/concurrency-testing.md`):
```powershell
php tests/Concurrency/run.php
```

> Jangan menjalankan test dengan config ter-cache (`php artisan optimize`),
> karena phpunit.xml env bisa terabaikan dan test menyentuh database asli.
> `Tests\TestCase` sudah menghapus `bootstrap/cache/config.php` sebelum boot
> sebagai pengaman.
