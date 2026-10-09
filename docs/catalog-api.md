# Catalog Item API — Universal POS

> Status: **Sudah diimplementasikan (Phase 1b)**.
> Semua endpoint berada di bawah `auth:sanctum` + `current.store`.
> Prefix `/api`, tanpa versioning.

Katalog universal memakai **satu tabel `items`** untuk semua jenis bisnis.
Tidak ada tabel `products` paralel.

---

## 1. Model & Tipe Item

Tipe diwakili enum PHP `App\Enums\ItemType`:

| Tipe | Nilai | Contoh |
|------|-------|--------|
| Produk | `product` | beras, baju, kosmetik |
| Jasa | `service` | potong rambut, servis motor, penggantian oli |
| Menu | `menu` | kopi susu, nasi goreng |
| Paket | `package` | paket cuci lengkap, paket servis |

Satu tabel yang sama menampung keempat tipe. Perbedaan perilaku diselesaikan
di lapisan modul, bukan dengan tabel terpisah.

---

## 2. Field & Aturan

| Field | Tipe DB | Validasi | Catatan |
|-------|---------|----------|---------|
| `id` | bigint | - | |
| `store_id` | FK stores | server-set | tidak pernah dari request |
| `category_id` | FK categories, nullable | `nullable, integer, exists (store aktif)` | harus satu toko |
| `name` | string(150) | `required, string, max:150` | tidak unik global |
| `type` | string(20) | `required, Rule::enum(ItemType)` | |
| `sku` | string(64), nullable | `nullable, string, max:64, unique per toko` | |
| `barcode` | string(64), nullable | `nullable, string, max:64, unique per toko` | |
| `description` | text, nullable | `nullable, string, max:2000` | |
| `cost_price` | unsignedBigInteger, nullable | `nullable, integer, min:0` | `null` = belum dilacak |
| `selling_price` | unsignedBigInteger | `required, integer, min:0` | rupiah integer |
| `unit` | string(20) | `nullable, string, max:20` | default `pcs` |
| `is_active` | boolean | `sometimes, boolean` | default `true` |
| `created_at`/`updated_at` | timestamp | - | ISO-8601 di response |

Catatan uang: **seluruh harga disimpan sebagai integer minor units**. Untuk IDR
artinya nilai rupiah bulat (`18000` = Rp18.000). Tidak memakai `float`/`double`.
`cost_price = null` berarti harga pokok tidak ditentukan/tidak dilacak; tidak
pernah diubah otomatis menjadi `0`.

`unit` tidak dibatasi hanya satuan eceran. Nilai sah misalnya `pcs`, `kg`,
`gram`, `liter`, `hour`, `service`, `porsi`. Panjang maksimum 20 karakter.

Field yang tidak dikenal (termasuk `store_id`) diabaikan dan tidak mengubah
model.

---

## 3. Endpoint

| Method | Path | Kemampuan | Role |
|--------|------|-----------|------|
| GET | `/api/items` | List (paginate, search, filter, sort) | owner, admin, cashier |
| POST | `/api/items` | Buat item | owner, admin |
| GET | `/api/items/{item}` | Detail item | owner, admin, cashier |
| PUT | `/api/items/{item}` | Ganti item (name/type/selling_price wajib) | owner, admin |
| PATCH | `/api/items/{item}` | Update sebagian | owner, admin |
| DELETE | `/api/items/{item}` | Hapus item | owner, admin |

`{item}` adalah **ID item**. ID item tenant lain diperlakukan sama seperti ID
tidak ada (**404**).

---

## 4. Contoh Request/Response

### Buat
```bash
curl -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"name":"Kopi Susu","type":"menu","selling_price":18000,"unit":"porsi","category_id":1,"cost_price":8000,"sku":"KOPI-01"}' \
  http://localhost:8000/api/items
```
Respons `201`:
```json
{
  "message": "Item berhasil dibuat.",
  "data": {
    "id": 1,
    "category_id": 1,
    "name": "Kopi Susu",
    "type": "menu",
    "sku": "KOPI-01",
    "barcode": null,
    "description": null,
    "cost_price": 8000,
    "selling_price": 18000,
    "unit": "porsi",
    "is_active": true,
    "created_at": "2026-10-09T03:00:00.000000Z",
    "updated_at": "2026-10-09T03:00:00.000000Z"
  }
}
```

