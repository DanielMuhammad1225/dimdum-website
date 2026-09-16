<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menempatkan Area di bawah Kota/Grup.
 *
 * Arti tabel location_areas TIDAK berubah: ia tetap Area operasional. Yang
 * ditambahkan hanyalah induknya, sehingga Provinsi dapat diturunkan lewat
 * Area -> LocationGroup -> Province tanpa menyimpan province_id redundan.
 *
 * Kolom string province dan city_regency dibuang: keduanya dulu menjadi
 * pengganti sementara hierarki yang belum ada, dan kini menjadi sumber
 * kebenaran kedua yang bisa bertentangan dengan induk sebenarnya.
 *
 * location_group_id sengaja NOT NULL. Area tanpa induk tidak punya URL yang
 * sah, jadi membiarkannya nullable hanya memindahkan kesalahan ke runtime.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         | Kolom NOT NULL tanpa default hanya boleh ditambahkan selagi tabel
         | kosong. Bila ternyata sudah ada Area, migration BERHENTI alih-alih
         | mengarang induk untuk baris yang sudah ada.
         */
        $existing = DB::table('location_areas')->count();

        if ($existing > 0) {
            throw new RuntimeException(
                "Migration dihentikan: tabel location_areas berisi {$existing} baris. "
                .'Koreksi hierarki ini dirancang untuk tabel kosong. Tentukan Kota/Grup '
                .'untuk setiap Area yang sudah ada lebih dulu, lalu jalankan migration '
                .'pemindahan data tersendiri.'
            );
        }

        Schema::table('location_areas', function (Blueprint $table) {
            $table->foreignId('location_group_id')
                ->after('id')
                ->constrained('location_groups')
                ->restrictOnDelete();

            // Query publik: Area visible pada satu Kota/Grup, terurut.
            $table->index(
                ['location_group_id', 'is_active', 'published_at', 'sort_order'],
                'location_areas_group_visibility_index',
            );
        });

        Schema::table('location_areas', function (Blueprint $table) {
            // Provinsi kini diturunkan dari hierarki, bukan diketik ulang.
            $table->dropColumn(['province', 'city_regency']);
        });
    }

    public function down(): void
    {
        Schema::table('location_areas', function (Blueprint $table) {
            $table->string('province')->nullable();
            $table->string('city_regency')->nullable();
        });

        Schema::table('location_areas', function (Blueprint $table) {
            $table->dropIndex('location_areas_group_visibility_index');
            $table->dropForeign(['location_group_id']);
            $table->dropColumn('location_group_id');
        });
    }
};
