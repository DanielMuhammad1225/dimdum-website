<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat slug Halaman Slug Lokasi.
 *
 * Halaman inilah satu-satunya yang punya URL publik, jadi hanya di sinilah
 * riwayat slug punya arti: URL lama masih beredar di iklan dan harus tetap
 * hidup.
 *
 * Barisnya menunjuk ke HALAMAN, bukan ke slug lain. Berapa kali pun slug
 * berganti, semua slug lama mengarah ke canonical terbaru dalam SATU lompatan
 * -- rantai redirect dan loop mustahil terbentuk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_page_slug_redirects', function (Blueprint $table) {
            $table->id();

            $table->foreignId('location_page_id')->constrained('location_pages')->cascadeOnDelete();

            // Unik global: satu slug lama tidak boleh menunjuk dua halaman.
            $table->string('old_slug')->unique();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('location_page_id', 'location_page_slug_redirects_page_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_page_slug_redirects');
    }
};
