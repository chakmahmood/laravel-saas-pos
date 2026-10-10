# Concurrency Testing (MySQL)

> Status: **Terverifikasi pada MySQL 8.4.3** (Phase 2.1).
> Harness: `tests/Concurrency/`.

Dokumen ini menjelaskan cara membuktikan perilaku concurrency yang **tidak**
dapat diverifikasi oleh test suite SQLite (`:memory:`), karena SQLite tidak
mendukung `SELECT ... FOR UPDATE`.

---

## 1. Prinsip keamanan

- Harness memakai **database MySQL khusus**: `saas_pos_concurrency_test`.
- `tests/Concurrency/boot.php` **menolak berjalan** jika nama database bukan
  `saas_pos_concurrency_test` (guard keras terhadap database development
  `saas_pos_db`).
- Koneksi di-`config()` + `DB::purge()` di runtime sehingga tidak bisa
  dikembalikan ke dev DB oleh `.env`.
- Harness **tidak** menjalankan `migrate:fresh`, `db:wipe`, `truncate`, atau
  `DROP`. Hanya `CREATE DATABASE IF NOT EXISTS` dan `migrate` (idempoten).
- Fixtures memakai store/user unik per skenario, jadi akumulasi data tidak
  mengganggu hasil.

---

## 2. Cara menjalankan

```sh
php tests/Concurrency/run.php
```

Opsional menentukan nama DB (harus tetap `saas_pos_concurrency_test`):

```sh
CONCURRENCY_DB=saas_pos_concurrency_test php tests/Concurrency/run.php
```

Runner akan:
1. Membuat DB jika belum ada, menjalankan `migrate`.
2. Menjalankan setiap skenario dengan **banyak proses PHP paralel** memakai
   `proc_open`, disinkronkan lewat barrier file (semua worker menunggu file
   `GO` sebelum masuk critical section).
3. Memeriksa invariant dan mencetak `PASS`/`FAIL`.

Tidak dijalankan sebagai bagian dari `php artisan test`.

---

## 3. Skenario & hasil (MySQL 8.4.3)

| Skenario | Beban | Hasil | Invariant |
|----------|-------|-------|-----------|
| Order numbers | 10 worker buat order toko sama | `successes=10 distinct_numbers=10 db_orders=10` | Nomor unik, tidak ada tabrakan |
| Overpayment | 8 worker bayar 5.000 atas order 10.000 | `successes=2 validation_failures=6 paid_sum=10000 status=paid` | Total dibayar == total order, tidak overpaid |
| Void race | 4 worker void payment yang sama | `successes=1 conflicts=3 payment_status=voided` | Tepat satu void, status order dihitung ulang |
| Item quota | 12 worker buat item, limit plan 5 | `successes=5 limit_failures=7 items_in_db=5` | Tidak melewati kuota |
| Open shift | 6 worker buka shift kasir/toko sama | `successes=1 conflicts=5 open_shifts_in_db=1` | Tepat satu shift terbuka (unique `open_guard`) |

Kesimpulan: **PASS** — `lockForUpdate` pada baris `stores` (nomor order, kuota
item) dan baris `orders`/`cash_sessions` (pembayaran/void/shift) benar-benar
men-serialisasi akses pada MySQL InnoDB. Pembukaan shift juga dijaga unique index
`cash_sessions.open_guard`.

### 3.1 Idempotensi order & pembayaran (Phase 3A)

Skenario 13 dan 14 (`run.php`) ditambahkan bersama hardening idempotensi order
dan pembayaran. Hasil aktual (`php tests/Concurrency/run.php`, MySQL 8.4.3 /
InnoDB, exit code `0`):

| Skenario | Beban | Hasil | Invariant |
|----------|-------|-------|-----------|
| Idempotent order | 4 worker, `idempotency_key=sc13-shared` | `successes=4 distinct_order_ids=1 orders_in_db=1` | Tepat 1 order; semua worker menerima order id yang sama |
| Idempotent payment | 4 worker bayar order sama, `idempotency_key=sc14-shared` | `successes=4 distinct_payment_ids=1 payments_in_db=1 paid=10000 status=paid` | Tepat 1 pembayaran; order lunas tepat sekali |

Verifikasi independen pada database pengujian: order `sc13-shared` tersimpan
tepat 1 baris; payment `sc14-shared` tersimpan tepat 1 baris; order terkait
`paid_amount=10000` dan `payment_status=paid`. Migration idempotensi diterapkan
**hanya** pada `saas_pos_concurrency_test`; `saas_pos_db` tidak menerima
migration ini (dikonfirmasi via `information_schema`).

---

## 4. Batasan

- Harness membuktikan pada **satu mesin, multi-proses**; bukan uji beban
  terdistribusi.
- Test suite reguler (`php artisan test`) tetap memakai SQLite dan **bukan**
  bukti concurrency; hanya protokol & logika.
- Item quota memakai `CatalogItemService` (lock baris store). Order memakai
  `OrderService`; payment/void memakai `PaymentService` (lock baris order).
- Sejak Phase 3A, skenario pembayaran tunai membuka shift untuk pemilik lebih
  dulu (pembayaran tunai kini memerlukan shift terbuka).