### Update sebagian (PATCH)
```bash
curl -X PATCH -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"selling_price":20000,"is_active":false}' \
  http://localhost:8000/api/items/1
```

### List
```bash
curl -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  "http://localhost:8000/api/items?type=menu&is_active=true&search=kopi&per_page=20"
```

### Hapus
```bash
curl -X DELETE -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  http://localhost:8000/api/items/1
```
Respons `200`: `{"message":"Item berhasil dihapus."}`.

---

## 5. Filter & Pagination

| Parameter | Tipe | Default | Aturan |
|-----------|------|---------|--------|
| `page` | integer | 1 | min 1 |
| `per_page` | integer | 15 | min 1, max 100 |
| `search` | string | - | maks 100; mencari `name`, `sku`, `barcode` |
| `type` | enum | - | `product`/`service`/`menu`/`package` |
| `category_id` | integer | - | min 1 |
| `is_active` | `true`/`false`/`1`/`0` | - | filter status |
| `sort` | `name`/`created_at`/`selling_price` | `name` | whitelist |
| `direction` | `asc`/`desc` | `asc` | whitelist |

Pengurutan selalu stabil (`sort` lalu `id`). Respons list memakai format
paginator Laravel (`data`, `links`, `meta`).

---

## 6. Authorization & Tenant Isolation

- Middleware `auth:sanctum` + `current.store`; toko aktif berasal dari token,
  bukan request.
- Otorisasi via Policy `App\Policies\ItemPolicy` (`viewAny`, `view`, `create`,
  `update`, `delete`). Role dibaca dari `current_store_role`:
  - **owner/admin**: baca + kelola,
  - **cashier**: baca saja (mutasi → 403).
- Semua query dibatasi `store_id` toko aktif melalui `$store->items()`.
- Item tenant lain → **404** untuk show/update/delete.
- `store_id` dari client diabaikan; item tidak dapat dipindahkan antar toko.
- `category_id` tervalidasi hanya boleh menunjuk kategori **toko yang sama**
  (tenant lain → 422).

---

## 7. Unique SKU & Barcode

- Constraint database: `unique(store_id, sku)` dan `unique(store_id, barcode)`.
- `null` diizinkan; banyak item tanpa SKU/barcode dapat dibuat.
- Validasi aplikasi mengecualikan item yang sedang diperbarui (`ignore`).
- **Tidak** unik global: dua toko berbeda boleh memakai SKU yang sama.
- Validasi saja tidak cukup; request bersamaan dapat berlomba. Pelanggaran
  unique dari database ditangkap dan diterjemahkan menjadi **422** dengan pesan
  pada field `sku`/`barcode`.

### Perbedaan MySQL vs SQLite

| Aspek | MySQL 8 | SQLite (test) |
|-------|---------|---------------|
| NULL pada unique | Dianggap berbeda → banyak NULL diizinkan | Sama, banyak NULL diizinkan |
| Case sensitivity SKU/barcode | Collation default sering case-insensitive | Case-sensitive |
| Row locking (`FOR UPDATE`) | Didukung InnoDB | **Tidak didukung** (no-op) |
| Unsigned bigint | Ditegakkan | Tipe unsigned tidak ditegakkan |

Artinya, test SQLite **tidak sepenuhnya merepresentasikan** perilaku MySQL
terkait case sensitivity dan concurrency. Test duplikat memakai nilai identik.

---

## 8. Kuota Katalog (`max_products`)

**Definisi:** kuota menghitung **seluruh baris `items` per toko**, semua tipe
(`product`, `service`, `menu`, `package`), **item aktif dan nonaktif** sama-sama
dihitung. Item yang dihapus tidak dihitung.

