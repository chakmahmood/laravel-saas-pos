# Production Deployment Checklist

> Checkpoint 6. Checklist operasional sebelum deploy. Keberadaan dokumen ini
> **tidak** berarti aplikasi sudah production-ready. Lihat §12 Migration
> Readiness dan §13 Status.

## 1. Environment
- [ ] `APP_ENV=production`
- [ ] `APP_DEBUG=false` (wajib; mencegah stack trace bocor ke response)
- [ ] `APP_KEY` di-generate (`php artisan key:generate`) dan disimpan aman
- [ ] `APP_URL` sesuai domain publik
- [ ] `APP_TIMEZONE` tetap `UTC` (API mengirim waktu ISO-8601 UTC)
- [ ] `LOG_LEVEL` sesuai (mis. `warning`/`error` di produksi)

## 2. Database
- [ ] Kredensial DB hanya di environment, **tidak** di Git
- [ ] Koneksi produksi = MySQL 8 (InnoDB)
- [ ] Backup otomatis + prosedur restore teruji
- [ ] Migration dijalankan lewat prosedur deployment yang disengaja
      (`php artisan migrate --force`), bukan otomatis tanpa kontrol
- [ ] Lihat §12 untuk kesiapan migration inventory

## 3. Keamanan & Web
- [ ] HTTPS aktif (redirect HTTP → HTTPS) dan HSTS
- [ ] CORS (`config/cors.php`) dibatasi ke domain FE resmi
- [ ] Sanctum: token-based API; bila memakai cookie SPA, atur
      `SANCTUM_STATEFUL_DOMAINS` dan cookie `Secure`/`SameSite`
- [ ] Rate limiting ditinjau: `throttle:auth` (login/register) + pertimbangkan
      `throttle:api` global
- [ ] Trusted proxies dikonfigurasi bila di belakang load balancer
- [ ] Header keamanan dasar (X-Frame-Options/X-Content-Type-Options) sesuai kebijakan

## 4. Logging & Privasi
- [ ] Log tidak merekam token, password, atau data rahasia
- [ ] `APP_DEBUG=false` sehingga response 5xx generik
- [ ] Error monitoring (mis. Sentry) opsional — di luar scope checkpoint ini
- [ ] Log rotation / retensi diatur

## 5. Storage & Queue
- [ ] `FILESYSTEM_DISK` sesuai (lokal/object storage) dan permission direktori benar
- [ ] Bila memakai queue: `QUEUE_CONNECTION` produksi, worker supervisor
- [ ] Bila memakai scheduler: cron `php artisan schedule:run`
- [ ] Saat ini aplikasi belum bergantung pada queue/scheduler untuk alur inti

## 6. Cache & Session
- [ ] `CACHE_STORE`/`SESSION_DRIVER` sesuai skala (mis. redis)
- [ ] Konfigurasi cookie session (secure, http_only, same_site)

## 7. Health & Observability
- [ ] Health check `/up` dipantau
- [ ] Error rate & latensi dipantau
- [ ] Alerting kegagalan 5xx

## 8. Dependency & Tooling
- [ ] `composer install --no-dev --optimize-autoloader`
- [ ] Debug tooling nonaktif di produksi (Telescope/Pulse/Nightwatch/IGNITION
      `PULSE_ENABLED=false`, dll)
- [ ] `php artisan config:cache route:cache view:cache` dijalankan
- [ ] Tidak ada dependency berbayar/layanan eksternal yang belum disetujui

## 9. Kualitas Rilis
- [ ] `php artisan test` hijau
- [ ] `vendor/bin/pint --test` hijau
- [ ] Review diff & `git status`
- [ ] Concurrency harness dijalankan pada DB uji MySQL (bukan `saas_pos_db`)

## 10. CI/CD
- [ ] Pipeline test + Pint
- [ ] Strategi migrasi (backward compatible, additive)
- [ ] Rollback plan

## 11. Akun & Data
- [ ] Seeder produksi (mis. `PlanSeeder`) dijalankan/diperiksa
- [ ] Tidak ada data uji yang tertinggal
- [ ] Akun owner pertama dibuat dengan aman

## 12. Migration Readiness (status aktual)

Migration inventory berikut **belum diterapkan** ke development database
`saas_pos_db` (masih `Pending`) dan juga belum ke database lain:

| Migration | Isi | Ketergantungan |
|-----------|-----|----------------|
| `2026_10_10_000400_add_tracks_stock_to_items_table` | `items.tracks_stock` | - |
| `2026_10_10_000500_create_stock_locations_table` | lokasi stok | stores |
| `2026_10_10_000600_create_stock_balances_table` | saldo | stock_locations, items |
| `2026_10_10_000700_create_stock_movements_table` | ledger | stock_locations, items, orders, order_items, users, self |
| `2026_10_10_000800_add_stock_columns_to_orders_table` | `orders.stock_location_id`, `stock_committed_at` | stock_locations |
| `2026_10_10_000900_add_request_fingerprint_to_stock_movements_table` | `stock_movements.request_fingerprint` | stock_movements |

- **Kesiapan kode migration:** sudah ada, additive, aman, diuji pada SQLite
  (suite) dan MySQL (`saas_pos_concurrency_test`).
- **Kesiapan database:** database target **belum** memiliki skema ini. Aplikasi
  tidak boleh dianggap production-ready untuk modul inventory sampai migration
  dijalankan.
- Menjalankan migration ke `saas_pos_db` memerlukan **persetujuan terpisah** dan
  tidak dilakukan pada checkpoint ini.

## 13. Status

Checklist ini adalah panduan. Aplikasi **belum** dinyatakan production-ready.
Blocker utama: (a) migration inventory belum diterapkan ke DB target, (b)
keputusan store lifecycle (akses baca histori store nonaktif) masih tertunda,
(c) `APP_DEBUG=false`/environment produksi harus diverifikasi di sisi deployment.
