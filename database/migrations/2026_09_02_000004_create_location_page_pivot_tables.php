<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dua relasi halaman slug, dengan tugas yang berbeda.
 *
 *   location_page_group    -> CAKUPAN. Kota/Grup yang boleh menyumbang
 *                             kandidat gerobak ke halaman ini.
 *   location_page_location -> ISI. Gerobak yang benar-benar dipilih admin
 *                             untuk ditampilkan.
 *
 * Cakupan tidak otomatis menjadi isi: gerobak baru di Kota/Grup yang sama
 * TIDAK ikut muncul sampai admin memilihnya. Itu disengaja -- halaman iklan
 * tidak boleh berubah isinya sendiri.
 *
 * Provinsi dan Area tidak disimpan ulang: keduanya bisa diturunkan dari
 * rantai Gerobak -> Area -> Kota/Grup -> Provinsi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_page_group', function (Blueprint $table) {
            $table->id();

            // Halaman dihapus permanen -> barisnya ikut hilang.
            $table->foreignId('location_page_id')->constrained('location_pages')->cascadeOnDelete();

            /*
             | restrictOnDelete: Kota/Grup tidak boleh dihapus permanen selama
             | masih menjadi cakupan sebuah halaman. Database yang menjadi
             | penjaga terakhir, bukan hanya policy aplikasi.
             */
            $table->foreignId('location_group_id')->constrained('location_groups')->restrictOnDelete();

            $table->timestamps();

            $table->unique(['location_page_id', 'location_group_id'], 'location_page_group_unique');
            $table->index('location_group_id', 'location_page_group_group_index');
        });

        Schema::create('location_page_location', function (Blueprint $table) {
            $table->id();

            $table->foreignId('location_page_id')->constrained('location_pages')->cascadeOnDelete();

            // restrictOnDelete: gerobak yang masih dipakai halaman tidak boleh
            // hilang permanen tanpa dilepas lebih dulu.
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();

            $table->timestamps();

            $table->unique(['location_page_id', 'location_id'], 'location_page_location_unique');
            $table->index('location_id', 'location_page_location_location_index');

            /*
             | Tidak ada kolom urutan di sini. Urutan kartu gerobak mengikuti
             | urutan master (Provinsi -> Kota/Grup -> Area -> Gerobak), jadi
             | sistem urutan kedua hanya akan menjadi sumber kebenaran yang
             | bersaing.
             */
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_page_location');
        Schema::dropIfExists('location_page_group');
    }
};
