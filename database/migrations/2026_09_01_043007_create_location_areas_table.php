<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wilayah landing DIMDUM.
 *
 * Wilayah landing adalah AREA PEMASARAN publik (contoh: Cianjur, Karawang),
 * bukan wilayah administratif. Data administratif disimpan per gerobak di
 * tabel locations, sehingga satu wilayah landing bebas mencakup beberapa
 * kecamatan atau kabupaten.
 *
 * Migration ini ADITIF: hanya membuat tabel baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_areas', function (Blueprint $table) {
            $table->id();

            $table->string('name');

            // Slug canonical wilayah. Unik di level database, bukan hanya
            // divalidasi aplikasi.
            $table->string('slug')->unique();

            $table->string('headline')->nullable();
            $table->text('description')->nullable();

            $table->string('province')->nullable();
            $table->string('city_regency')->nullable();

            $table->string('seo_title')->nullable();
            $table->string('seo_description', 500)->nullable();

            /*
             | Visibilitas publik = tidak terhapus DAN is_active DAN
             | published_at terisi dan tidak berada di masa depan.
             */
            $table->boolean('is_active')->default(false)->index();
            $table->timestamp('published_at')->nullable()->index();

            $table->unsignedInteger('sort_order')->default(0)->index();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Query publik: filter visibilitas lalu urutkan.
            $table->index(['is_active', 'published_at', 'sort_order'], 'location_areas_visibility_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_areas');
    }
};
