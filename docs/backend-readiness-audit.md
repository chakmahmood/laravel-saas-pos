# Backend Readiness Audit — Checkpoint 5

> Audit & hardening untuk kesiapan integrasi FE Admin Panel.
> Status: **PASS** (dengan catatan low-severity yang terdokumentasi).
> Tanggal: 2026-10-10. Tidak ada migrasi diterapkan ke development DB.

---

## 1. Metode

Audit dilakukan dengan membaca kode aktual (`routes/api.php`, controller, Form
Request, resource, service, model, migration, policy, middleware), menjalankan
seluruh test suite, guard test, Pint, `migrate:status`, concurrency harness
MySQL (skenario 1–12), serta probe terarah pada database khusus test untuk
memverifikasi perilaku lintas MySQL/SQLite. Angka laporan checkpoint sebelumnya
tidak dianggap berlaku sebelum dijalankan ulang.

Hasil: **306 tests / 1274 assertions, 0 warning/error**; concurrency 1–12 PASS;
`migrate:status` menunjukkan migrasi inventory masih `Pending`.

---

## 2. Inventaris Endpoint (aktual)

### Publik (throttle:auth)
- `POST /api/auth/register`
- `POST /api/auth/login`

### Terautentikasi (auth:sanctum)
- `POST /api/auth/logout`
- `GET /api/me`
- `GET /api/current-store`, `PUT /api/current-store`

### Bisnis (auth:sanctum + current.store)
- Categories: `GET/POST /api/categories`, `GET/PUT/PATCH/DELETE /api/categories/{category}`
- Items: `GET/POST /api/items`, `GET/PUT/PATCH/DELETE /api/items/{item}`
- Customers: `GET/POST /api/customers`, `GET/PUT/PATCH/DELETE /api/customers/{customer}`
- Orders: `GET/POST /api/orders`, `GET /api/orders/{order}`, `PATCH /api/orders/{order}/fulfillment`
- Payments: `GET/POST /api/orders/{order}/payments`, `GET /api/payments/{payment}`, `POST /api/payments/{payment}/void`
- Cash sessions: `GET /api/cash-sessions/current`, `GET /api/cash-sessions`, `POST /api/cash-sessions/open`, `GET /api/cash-sessions/{cashSession}`, `POST /api/cash-sessions/{cashSession}/close`, `GET/POST /api/cash-sessions/{cashSession}/movements`

### Inventory (auth:sanctum + current.store + EnsureInventoryEnabled)
- `GET/POST /api/stock/locations`, `GET/PUT/PATCH/DELETE /api/stock/locations/{stockLocation}`
- `GET /api/stock/balances`, `GET /api/items/{item}/stock`
- `GET /api/stock/movements` (read-only)
- `POST /api/stock/opening-balances`, `POST /api/stock/receipts`, `POST /api/stock/adjustments`

---

## 3. Matriks Authorization (terverifikasi dari policy)

| Aksi | owner | admin | cashier | store non-inventory |
|------|:---:|:---:|:---:|:---:|
| Read catalog/customer/location/balance/ledger | ✓ | ✓ | ✓ | 403 pada endpoint inventory |
| Mutate category/item/customer/location | ✓ | ✓ | ✗ (403) | - |
| Order create/read/fulfillment | ✓ | ✓ | ✓ | - |
| Order cancel | ✓ | ✓ | ✗ (403) | - |
| Payment record/read | ✓ | ✓ | ✓ | - |
| Payment void | ✓ | ✓ | ✗ (403) | - |
| Cash open/own shift, movement | ✓ | ✓ | ✓ (hanya shift sendiri) | - |
| Cash close | ✓ | ✓ | shift sendiri | - |
| Inventory opening/receipt/adjustment | ✓ | ✓ | ✗ (403) | 403 `inventory_not_available` |

Tidak ada `super_admin` platform; semua role bersifat per-store
(`store_user.role`). Role tidak dapat dinaikkan lewat input (tidak ada endpoint
membership; role register di-hardcode `owner`).

