# Category API — Universal POS

> Status: **Sudah diimplementasikan (Phase 1a)**.
> Semua endpoint berada di bawah `auth:sanctum` + `current.store`.
> Prefix `/api`, tanpa versioning.

Kategori adalah bagian dari **Universal POS** dan dipakai oleh semua jenis
bisnis (`business_type`). Kategori selalu dimiliki tepat satu toko.

---

## 1. Daftar Endpoint

| Method | Path | Kemampuan | Role |
|--------|------|-----------|------|
| GET | `/api/categories` | List (paginate, search, filter, sort) | owner, admin, cashier |
| POST | `/api/categories` | Buat kategori | owner, admin |
| GET | `/api/categories/{category}` | Detail kategori | owner, admin, cashier |
| PUT | `/api/categories/{category}` | Ganti kategori (nama wajib) | owner, admin |
| PATCH | `/api/categories/{category}` | Update sebagian | owner, admin |
| DELETE | `/api/categories/{category}` | Hapus kategori | owner, admin |

`{category}` adalah **ID kategori**, bukan slug. ID di luar toko aktif
diperlakukan sama seperti ID tidak ada (**404**).

---

## 2. Model Data

| Field | Tipe | Catatan |
|-------|------|---------|
| `id` | bigint | |
| `name` | string(100) | unik per toko |
| `description` | text, nullable | |
| `is_active` | boolean, default `true` | |
| `created_at` | ISO-8601 | |
| `updated_at` | ISO-8601 | |

`store_id` **tidak** diekspos di response dan **tidak** pernah diambil dari
request. Kepemilikan selalu berasal dari toko aktif pada token.

Unique constraint: `(store_id, name)`. Dua toko berbeda boleh memakai nama
yang sama.

> Catatan collation: pada MySQL (collation default case-insensitive), nama yang
> hanya berbeda kapitalisasi dianggap duplikat; pada SQLite (test) pencocokan
> bersifat case-sensitive. Uji duplikat memakai nama identik.

---

## 3. Autentikasi & Tenant Isolation

- Setiap request memakai header `Authorization: Bearer <token>`.
- Middleware `current.store` memvalidasi bahwa token memiliki
  `current_store_id` dan user masih anggota aktif toko tersebut.
- Toko aktif diambil server-side dari
  `$request->attributes->get('current_store')`.
- Semua query dibatasi ke toko aktif. Detail/update/delete memakai relasi
  `$store->categories()->findOrFail($id)` sehingga kategori milik tenant lain
  menghasilkan **404**, bukan 403 (tidak membocorkan keberadaan tenant lain).
- Field `store_id` yang dikirim client **diabaikan**.

Kontrak error middleware (tidak berubah):

| Status | `code` | Kondisi |
|--------|--------|---------|
| 401 | `unauthenticated` | Token tidak ada / tidak valid |
| 401 | `token_required` | Bukan token API dengan konteks toko |
| 409 | `current_store_not_selected` | Token belum punya toko aktif |
| 409 | `current_store_unavailable` | Toko aktif tidak valid lagi (hapus/nonaktif/membership dicabut) |

---

## 4. Aturan Role

Role dibaca dari `current_store_role` (server-derived), **bukan** dari request.

| Aksi | owner | admin | cashier |
|------|:---:|:---:|:---:|
| List / Detail | ya | ya | ya |
| Buat | ya | ya | tidak (403) |
| Update (PUT/PATCH) | ya | ya | tidak (403) |
| Hapus | ya | ya | tidak (403) |

Otorisasi memakai Laravel Policy `App\Policies\CategoryPolicy`
(`viewAny`, `view`, `create`, `update`, `delete`). Tidak ada paket permission
baru.

---

## 5. Validasi

### Buat (POST)
- `name`: **wajib**, string, maksimal **100** karakter, unik dalam toko.
- `description`: opsional, nullable, string, maksimal **1000** karakter.
- `is_active`: opsional, boolean (`true`/`false`/`1`/`0`). Default `true`.

### Update (PUT)
- `name`: **wajib** (semantik replace).
- `description`: opsional/nullable.
- `is_active`: opsional.

