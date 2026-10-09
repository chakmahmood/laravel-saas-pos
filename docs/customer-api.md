# Customer API — Universal POS

> Status: **Sudah diimplementasikan (Phase 2)**.
> Semua endpoint berada di bawah `auth:sanctum` + `current.store`.

Customer adalah bagian dari **Universal POS** dan dipakai semua jenis bisnis.
Customer selalu dimiliki tepat satu toko.

---

## 1. Endpoint

| Method | Path | Kemampuan | Role |
|--------|------|-----------|------|
| GET | `/api/customers` | List (paginate, search, sort) | owner, admin, cashier |
| POST | `/api/customers` | Buat customer | owner, admin |
| GET | `/api/customers/{customer}` | Detail | owner, admin, cashier |
| PUT | `/api/customers/{customer}` | Ganti (name wajib) | owner, admin |
| PATCH | `/api/customers/{customer}` | Update sebagian | owner, admin |
| DELETE | `/api/customers/{customer}` | Arsipkan (soft delete) | owner, admin |

`{customer}` adalah **ID customer**. ID milik toko lain → **404** (sama seperti
ID tidak ada).

---

## 2. Field & Validasi

| Field | Tipe DB | Validasi |
|-------|---------|----------|
| `id` | bigint | - |
| `store_id` | FK stores | server-set, tidak dari request |
| `name` | string(150) | `required, string, max:150` |
| `phone` | string(30), nullable | `nullable, string, max:30` |
| `email` | string(150), nullable | `nullable, email, max:150` |
| `address` | text, nullable | `nullable, string, max:1000` |
| `notes` | text, nullable | `nullable, string, max:1000` |
| `created_at`/`updated_at` | timestamp | ISO-8601 |
| `deleted_at` | timestamp, nullable | soft delete (arsip) |

Field `store_id` dari client diabaikan.

### Kebijakan duplikasi

- **Tidak ada unique** pada `phone` atau `email`, baik global maupun per toko.
- Alasan: anggota keluarga dapat berbagi nomor telepon; customer walk-in dapat
  tercatat berulang; dan unique gabungan dengan nilai NULL di MySQL berperilaku
  membingungkan. Deteksi duplikat adalah tanggung jawab aplikasi (mis. peringatan
  di UI), bukan constraint database.
- Ini **dokumentasi keputusan**: duplicate diperbolehkan.

---

## 3. List, Pencarian, Pagination

| Parameter | Tipe | Default | Aturan |
|-----------|------|---------|--------|
| `page` | integer | 1 | min 1 |
| `per_page` | integer | 15 | min 1, max 100 |
| `search` | string | - | maks 100; mencari `name`, `phone`, `email` |
| `sort` | `name`/`created_at` | `name` | whitelist |
| `direction` | `asc`/`desc` | `asc` | whitelist |

Customer yang diarsipkan (`deleted_at` tidak null) tidak muncul di list.

---

## 4. Contoh

### Buat
```bash
curl -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"name":"Budi Santoso","phone":"081234567890","email":"budi@example.com"}' \
  http://localhost:8000/api/customers
```
Respons `201`:
```json
{
  "message": "Pelanggan berhasil dibuat.",
  "data": {
    "id": 1,
    "name": "Budi Santoso",
    "phone": "081234567890",
    "email": "budi@example.com",
    "address": null,
    "notes": null,
    "created_at": "2026-10-09T04:00:00.000000Z",
    "updated_at": "2026-10-09T04:00:00.000000Z"
  }
}
```

### List
```bash
curl -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  "http://localhost:8000/api/customers?search=budi&per_page=20"
```

### Arsipkan
```bash
curl -X DELETE -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  http://localhost:8000/api/customers/1
```
Respons `200`: `{"message":"Pelanggan berhasil diarsipkan."}`.

---

## 5. Kebijakan Penghapusan (Arsip)

- `DELETE` melakukan **soft delete** (`deleted_at` diisi), bukan hard delete.
- Customer yang diarsipkan **tetap terhubung** ke order historis; Order API
  memuat customer dengan `withTrashed()` sehingga nama tetap tampil.
- Customer yang diarsipkan tidak dapat dipilih untuk order baru
  (validasi `Rule::exists(...)->whereNull('deleted_at')`).
- FK `orders.customer_id` memakai `nullOnDelete` sebagai jaring pengaman bila
  customer dihapus permanen di luar API; histori order tidak pernah dihapus.
- Tidak ada endpoint untuk menghapus customer secara permanen.

---

## 6. Authorization & Tenant Isolation

- Policy `App\Policies\CustomerPolicy`: owner/admin kelola; cashier baca saja.
- Semua query dibatasi `store_id` toko aktif (`$store->customers()`).
- Customer tenant lain tidak dapat dibaca/diubah/dihapus (404).
- `store_id` client diabaikan.

---

## 7. Keterbatasan

- Belum ada merge/deduplikasi customer.
- Belum ada atribut tambahan (tanggal lahir, tipe, dsb.).
- Belum ada endpoint restore customer yang diarsipkan.