---

## 4. Tenant Isolation

- Store aktif selalu dari token (`EnsureCurrentStore` → `CurrentStoreService`),
  tidak pernah dari payload.
- Semua resource di-resolve lewat relasi store (`$store->relation()->findOrFail`),
  sehingga resource tenant lain → 404.
- Relasi bertingkat divalidasi: `customer_id`/`item_id` pada order, order pada
  payment, lokasi/item pada inventory — semua di-scope store.
- Respons error tidak membocorkan nama kelas internal/ID tenant lain (lihat §6).
- Test isolasi tenant tersedia untuk setiap resource + `TenantIsolationTest`.

---

## 5. Idempotency & Lock Ordering

### Peta urutan lock (tidak ada siklus → tidak ada deadlock)

| Operasi | Urutan lock |
|---------|-------------|
| Order create | store → (order insert) → stock_balances |
| Fulfillment commit/cancel | order → stock_balances |
| Payment record | order → cash_session |
| Payment void | order → cash_session → payment |
| Cash open | unique `open_guard` (tanpa row lock) |
| Cash close | cash_session |
| Cash movement | cash_session |
| Opening / receipt / adjustment | store → stock_balance |
| Provisioning default location | store |

Semua transaksi yang menyentuh store mengunci store lebih dulu; semua yang
menyentuh order mengunci order sebelum balance. Tidak ditemukan urutan terbalik.

### Idempotency

- Order flow: kunci deterministik per order item (`order:{id}:item:{id}:{event}`)
  + guard `orders.stock_committed_at` / keberadaan movement pelepasan.
- Opening/receipt/adjustment: `idempotency_key` unik per store + `request_fingerprint`
  (sha256 operasi|store|lokasi|item|millis|detail). Kuantitas dinormalisasi ke
  integer milli sehingga `"5"` dan `"5.000"` identik. Retry key+payload sama →
  satu efek; key sama payload beda → 409 `idempotency_conflict`.
- Unique `(store_id, idempotency_key)` adalah backstop DB; pengecekan dilakukan
  setelah lock store sehingga tidak ada race check-then-insert.

---

## 6. Temuan

### Critical
Tidak ada.

### High
1. **Penghapusan store tidak konsisten antar database (MySQL vs SQLite).**
   Menghapus store yang memiliki baris inventory gagal di MySQL dengan
   FK RESTRICT (`stock_balances_item_id_foreign`, error 1451), sedangkan di
   SQLite berhasil. Tidak ada endpoint delete store, sehingga tidak menghasilkan
   HTTP 500 hari ini, tetapi ini hazard integritas dan hazard test (test SQLite
   bisa lulus sementara MySQL gagal).
   **Bukti (MySQL, DB khusus test):**
   `SQLSTATE[23000] ... stock_balances_item_id_foreign ... ON DELETE RESTRICT`
   saat `delete from stores`.
   **Rekomendasi (belum diimplementasikan, menunggu keputusan):** definisikan
   kebijakan lifecycle store — larang hard-delete store yang punya histori
   finansial/inventory, atau sediakan prosedur hapus yang menghapus child
   (movement → balance → location/item → order/payment/cash) dalam urutan benar
   di dalam satu transaksi. Jangan membuat endpoint delete baru tanpa keputusan.

2. **`APP_DEBUG=true` / `APP_ENV=local` pada `.env` dan `.env.example`.**
   Jika dideploy apa adanya, exception tak tertangani membocorkan stack trace.
   Bukan kode aplikasi (dan `.env` tidak boleh diubah pada checkpoint ini);
   mitigasi: pastikan environment produksi memakai `APP_ENV=production` dan
   `APP_DEBUG=false`. Handler 404/403 baru (§7) sudah aman meski debug aktif.

### Medium
Tidak ada yang tersisa. (Rate limiting auth sebelumnya tidak ada — sudah
diperbaiki di §7.)

