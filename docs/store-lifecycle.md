# Store Lifecycle Policy

> Checkpoint 6. Kebijakan resmi siklus hidup store, konsistensi MySQL/SQLite,
> dan perlindungan histori.

## 1. Prinsip

1. **Tidak ada penghapusan fisik store melalui API.** Store yang memiliki
   histori bisnis (order, order item, payment, cash session, saldo/ledger
   inventory) **tidak boleh** dihapus secara fisik oleh operasi aplikasi biasa.
2. **Arsip/deaktivasi, bukan hapus.** Penghentian penggunaan store dilakukan
   dengan menonaktifkan store (`stores.is_active = false`). Semua histori tetap
   utuh.
3. **Hanya satu mekanisme status.** `stores.is_active` (store) **berbeda** dari
   `store_user.is_active` (membership user). Menonaktifkan store tidak mengubah
   membership, dan sebaliknya.

## 2. Mekanisme yang tersedia

| Konsep | Kolom | Arti |
|--------|-------|------|
| Status store | `stores.is_active` | Store dapat dipakai (true) atau diarsipkan (false). |
| Status membership | `store_user.is_active` | User masih boleh mengakses store. |
| Current store | `personal_access_tokens.current_store_id` | Store aktif per token. |

Tidak ada kolom `deleted_at`/soft delete pada `stores`, dan tidak ada endpoint
delete/arsip store. Deaktivasi saat ini dilakukan di level data/administratif
(seeder, tinker, prosedur operator) — bukan melalui API publik.

## 3. Perilaku

### 3.1 Akses (implementasi saat ini)

`App\Services\CurrentStoreService::findAccessibleStore()` menganggap store dapat
diakses hanya bila **semua** kondisi berikut terpenuhi:

- store ada,
- `stores.is_active = true`,
- user punya membership dengan `store_user.is_active = true`.

Akibatnya:

- Token yang menunjuk store nonaktif → `409 current_store_unavailable`; referensi
  `current_store_id` pada token dibersihkan.
- `PUT /api/current-store` ke store nonaktif → `403 store_not_accessible`.
- Semua endpoint bisnis menolak store nonaktif (termasuk operasi baca) karena
  store tidak dapat dijadikan current store.

### 3.2 Tidak membuat transaksi baru

Store nonaktif tidak dapat dipilih sebagai current store, sehingga **tidak ada
transaksi baru** (order, payment, cash session, mutasi inventory) yang dapat
dibuat untuk store tersebut.

### 3.3 Histori tetap tersimpan

Menonaktifkan store adalah operasi UPDATE pada `stores.is_active`; tidak ada
cascade delete. Order, payment, cash session, saldo, dan ledger inventory tetap
ada dan dapat direkonsiliasi dari database. Ini dikunci oleh test
`tests/Feature/StoreLifecycleTest.php`.

## 4. Temuan H1 (Checkpoint 5) dan keputusan

Sebelumnya (Checkpoint 5) ditemukan bahwa menghapus store berisi inventory
gagal di MySQL (`FK RESTRICT` pada `stock_balances`/`stock_movements`) tetapi
berhasil di SQLite.

**Keputusan: jangan mengubah FK menjadi cascade.** Perlindungan histori lebih
diutamakan. `RESTRICT` pada `stock_balances.item_id/stock_location_id` dan
`stock_movements.item_id/stock_location_id` adalah backstop database yang
mencegah item/lokasi beriwayat terhapus.

Karena tidak ada endpoint delete store, tidak ada risiko HTTP 500. Bila kelak
store benar-benar perlu dihapus, lakukan **prosedur terkendali** (menghapus child
dalam urutan: movement → balance → stock location/item → order/payment/cash)
atau tolak penghapusan store yang beriwayat — bukan cascade otomatis.

## 5. Keputusan yang masih tertunda (butuh persetujuan)

**Akses baca ke histori store nonaktif.** Requirement menyatakan: store nonaktif
tidak boleh bertransaksi, tetapi historinya tetap dapat dibaca sesuai permission.
Saat ini store nonaktif **tidak dapat diakses sama sekali** (termasuk baca)
karena `findAccessibleStore` mensyaratkan `stores.is_active = true`.

Mengubah ini memerlukan perubahan arsitektur yang lebih luas:

1. Mengizinkan pemilihan store nonaktif (untuk baca) —
   `findAccessibleStore` tidak lagi mensyaratkan `is_active`.
2. Menambahkan middleware baru `EnsureActiveStore` pada seluruh route mutasi
   (owner/admin yang menulis) sementara route baca tetap diizinkan.

Ini menyentuh ~40 route dan semua policy, sehingga **dihentikan pada keputusan**
agar tidak membuat perubahan berisiko. Opsi yang perlu diputuskan produk:
(a) tetap seperti sekarang (arsip = tidak terlihat), atau (b) implementasi
read-only history store nonaktif dengan middleware mutasi eksplisit.

## 6. Super-admin platform

Tidak ada role super-admin platform. Semua role (`owner`/`admin`/`cashier`)
bersifat per-store pada `store_user`. Tidak ada mekanisme yang memberi hak lintas
store otomatis. Penambahan admin platform adalah keputusan arsitektur terpisah.

## 7. Test

- `tests/Feature/StoreLifecycleTest.php` — deaktivasi menyimpan histori;
  store nonaktif tidak bisa transaksi; delete store tidak diekspos.
- `tests/Feature/CurrentStoreTest.php` — store/membership nonaktif tidak dapat
  dipilih; token stale dibersihkan.
- `tests/Feature/ErrorContractTest.php` — kontrak error 401/403/404/422/429.
