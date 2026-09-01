<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel singleton konten homepage.
 *
 * Setiap section disimpan sebagai satu kolom JSON. Nama kolom sengaja
 * mengikuti KEY SECTION AKTUAL (hero, usp, products, how, budget, locations,
 * cta) supaya service tidak perlu lapisan penerjemah nama dan bentuk array
 * yang diterima Blade tetap identik dengan config.
 *
 * Migration ini ADITIF: hanya membuat tabel baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homepage_settings', function (Blueprint $table) {
            $table->id();

            $table->string('key')->unique()->default('homepage');

            $table->json('hero')->nullable();
            $table->json('usp')->nullable();
            $table->json('products')->nullable();
            $table->json('how')->nullable();
            $table->json('budget')->nullable();
            $table->json('locations')->nullable();
            $table->json('cta')->nullable();

            /*
             | Daftar section: [{"key": "hero", "enabled": true}, ...].
             | Hanya key yang terdaftar di config('homepage.section_views')
             | yang diterima; nama file Blade TIDAK PERNAH disimpan di sini.
             */
            $table->json('sections')->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homepage_settings');
    }
};
