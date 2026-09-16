<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kota/Grup -- tingkat kedua hierarki lokasi DIMDUM.
 *
 * Satu Kota/Grup berada tepat di bawah satu Provinsi dan menampung beberapa
 * Area. Ia bisa berupa wilayah administratif (Jakarta Selatan) ATAU kelompok
 * pemasaran yang melintasi batas administratif (Bandung Raya) -- karena itu
 * ada kolom `type` yang divalidasi enum, bukan label bebas.
 *
 * Kota/Grup TIDAK punya halaman publik sendiri: ia hanya menjadi heading
 * pengelompokan di halaman Provinsi. Karena itu ia tidak butuh published_at,
 * SEO, maupun riwayat slug.
 *
 * Migration ini ADITIF: hanya membuat tabel baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_groups', function (Blueprint $table) {
            $table->id();

            /*
             | restrictOnDelete: provinsi tidak boleh dihapus selama masih
             | memiliki Kota/Grup. Database yang menjadi penjaga terakhir,
             | bukan hanya policy aplikasi.
             */
            $table->foreignId('province_id')->constrained('provinces')->restrictOnDelete();

            $table->string('name');
            $table->string('slug');

            // Nilai resmi ada di App\Enums\LocationGroupType.
            $table->string('type', 32);

            $table->text('description')->nullable();

            // Tanpa published_at: Kota/Grup tidak punya URL yang bisa terbit.
            $table->boolean('is_active')->default(false)->index();

            $table->unsignedInteger('sort_order')->default(0)->index();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Slug Kota/Grup unik DI DALAM satu provinsi.
            $table->unique(['province_id', 'slug'], 'location_groups_province_slug_unique');

            // Query publik: grup aktif pada satu provinsi, terurut.
            $table->index(['province_id', 'is_active', 'sort_order'], 'location_groups_visibility_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_groups');
    }
};
