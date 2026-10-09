# Tenant Isolation & Current Store

Dokumen ini menjelaskan bagaimana SaaS POS menentukan **current store**,
bagaimana tenant isolation dijaga, dan pola yang wajib diikuti untuk setiap
modul bisnis baru (Category, Product, Inventory, Sales, Reports).

## 1. Ringkasan

Sistem menggunakan model multi-tenant berbasis **store**. Setiap user dapat
menjadi anggota beberapa store melalui tabel pivot `store_user` (dengan
`role` dan `is_active`). Setiap **Sanctum token** menyimpan store aktifnya
sendiri pada kolom `personal_access_tokens.current_store_id`.

Tidak ada endpoint bisnis yang menerima `store_id` dari client sebagai sumber
otorisasi. Otorisasi tenant selalu berasal dari token yang tervalidasi di
server.

## 2. Mengapa current store disimpan per token

Kolom `current_store_id` disimpan pada tabel `personal_access_tokens`, bukan
pada tabel `users`.

Alasan:

- Satu user dapat login dari beberapa device/aplikasi sekaligus. Setiap device
  memiliki token sendiri dan boleh berada di store yang berbeda.
- Mengubah store aktif dari satu device **tidak boleh** mengubah store aktif
  device lain milik user yang sama.
- Store aktif adalah konteks request, bukan atribut permanen user.

## 3. Alur autentikasi dan validasi membership

1. `POST /api/auth/register` membuat user, store pertama, membership `owner`,
   langganan plan Free, dan token. Store pertama dipasang sebagai
   `current_store_id` pada token tersebut.
2. `POST /api/auth/login` memvalidasi kredensial lalu membuat token baru. Store
   aktif pertama (aktif + membership aktif) dipasang sebagai
   `current_store_id`. Jika user tidak punya store yang bisa diakses, nilainya
   `NULL` dan user harus memilih store terlebih dahulu.
3. `GET /api/me` mengembalikan user dan daftar store yang dapat diakses
   (store aktif + membership aktif).
4. `GET /api/current-store` mengembalikan store aktif token saat ini
   (`id`, `name`, `slug`, `role`, `is_active`).
5. `PUT /api/current-store` memilih store aktif **untuk token yang dipakai
   request**. Hanya store yang menjadi membership aktif user yang diterima.

Semua validasi store berpusat pada satu service:

```
App\Services\CurrentStoreService
```

- `findAccessibleStore(User $user, int $storeId): ?Store`
- `resolveForToken(User $user, PersonalAccessToken $token): ?Store`
- `persist(PersonalAccessToken $token, ?int $storeId): void`

Controller dan middleware **tidak boleh** menulis ulang query membership
sendiri; gunakan service ini.

## 4. Middleware `current.store`

Middleware: `App\Http\Middleware\EnsureCurrentStore`.

Terdaftar sebagai alias di `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->alias([
        'current.store' => EnsureCurrentStore::class,
    ]);
})
```

Middleware melakukan, secara berurutan:

1. Memastikan ada user terautentikasi (Sanctum). Jika tidak -> `401`.
2. Memastikan request memakai token API (bukan session/transient token).
   Jika tidak -> `401`.
3. Memastikan token punya `current_store_id`. Jika belum -> `409`
   (`current_store_not_selected`).
4. Memvalidasi store: harus ada, `stores.is_active = true`, dan user punya
   membership `store_user.is_active = true`. Jika tidak valid -> referensi
   dibersihkan dan -> `409` (`current_store_unavailable`).
5. Menyimpan store tervalidasi ke request attributes:

```php
$store = $request->attributes->get('current_store');
$role  = $request->attributes->get('current_store_role');
```

Middleware **tidak** dipasang pada:

- `POST /api/auth/register`
- `POST /api/auth/login`
- `POST /api/auth/logout`
- `GET /api/me`
- `GET /api/current-store`
- `PUT /api/current-store`

Endpoint tersebut tidak membutuhkan tenant, atau justru dipakai untuk memilih
tenant.

## 5. Status HTTP yang digunakan

| Status | Kondisi | `code` |
|--------|---------|--------|
| `401` | Tidak terautentikasi / token tidak valid | `unauthenticated` |
| `401` | Endpoint butuh token API dengan konteks toko | `token_required` |
| `409` | Terautentikasi tetapi token belum punya current store | `current_store_not_selected` |
| `409` | Current store token sudah tidak valid (hilang/nonaktif/membership dicabut) | `current_store_unavailable` |
| `403` | Memilih store yang tidak dapat diakses user | `store_not_accessible` |
| `422` | Format request tidak valid (mis. `store_id` bukan integer) | bawaan validasi |

