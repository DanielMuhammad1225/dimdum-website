<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slug lama wilayah landing.
 *
 * Halaman wilayah dipakai sebagai destination iklan, jadi URL yang sudah
 * pernah terbit tidak boleh mati ketika slug-nya diganti. Setiap slug lama
 * dicatat di sini dan diarahkan 301 ke slug canonical terbaru.
 *
 * old_slug unik secara global sehingga tidak mungkin ada satu URL lama yang
 * mengarah ke dua wilayah berbeda.
 *
 * Migration ini ADITIF: hanya membuat tabel baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_area_slug_redirects', function (Blueprint $table) {
            $table->id();

            $table->foreignId('location_area_id')->constrained('location_areas')->cascadeOnDelete();

            $table->string('old_slug')->unique();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('location_area_id', 'location_area_slug_redirects_area_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_area_slug_redirects');
    }
};
