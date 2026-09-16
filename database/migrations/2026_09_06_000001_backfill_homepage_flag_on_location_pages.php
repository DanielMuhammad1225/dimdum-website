<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pertahankan tampilan homepage yang berlaku sebelum saklar ini ada.
 *
 * Sampai sebelum ini, is_featured hanya menentukan URUTAN: SELURUH halaman
 * yang tampil publik dan punya gerobak ikut masuk daftar lokasi di homepage.
 * Sejak kolom itu menjadi penentu KEANGGOTAAN, halaman lama yang nilainya
 * false akan hilang dari homepage begitu kode baru berjalan -- padahal admin
 * tidak pernah memutuskan untuk menyembunyikannya.
 *
 * Karena itu baris yang SUDAH ADA dinyalakan, supaya perilaku yang terlihat
 * pengunjung tidak berubah tanpa diminta. Halaman BARU tetap default false:
 * saklar yang bersifat opt-in adalah yang benar untuk ke depannya, dan
 * admin memutuskannya sendiri saat membuat halaman.
 *
 * Hanya menyentuh kolom ini. Tidak ada baris yang dibuat maupun dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('location_pages', 'is_featured')) {
            return;
        }

        DB::table('location_pages')
            ->where('is_featured', false)
            ->update(['is_featured' => true]);
    }

    /**
     * SENGAJA kosong.
     *
     * Nilai sebelumnya tidak dicatat di mana pun, jadi mengembalikan seluruh
     * baris ke false akan menebak -- dan tebakan itu justru menyembunyikan
     * halaman yang memang sengaja dinyalakan admin sesudahnya.
     */
    public function down(): void {}
};
