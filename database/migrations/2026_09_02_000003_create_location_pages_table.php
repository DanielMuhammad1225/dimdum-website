<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Halaman Slug Lokasi -- satu-satunya pemilik URL publik lokasi.
 *
 * Halaman inilah yang punya slug, judul, konten, poster, CTA, dan SEO. Master
 * hierarki (Provinsi/Kota-Grup/Area/Gerobak) tidak lagi memilikinya.
 *
 * Cakupan halaman ditentukan oleh Kota/Grup yang dipilih, sedangkan ISI-nya
 * adalah gerobak yang dipilih admin secara eksplisit. Keduanya disimpan di
 * tabel pivot terpisah, bukan di sini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_pages', function (Blueprint $table) {
            $table->id();

            $table->string('title');

            // Slug canonical halaman. Unik di level database, bukan sekadar
            // divalidasi aplikasi.
            $table->string('slug')->unique();

            $table->text('short_description')->nullable();
            $table->longText('detailed_description')->nullable();

            /*
             | Periode ditulis dua bentuk yang berbeda tugasnya, bukan duplikat:
             | period_text adalah kalimat yang dibaca pengunjung, sedangkan
             | starts_at/ends_at adalah fakta tanggal yang dipakai mesin untuk
             | menentukan halaman masih berlaku atau tidak.
             */
            $table->string('period_text')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->string('button_text')->nullable();
            // Panjang mengikuti google_maps_url pada locations.
            $table->string('button_url', 2048)->nullable();

            // Metadata poster mengikuti pola location_images.
            $table->string('poster_path')->nullable();
            $table->string('poster_alt')->nullable();
            $table->unsignedInteger('poster_width')->nullable();
            $table->unsignedInteger('poster_height')->nullable();
            $table->string('poster_mime_type', 64)->nullable();
            $table->unsignedBigInteger('poster_size_bytes')->nullable();

            $table->string('seo_title')->nullable();
            $table->string('seo_description', 500)->nullable();

            /*
             | is_active dan published_at TIDAK berarti sama:
             |   is_active    -> saklar admin, bisa dimatikan kapan saja;
             |   published_at -> kapan halaman boleh mulai tampil.
             | Visibilitas publik = tidak terhapus DAN aktif DAN sudah terbit
             | DAN masih dalam periode.
             */
            $table->boolean('is_active')->default(false)->index();
            $table->timestamp('published_at')->nullable()->index();

            // Sorotan di homepage.
            $table->boolean('is_featured')->default(false)->index();

            $table->unsignedInteger('sort_order')->default(0)->index();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Query publik: saring visibilitas lalu urutkan.
            $table->index(['is_active', 'published_at', 'sort_order'], 'location_pages_visibility_index');

            // Homepage: halaman sorotan yang tampil, terurut.
            $table->index(['is_featured', 'is_active', 'published_at'], 'location_pages_featured_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_pages');
    }
};
