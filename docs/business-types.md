# Tipe Bisnis, Katalog, dan Template Workflow

Dokumen ini menjelaskan pemisahan konsep pada Universal POS SaaS setelah
refaktor canonical business type.

## 1. Dua tipe bisnis utama (canonical)

`stores.business_type` (`App\Enums\BusinessType`) hanya memiliki **dua** nilai:

| Nilai | Label UI | Cakupan | Inventory |
|-------|----------|---------|-----------|
| `retail` | Toko & Penjualan | Warung, toko kelontong, minimarket, toko pakaian/barang, **kafe & restoran**, usaha penjualan produk/menu | Ya |
| `service` | Jasa & Servis | Laundry, bengkel, salon, reparasi elektronik, jasa layanan lain | Tidak |

Kafe/restoran **bukan** tipe terpisah; keduanya `retail`. Laundry/bengkel/salon
**bukan** tipe terpisah; semuanya `service`.

Registrasi toko (`POST /api/auth/register`) **wajib** menyertakan
`business_type`. Nilai tak dikenal ditolak `422`.

## 2. Kompatibilitas nilai lama

Store yang dibuat sebelum refaktor dapat menyimpan nilai lama di kolom
`stores.business_type`. Aplikasi tetap berjalan melalui pemetaan canonical
(`App\Enums\BusinessType::canonicalize`, dipakai `App\Casts\BusinessTypeCast`):

| Nilai lama | Grup canonical |
|-----------|----------------|
| `retail` | `retail` |
| `restaurant` | `retail` |
| `laundry` | `service` |
| `repair` | `service` |
| `salon` | `service` |
| `other` | `service` |

- **Baca**: nilai lama dipetakan ke canonical sehingga API/UI tidak pernah
  menerima nilai non-canonical.
- **Tulis**: model selalu menyimpan nilai canonical.
- **Input register**: nilai lama masih diterima dan dinormalisasi (expand).
- **Backfill**: `2026_10_12_000000_normalize_store_business_type.php`
  menormalkan baris lama. Migrasi ini **belum dijalankan** dan bersifat
  data-only (tanpa perubahan skema).

Urutan deployment: deploy kode kompatibel lebih dulu → jalankan backfill →
verifikasi tidak ada nilai lama. Bersifat expand/backfill; tidak destruktif.

## 3. Pemisahan konsep

| Konsep | Menentukan | Representasi |
|--------|-----------|--------------|
| Business type | Kelompok utama aplikasi | `stores.business_type` (`retail`/`service`) |
| Workflow profile | Detail operasional usaha (template) | **Belum dipersistensi** — lihat §4 |
| Item type | Isi katalog | `items.type` (`product`/`service`/`menu`/`package`) |
| Category | Pengelompokan katalog | `categories` (per store), bukan penentu tipe bisnis |

`business_type` **bukan** `items.type`. Katalog tetap satu tabel universal
(`items`); tidak ada tabel produk terpisah per industri.

## 4. Template workflow (konsep, belum diimplementasikan)

Template workflow menggambarkan alur operasional spesifik di dalam grup
`service`:

- `general_service` (default), `laundry`, `workshop`, `salon`.

Status saat ini:

- **Belum ada** kolom/tabel/enum persistensi untuk workflow profile.
- UI **belum** menyediakan pemilihan workflow.
- Ini adalah template, **bukan** business type baru.

Rencana (checkpoint terpisah, perlu persetujuan skema):

1. Tambah pengaturan `service_workflow` (mis. di tabel `store_settings` yang
   direncanakan) dengan default `general_service`; pilihan lanjutan tersedia
   sebagai **pengaturan proses kerja**, bukan pilihan tipe bisnis utama.
2. Entitas pekerjaan servis (mis. `service_jobs`) terhubung ke store,
   pelanggan, layanan/`items`, detail pekerjaan, status, tanggal, pembayaran.
3. Field khusus (berat kg, tanggal selesai, nomor kendaraan, keluhan) hanya
   pada pekerjaan servis, **bukan** pada katalog/transaksi retail.

Kebutuhan laundry/bengkel yang belum dibangun (berat desimal, status
pengerjaan, tanggal selesai, aset kendaraan) **sengaja ditunda** ke checkpoint
terpisah dan tidak diklaim siap.

## 5. Katalog per grup

- **Retail** berfokus produk/menu (`product`/`menu`), kategori produk, kasir,
  pembayaran, inventory (jika `tracks_stock`), laporan penjualan.
- **Service** berfokus layanan/paket (`service`/`package`). Produk tambahan
  (`product`) **opsional** dan tidak wajib. Bisnis jasa **tidak** diwajibkan
  membuat katalog produk sebelum melayani pelanggan.
- Item `tracks_stock` hanya mungkin pada grup `retail` (grup `service`
  menerima `403 inventory_not_available`).

## 6. Dampak API

- `BusinessType` pada OpenAPI: `enum: [retail, service]`.
- `POST /api/auth/register`: `business_type` wajib.
- `GET /api/me`, `GET /api/current-store`, respons register: `business_type`
  selalu canonical.
- Tidak ada endpoint yang mengubah `business_type` toko setelah dibuat.

Lihat juga: `docs/universal-pos-architecture.md` §8, `docs/api/openapi.yaml`,
`docs/api/frontend-integration-guide.md`.
