<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saklar "Tampilkan di Bio" pada produk.
 *
 * TERPISAH dari show_on_homepage: halaman Bio dan homepage adalah dua
 * tampilan publik berbeda, dan admin boleh memilih isi yang berbeda untuk
 * masing-masing.
 *
 * Default FALSE, dan baris yang sudah ada TIDAK dinyalakan. Tidak ada produk
 * yang muncul di Bio tanpa keputusan admin -- berbeda dengan backfill saklar
 * homepage halaman lokasi, di sini tidak ada tampilan lama yang perlu
 * dipertahankan, karena halaman Bio belum pernah ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'show_on_bio')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('show_on_bio')->default(false)->after('show_on_homepage');

            // Halaman /bio/produk: saring lalu urutkan.
            $table->index(['is_active', 'show_on_bio', 'sort_order'], 'products_bio_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('products', 'show_on_bio')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_bio_index');
            $table->dropColumn('show_on_bio');
        });
    }
};
