<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provinsi -- tingkat teratas hierarki lokasi DIMDUM.
 *
 * Provinsi adalah wilayah administratif sungguhan dan menjadi segmen pertama
 * URL publik (/lokasi/{province}). Karena itu ia butuh slug, status terbit,
 * dan SEO sendiri -- sama seperti Area.
 *
 * Migration ini ADITIF: hanya membuat tabel baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provinces', function (Blueprint $table) {
            $table->id();

            $table->string('name');

            // Slug canonical provinsi, unik di level database.
            $table->string('slug')->unique();

            $table->text('description')->nullable();

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

            // Query publik: saring visibilitas lalu urutkan.
            $table->index(['is_active', 'published_at', 'sort_order'], 'provinces_visibility_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provinces');
    }
};
