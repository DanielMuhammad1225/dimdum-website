<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengaturan halaman Bio (singleton).
 *
 * SATU baris, dikunci lewat kolom unik 'key' -- pola yang sama dengan
 * site_settings dan homepage_settings. Bukan sistem multi-Bio.
 *
 * Yang SENGAJA tidak ada di sini:
 *
 *   URL tombol  Tujuan tombol Lokasi dan Menu berasal dari named route di
 *               kode, dan tombol WhatsApp dibentuk service dari nomor yang
 *               tervalidasi. Kolom URL bebas akan membuka jalan bagi tautan
 *               mati maupun tautan berbahaya pada halaman yang dibagikan ke
 *               luar situs.
 *   URL lokasi  /bio/lokasi selalu satu alamat; mode sumber datanya saja
 *               yang dipilih.
 *
 * is_active default FALSE: halaman Bio tidak boleh terbit sebelum admin
 * memutuskannya sendiri.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bio_settings')) {
            return;
        }

        Schema::create('bio_settings', function (Blueprint $table) {
            $table->id();

            $table->string('key', 32)->unique();

            $table->boolean('is_active')->default(false);

            $table->string('title', 120)->nullable();
            $table->string('description', 500)->nullable();

            // Nilai BioLocationMode. String, bukan ENUM database: menambah
            // mode baru tidak boleh memerlukan ALTER TABLE.
            $table->string('location_mode', 32)->default('all_active_locations');

            /*
             | Nomor WhatsApp KHUSUS Bio -- terpisah dari nomor di Pengaturan
             | Website. Disimpan dalam bentuk ternormalisasi (628...), dan
             | diperiksa ulang saat render.
             */
            $table->string('whatsapp_number', 32)->nullable();
            $table->string('whatsapp_message', 500)->nullable();

            /*
             | Urutan, label, dan status tampil tombol utama:
             |   [{"key": "location", "label": "...", "visible": true}, ...]
             | Key divalidasi terhadap BioButton; key asing dibuang.
             */
            $table->json('buttons')->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bio_settings');
    }
};