Keputusan 409: kondisi "terautentikasi tapi belum/tidak lagi punya konteks
tenant" adalah konflik state, bukan kegagalan otorisasi (403) maupun
unauthorized (401). Client harus memilih store lewat `PUT /api/current-store`.

Store yang tidak ada dan store milik tenant lain sengaja menghasilkan respons
**403 yang sama** agar keberadaan tenant lain tidak bocor (enumeration).

## 6. Melindungi endpoint bisnis baru

Daftarkan endpoint bisnis di dalam group `auth:sanctum` **dan**
`current.store`:

```php
use App\Http\Controllers\Api\ProductController;

Route::middleware(['auth:sanctum', 'current.store'])
    ->group(function () {
        Route::get('/products', [ProductController::class, 'index']);
        Route::post('/products', [ProductController::class, 'store']);
        Route::get('/products/{product}', [ProductController::class, 'show']);
        Route::put('/products/{product}', [ProductController::class, 'update']);
        Route::delete('/products/{product}', [ProductController::class, 'destroy']);
    });
```

Di controller, ambil store dari request attributes (jangan dari input):

```php
public function store(Request $request): JsonResponse
{
    $store = $request->attributes->get('current_store');

    $product = DB::transaction(function () use ($request, $store) {
        return $store->products()->create([
            // store_id ditentukan server, bukan oleh client
            'name' => $request->string('name'),
        ]);
    });

    return response()->json(['data' => $product], 201);
}
```

## 7. Memastikan query dibatasi ke current store

Prinsip wajib untuk semua modul bisnis:

- Semua tabel bisnis memiliki kolom `store_id` dengan foreign key ke `stores`
  dan index `(store_id, ...)`.
- **Create**: `store_id` selalu diambil dari `$request->attributes->get('current_store')`,
  bukan dari body/query.
- **Index**: selalu `where('store_id', $store->id)`.
- **Show / Update / Delete**: cari record lewat relasi store, mis.
  `$store->products()->findOrFail($id)`, sehingga record milik store lain
  menghasilkan 404 tanpa membocorkan data.
- Untuk unik per tenant, gunakan composite unique, mis.
  `unique(['store_id', 'sku'])` (bukan `unique(['sku'])`).
- Gunakan `DB::transaction(...)` untuk operasi multi-tabel.
- Jangan mengandalkan filter di Flutter; UI bukan mekanisme keamanan.

### Global scope: hindari untuk saat ini

Kami **tidak** memakai global scope pada tahap ini. Alasan: global scope mudah
lupa dinonaktifkan (`withoutGlobalScope`) dan sulit didiagnosis, serta dapat
menyembunyikan query lintas tenant yang sah (mis. job admin). Pola eksplisit
`store_id` di setiap query lebih mudah dibaca dan diuji. Bila nanti dipakai,
wajib: (1) hanya di model yang benar-benar tenant-scoped, (2) sediakan escape
hatch yang jelas untuk operasi lintas tenant, dan (3) tutup dengan test.

## 8. Menjalankan test

```sh
php artisan test
```

Test memakai SQLite in-memory (lihat `phpunit.xml`) dan `RefreshDatabase`,
sehingga tidak menyentuh database development. Test terkait:

- `tests/Feature/AuthFlowTest.php` - register, login, logout, `/api/me`,
  penolakan token kosong/dicabut.
- `tests/Feature/CurrentStoreTest.php` - GET/PUT current store, ganti store,
  isolasi antar token, penolakan membership nonaktif / store nonaktif,
  token tanpa / dengan current store tidak valid.
- `tests/Feature/TenantIsolationTest.php` - isolasi data antar store,
  `store_id` dari client tidak mengubah otorisasi, respons error tidak
  membocorkan data tenant lain.

Helper test ada di `tests/Concerns/InteractsWithTenants.php`.

## 9. Batasan implementasi saat ini

- Belum ada model bisnis (Category/Product/Sales), jadi tenant isolation
  diuji melalui route hipotetis `_test/business` di dalam test. Test
  end-to-end untuk record bisnis ditambahkan saat model pertama dibuat.
- Belum ada granular permission per role. `role` pada membership baru dipakai
  sebagai informasi; otorisasi aksi per role akan ditambahkan terpisah
  (kemungkinan dengan Spatie Permission atau policy).
- Belum ada strategi pemilihan **last-active store**; login memilih store
  aktif pertama berdasarkan ID.
- Belum ada device/session management.
- Billing/payment, Redis, queue, dan sharding belum termasuk.
