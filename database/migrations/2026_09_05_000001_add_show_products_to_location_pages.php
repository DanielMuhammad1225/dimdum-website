<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saklar section produk pada Halaman Slug Lokasi.
 *
 * Default false: halaman yang sudah ada tidak boleh berubah tampilannya
 * hanya karena migration ini dijalankan.
 *
 * SENGAJA tanpa index. Kolom ini tidak pernah dipakai untuk menyaring daftar
 * halaman -- ia hanya dibaca pada satu baris yang sudah ditemukan lewat slug.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('location_pages', 'show_products')) {
            return;
        }

        Schema::table('location_pages', function (Blueprint $table) {
            $table->boolean('show_products')->default(false)->after('is_featured');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('location_pages', 'show_products')) {
            return;
        }

        Schema::table('location_pages', function (Blueprint $table) {
            $table->dropColumn('show_products');
        });
    }
};