### Update (PATCH)
- Semua field opsional; hanya field yang dikirim yang divalidasi/diubah.
- Jika `name` dikirim, tetap wajib string dan unik (selain dirinya sendiri).

Field yang tidak dikenal diabaikan (tidak error), mengikuti konvensi validasi
proyek. `store_id` diabaikan.

---

## 6. List, Pagination, Search, Filter, Sort

Query parameter:

| Parameter | Tipe | Default | Aturan |
|-----------|------|---------|--------|
| `page` | integer | 1 | min 1 |
| `per_page` | integer | 15 | min 1, max 100 |
| `search` | string | - | maks 100; `LIKE %term%` pada `name` |
| `is_active` | `true`/`false`/`1`/`0` | - | filter status |
| `sort` | `name`/`created_at` | `name` | whitelist |
| `direction` | `asc`/`desc` | `asc` | whitelist |

Pengurutan selalu stabil: kolom `sort` lalu `id` sebagai tie-breaker.

Respons list memakai format paginator Laravel (`data`, `links`, `meta`):

```json
{
  "data": [
    {
      "id": 1,
      "name": "Minuman",
      "description": "Semua minuman",
      "is_active": true,
      "created_at": "2026-10-09T02:00:00.000000Z",
      "updated_at": "2026-10-09T02:00:00.000000Z"
    }
  ],
  "links": {
    "first": "http://localhost:8000/api/categories?page=1",
    "last": "http://localhost:8000/api/categories?page=2",
    "prev": null,
    "next": "http://localhost:8000/api/categories?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 2,
    "per_page": 15,
    "to": 15,
    "total": 31
  }
}
```

---

## 7. Contoh Penggunaan

### List
```bash
curl -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  "http://localhost:8000/api/categories?per_page=20&search=min&is_active=true"
```

### Buat
```bash
curl -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"name":"Minuman","description":"Semua minuman","is_active":true}' \
  http://localhost:8000/api/categories
```
Respons `201`:
```json
{
  "message": "Kategori berhasil dibuat.",
  "data": {
    "id": 1,
    "name": "Minuman",
    "description": "Semua minuman",
    "is_active": true,
    "created_at": "2026-10-09T02:00:00.000000Z",
    "updated_at": "2026-10-09T02:00:00.000000Z"
  }
}
```

### Detail
```bash
curl -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  http://localhost:8000/api/categories/1
```
Respons `200`: `{"data": { ... }}`.

### Update (PUT)
```bash
curl -X PUT -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"name":"Minuman & Kopi","description":null,"is_active":true}' \
  http://localhost:8000/api/categories/1
```

### Update sebagian (PATCH)
```bash
curl -X PATCH -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"is_active":false}' \
  http://localhost:8000/api/categories/1
```

### Hapus
```bash
curl -X DELETE -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  http://localhost:8000/api/categories/1
```
Respons `200`: `{"message":"Kategori berhasil dihapus."}`.

---

## 8. Aturan Penghapusan

- Penghapusan saat ini **hard delete** (bukan soft delete). Tidak ada kolom
  `deleted_at`.
- Menghapus kategori **tidak** menghapus data bisnis lain secara cascading.
- **Phase 1b (rencana):** setelah tabel `items` ada dan relasi
  `Category::items()` dibuat, kategori yang masih dipakai item **tidak boleh
  dihapus**. Controller akan memeriksa keterkaitan dan mengembalikan **409**
  dengan pesan jelas. FK `items.category_id` direncanakan `nullOnDelete`
  sehingga penghapusan tidak pernah menghapus item.
- Saat ini belum ada tabel `items`, sehingga belum ada pemeriksaan keterkaitan.

---

## 9. Kompatibilitas

- MySQL 8 (produksi) dan SQLite `:memory:` (test) didukung.
- Foreign key `categories.store_id → stores.id` dengan `cascadeOnDelete`
  (menghapus store menghapus kategori miliknya). FK diaktifkan pada SQLite
  test.
- Migrasi: `2026_10_09_000100_create_categories_table`.