### Low
1. **Full-project `pint --test` gagal pada 6 file legacy** (5 migrasi Phase 0 +
   `DatabaseSeeder`) karena `braces_position`/`no_unused_imports`. Pre-existing,
   di luar checkpoint, tidak diubah agar tidak melakukan perubahan tak terkait.
   Semua file relevan checkpoint 1–5 lulus Pint.
2. **`PUT /api/current-store`**: `store_id` divalidasi `required|integer` tanpa
   `min:1`; nilai 0 tetap ditolak (403) oleh service. Dampak nol.
3. **Adjustment no-op tidak tercatat idempotency** (delta 0 tidak membuat
   movement), sehingga retry dengan key sama setelah saldo berubah dapat
   menghasilkan movement. Ini sesuai semantik "set ke hasil hitung" dan
   didokumentasikan; bukan double-apply.
4. **Payment record tanpa idempotency key** (kontrak payment belum
   mendukungnya). Overpayment tetap dicegah oleh lock + sisa tagihan.
5. **`item_id` pada order item dapat menjadi NULL** setelah item dihapus
   (snapshot tetap menjaga histori). Sesuai desain.

---

## 7. Perbaikan yang Diterapkan (hardening, dengan regresi test)

1. **Rate limiting endpoint auth** (`login`, `register`): dua bucket —
   per-IP (30/menit) dan per email+IP (5/menit). Limit dipasang di group
   `auth` (`throttle:auth`) dan didefinisikan di `AppServiceProvider`.
   Test: `tests/Feature/AuthRateLimitTest.php`.
2. **Kontrak error aman & konsisten**: `NotFoundHttpException` → 404
   `{message, code:"not_found"}`; `AccessDeniedHttpException` → 403
   `{message, code:"forbidden"}`. Menghilangkan kebocoran nama kelas Eloquent
   pada 404 (mis. `No query results for model [App\Models\Order] 999999`) dan
   membuat 403 punya `code` yang stabil. Definisi di `bootstrap/app.php`.
   Test: `tests/Feature/ErrorContractTest.php`.

---

## 8. Hasil Verifikasi

| Pemeriksaan | Hasil |
|-------------|-------|
| Full suite | 306 passed / 1274 assertions, 0 warning/error |
| Guard (`TestDatabaseGuardTest`, `TestEnvironmentGuardTest`) | 11 passed / 17 assertions |
| Pint (file relevan) | passed |
| Pint (seluruh proyek) | 6 file legacy pra-eksisting gagal (Low) |
| Concurrency MySQL 1–12 | PASS (DB `saas_pos_concurrency_test`) |
| `migrate:status` dev DB | 000400–000900 `Pending` |

Concurrency: 1 order numbers, 2 overpayment, 3 void race, 4 item quota,
5 open shift, 6 last-unit reserve, 7 double commit, 8 provisioning,
9 opening race, 10 concurrent receipts, 11 concurrent adjustments,
12 same-key receipt retry — seluruhnya PASS.

---

## 9. Rekomendasi Checkpoint 6 — API Contract & FE Preparation

1. **Keputusan store lifecycle** (High): tentukan larangan/arsip store vs
   prosedur hapus aman; lalu implementasi + test MySQL.
2. **Environment produksi**: dokumen checklist `APP_ENV=production`,
   `APP_DEBUG=false`, HTTPS, CORS, dan rate-limit tuning.
3. **Kontrak API final untuk FE**: publikasikan OpenAPI/Postman; samakan code
   error (422/409/403/404) di seluruh endpoint; pertimbangkan `code` pada 401.
4. **Rate limiting lanjutan** (opsional): throttle per-endpoint untuk mutasi
   berat dan `throttle:api` global.
5. **Housekeeping**: jalankan `vendor/bin/pint` sekali untuk 6 file legacy.
6. **Observability**: struktur logging tanpa data sensitif + monitoring
   eksternal (di luar scope checkpoint ini).
7. **Menerapkan migrasi inventory ke development DB** setelah persetujuan.
