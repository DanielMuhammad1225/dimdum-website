<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Buang tabel redirect slug hierarki.
 *
 * Provinsi dan Area tidak lagi memiliki URL publik, sehingga tidak ada URL
 * yang bisa mati ketika namanya berganti -- riwayat slug-nya menjadi tidak
 * bermakna. Riwayat slug kini hanya relevan bagi Halaman Slug Lokasi, yang
 * punya tabel redirect sendiri.
 *
 * Migration ini menolak berjalan bila tabelnya masih berisi data, supaya
 * redirect yang benar-benar terpakai tidak hilang diam-diam.
 */
return new class extends Migration
{
    /** @var list<string> */
    protected array $tables = [
        'location_area_slug_redirects',
        'location_province_slug_redirects',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $rows = DB::table($table)->count();

            if ($rows > 0) {
                throw new RuntimeException(
                    "Migration dihentikan: tabel {$table} berisi {$rows} baris redirect. "
                    .'Menghapusnya akan mematikan URL lama yang mungkin masih beredar. '
                    .'Periksa dan pindahkan isinya lebih dulu.'
                );
            }
        }

        foreach ($this->tables as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        /*
         | Sengaja tidak membangun ulang tabelnya. Struktur lamanya melekat
         | pada kolom slug Provinsi/Area yang sudah dibuang oleh migration
         | berikutnya, jadi memulihkannya di sini hanya akan menghasilkan
         | tabel yatim.
         */
    }
};
