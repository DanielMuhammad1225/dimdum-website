<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slug lama provinsi.
 *
 * Provinsi menjadi segmen pertama URL landing (/lokasi/{province}/{area}),
 * jadi mengganti slug provinsi ikut memutus SELURUH URL Area di bawahnya.
 * Setiap slug provinsi yang pernah terbit dicatat di sini dan dijawab 301 ke
 * slug canonical terbaru.
 *
 * old_slug unik secara global sehingga satu URL lama tidak mungkin menunjuk
 * dua provinsi berbeda -- pola yang sama dengan tabel redirect Area.
 *
 * Migration ini ADITIF: hanya membuat tabel baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_province_slug_redirects', function (Blueprint $table) {
            $table->id();

            $table->foreignId('province_id')->constrained('provinces')->cascadeOnDelete();

            $table->string('old_slug')->unique();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('province_id', 'location_province_slug_redirects_province_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_province_slug_redirects');
    }
};