- Sumber limit: `Plan::max_products` dari **subscription aktif** toko
  (`Store::activeSubscription` = `status = active`, `latestOfMany`).
  Subscription non-aktif dan plan-nya diabaikan.
- `max_products = null` → **unlimited** (didukung schema: kolom nullable).
- Tanpa subscription aktif → unlimited (belum ada penegakan billing pada fase
  ini; didokumentasikan sebagai risiko).
- Diperiksa **hanya saat create**. Update item yang sudah ada **tidak pernah**
  ditolak karena kuota penuh.
- Pelanggaran → **403** dengan `code: "item_limit_reached"`.

### Strategi concurrency (kuota)

`App\Services\CatalogItemService::create()`:

1. Membuka `DB::transaction`.
2. `Store::whereKey($id)->lockForUpdate()->firstOrFail()` — mengunci baris store.
3. Setelah lock diperoleh, hitung `items()->count()` dan bandingkan dengan
   limit.
4. Insert item dalam transaksi yang sama, lalu commit.

Semua pembuatan item **wajib** melalui service ini agar protokol lock konsisten
(dua create bersamaan untuk toko yang sama akan ter-serialisasi; request kedua
menghitung insert pertama).

**Terverifikasi (Phase 2.1):** pada MySQL 8.4.3, 12 worker paralel membuat item
untuk toko dengan limit plan 5 menghasilkan tepat 5 item (7 ditolak
`item_limit_reached`). SQLite (test suite) hanya memverifikasi protokol, bukan
isolasi paralel. Lihat `docs/concurrency-testing.md`.

**Batas:** row lock hanya nyata di MySQL 8 InnoDB. SQLite (test) tidak
mendukung `FOR UPDATE`, sehingga test suite reguler bukan bukti concurrency.

---

## 9. Error Codes

| Status | `code` | Kondisi |
|--------|--------|---------|
| 401 | `unauthenticated` | Token tidak ada/tidak valid |
| 401 | `token_required` | Bukan token API dengan konteks toko |
| 409 | `current_store_not_selected` | Token belum punya toko aktif |
| 409 | `current_store_unavailable` | Toko aktif tidak valid lagi |
| 409 | `category_in_use` | Kategori masih dipakai item (hapus kategori) |
| 403 | `item_limit_reached` | Kuota item paket tercapai |
| 403 | - | Role cashier melakukan mutasi |
| 404 | - | Item/kategori tidak ada atau milik tenant lain |
| 422 | - | Validasi payload/query gagal (termasuk SKU/barcode duplikat) |

---

## 10. Perilaku Penghapusan Kategori

- Kategori yang **masih dipakai** item tidak bisa dihapus → **409**
  `category_in_use`.
- Pengecekan aplikasi dilakukan lebih dulu; foreign key `items.category_id`
  **RESTRICT** menjadi pengaman untuk race antara cek dan delete (pelanggaran FK
  juga diterjemahkan menjadi 409 `category_in_use`).
- Menghapus kategori **tidak pernah** menghapus item (tidak ada cascade ke
  item).
- Kategori yang tidak dipakai tetap dapat dihapus sesuai role.
- Menghapus **store** menghapus item dan kategori miliknya (cascade dari
  `store_id`).

---

## 11. Risiko & Pengembangan Berikutnya

Belum diimplementasikan (rencana, butuh keputusan/persetujuan):

- **Varian, modifier, resep, komponen paket** — tabel ekstensi terpisah.
- **Soft delete** katalog — belum dipakai (menghindari konflik unique SKU).
- **Kuota varian** — varian belum dihitung pada `max_products`.
- **Test concurrency nyata** pada MySQL.
- **Kuota per tipe** atau penamaan ulang `max_products` → `max_catalog_items`.
- **Snapshot harga** dikonsumsi saat Order API dibuat.
- Tanpa subscription aktif dianggap unlimited (perlu penegakan billing).
