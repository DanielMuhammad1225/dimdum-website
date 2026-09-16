<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Membuang dua kolom gerobak yang digantikan hierarki.
 *
 * filter_label dulu memaksa gerobak dikelompokkan lewat string bebas karena
 * tingkat Area belum menjadi entitas. Sekarang Area sungguhan yang menjadi
 * induk, jadi mempertahankannya berarti menyediakan dua cara mengelompokkan
 * hal yang sama -- dan keduanya bisa bertentangan.
 *
 * province dibuang karena provinsi kini diturunkan dari
 * Location -> LocationArea -> LocationGroup -> Province.
 *
 * Yang TETAP disimpan pada gerobak: village, district, city_regency,
 * postal_code. Ketiganya adalah FAKTA ALAMAT POS, bukan hierarki:
 *  - city_regency tetap perlu karena satu Kota/Grup bertipe pemasaran boleh
 *    mencakup lebih dari satu kota/kabupaten;
 *  - district tetap perlu sebagai bagian alamat, bukan sebagai pengelompokan.
 */
return new class extends Migration
{
    public function up(): void
    {
        $existing = DB::table('locations')->count();

        if ($existing > 0) {
            throw new RuntimeException(
                "Migration dihentikan: tabel locations berisi {$existing} baris. "
                .'Membuang filter_label dan province akan menghilangkan data yang '
                .'sudah diisi. Pindahkan nilainya ke hierarki lebih dulu.'
            );
        }

        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn(['filter_label', 'province']);
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->string('filter_label')->nullable()->after('slug');
            $table->string('province')->nullable()->after('city_regency');
        });
    }
};
