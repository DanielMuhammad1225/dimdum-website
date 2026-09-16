<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saklar "Tampilkan di Bio" pada Halaman Slug Lokasi.
 *
 * TERPISAH dari is_featured ("Tampilkan di Homepage"): halaman yang tampil
 * di homepage belum tentu layak dipasang di Bio, dan sebaliknya.
 *
 * Default FALSE, dan baris yang sudah ada TIDAK dinyalakan. Halaman Bio belum
 * pernah ada, jadi tidak ada tampilan lama yang perlu dipertahankan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('location_pages', 'show_on_bio')) {
            return;
        }

        Schema::table('location_pages', function (Blueprint $table) {
            $table->boolean('show_on_bio')->default(false)->after('show_products');

            // Mode location_pages di /bio/lokasi: saring lalu urutkan.
            $table->index(['is_active', 'show_on_bio', 'sort_order'], 'location_pages_bio_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('location_pages', 'show_on_bio')) {
            return;
        }

        Schema::table('location_pages', function (Blueprint $table) {
            $table->dropIndex('location_pages_bio_index');
            $table->dropColumn('show_on_bio');
        });
    }
};
