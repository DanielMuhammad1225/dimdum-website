<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foto gerobak.
 *
 * Foto SELALU melekat pada satu gerobak; tidak ada galeri global. Galeri
 * halaman wilayah dirakit dari foto gerobak yang benar-benar berada di
 * wilayah tersebut.
 *
 * Migration ini ADITIF: hanya membuat tabel baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_images', function (Blueprint $table) {
            $table->id();

            // Menghapus gerobak ikut menghapus barisan fotonya.
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();

            // Path RELATIF pada disk 'public' (contoh: locations/01J.../01J....jpg).
            $table->string('image_path', 512);

            // Alt text wajib: foto tanpa deskripsi tidak boleh masuk.
            $table->string('alt_text', 255);
            $table->string('caption', 500)->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_cover')->default(false);

            // Dimensi nyata file, dibaca server saat upload -> mencegah CLS.
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->string('mime_type', 64);
            $table->unsignedBigInteger('size_bytes');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['location_id', 'sort_order'], 'location_images_order_index');
            $table->index(['location_id', 'is_cover'], 'location_images_cover_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_images');
    }
};
