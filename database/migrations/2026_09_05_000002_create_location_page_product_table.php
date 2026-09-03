<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Produk yang dipilih untuk sebuah Halaman Slug Lokasi.
 *
 * Pivot ini hanya menyimpan RELASI. Nama, kategori, tipe, harga, foto, dan
 * deskripsi tetap milik tabel products -- tidak satu pun disalin ke sini,
 * supaya tidak pernah ada dua versi kebenaran untuk produk yang sama.
 *
 * Kedua sisi cascadeOnDelete, dan itu BERBEDA dari location_page_location
 * yang memakai restrictOnDelete pada gerobaknya. Alasannya bukan
 * ketidakkonsistenan:
 *
 *   gerobak  master data operasional yang tidak boleh bisa hilang selama
 *            masih dipasang di halaman iklan yang beredar; penghapusannya
 *            harus ditolak supaya admin sadar.
 *   produk   sudah punya soft delete sebagai jalur normal. Yang sampai ke
 *            force delete berarti memang dibuang permanen dari katalog --
 *            menyisakan baris pivot yang menunjuk produk yang tidak ada lagi
 *            hanya akan menghasilkan referensi rusak, bukan perlindungan.
 *
 * Produk yang di-SOFT delete tetap punya baris pivotnya, sehingga pemulihan
 * mengembalikannya ke halaman apa adanya.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('location_page_product')) {
            return;
        }

        Schema::create('location_page_product', function (Blueprint $table) {
            $table->id();

            $table->foreignId('location_page_id')->constrained('location_pages')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            $table->timestamps();

            // Satu produk tidak boleh terpasang dua kali pada halaman yang
            // sama. Dijaga DATABASE, bukan hanya oleh sync() aplikasi.
            $table->unique(['location_page_id', 'product_id'], 'location_page_product_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_page_product');
    }
};
