<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel singleton pengaturan website.
 *
 * Migration ini ADITIF: hanya membuat tabel baru dan tidak menyentuh tabel
 * bawaan Laravel maupun tabel permission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_settings', function (Blueprint $table) {
            $table->id();

            /*
             | Singleton dikunci lewat kolom unik 'key'. Menyimpan baris kedua
             | akan ditolak database, bukan hanya oleh kode aplikasi.
             */
            $table->string('key')->unique()->default('default');

            $table->string('brand_name');
            $table->string('tagline');
            $table->string('positioning');

            // Nomor disimpan sudah dinormalisasi (digit saja, format E.164 tanpa '+').
            $table->string('whatsapp_number', 32)->nullable();
            $table->string('instagram_url')->nullable();
            $table->string('tiktok_url')->nullable();
            $table->string('facebook_url')->nullable();

            $table->string('default_meta_title');
            $table->string('default_meta_description', 500);

            // Path RELATIF pada disk 'public' (contoh: site/og/xxx.jpg).
            $table->string('default_og_image_path')->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_settings');
    }
};
