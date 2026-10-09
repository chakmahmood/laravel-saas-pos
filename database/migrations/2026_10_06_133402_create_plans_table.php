<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();

            $table->string('name', 100);
            $table->string('slug', 100)->unique();

            $table->text('description')->nullable();

            // Harga dalam satuan terkecil mata uang.
            // Contoh: Rp 49.000 disimpan sebagai 49000.
            $table->unsignedBigInteger('price_monthly')->default(0);
            $table->unsignedBigInteger('price_yearly')->default(0);

            // Batas jumlah toko yang dapat dimiliki akun.
            $table->unsignedInteger('max_stores')->default(1);

            // Batas user/staff per toko.
            $table->unsignedInteger('max_users_per_store')->default(1);

            // Batas produk per toko.
            // NULL = tidak terbatas.
            $table->unsignedInteger('max_products')->nullable();

            // Batas transaksi per bulan.
            // NULL = tidak terbatas.
            $table->unsignedInteger('max_transactions_per_month')->nullable();

            $table->boolean('is_active')->default(true);

            // Urutan tampilan paket.
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
