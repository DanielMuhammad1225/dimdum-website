<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gerobak DIMDUM.
 *
 * Slug sudah disimpan sekarang -- lengkap dengan keunikan per wilayah --
 * supaya route detail /lokasi/{area}/{location} dapat ditambahkan pada fase
 * berikutnya tanpa migration pengubah struktur. Route detail SENGAJA belum
 * didaftarkan pada fase ini.
 *
 * Migration ini ADITIF: hanya membuat tabel baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();

            /*
             | restrictOnDelete: wilayah tidak boleh dihapus selama masih
             | memiliki gerobak. Database yang menjadi penjaga terakhir,
             | bukan hanya policy aplikasi.
             */
            $table->foreignId('location_area_id')->constrained('location_areas')->restrictOnDelete();

            $table->string('name');
            $table->string('slug');

            // Kelompok filter publik. Kosong -> service memakai district.
            $table->string('filter_label')->nullable();

            $table->string('full_address', 500);
            $table->string('village')->nullable();
            $table->string('district')->nullable();
            $table->string('city_regency')->nullable();
            $table->string('province')->nullable();
            $table->string('postal_code', 16)->nullable();
            $table->string('landmark')->nullable();
            $table->string('operational_hours_text')->nullable();

            // Digit saja, sudah dinormalisasi (lihat App\Support\WhatsAppNumber).
            $table->string('whatsapp_number', 32)->nullable();

            /*
             | Decimal, bukan float: koordinat butuh nilai eksak.
             | 10,7 menampung -90.0000000..180.0000000 dengan presisi ~1 cm.
             */
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // Link Maps manual. Divalidasi saat input DAN saat render.
            $table->string('google_maps_url', 2048)->nullable();

            $table->boolean('is_active')->default(false)->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->unsignedInteger('sort_order')->default(0)->index();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Slug gerobak unik DI DALAM satu wilayah.
            $table->unique(['location_area_id', 'slug'], 'locations_area_slug_unique');

            // Query publik: gerobak visible pada satu wilayah, terurut.
            $table->index(
                ['location_area_id', 'is_active', 'published_at', 'sort_order'],
                'locations_area_visibility_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
