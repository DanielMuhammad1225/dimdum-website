<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tautan social media yang tampil di halaman Bio.
 *
 * 'icon' hanya menyimpan NAMA dari allowlist SocialIcon -- markup ikonnya
 * hidup di kode. Tidak ada kolom untuk HTML maupun SVG dari admin.
 *
 * SENGAJA tanpa soft delete. Baris ini tidak punya berkas, tidak punya relasi,
 * dan jalur yang bisa dipulihkan sudah tersedia lewat saklar 'is_active':
 * menonaktifkan menyembunyikan tautan tanpa kehilangan isinya. Menghapus
 * berarti memang dibuang.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('social_links')) {
            return;
        }

        Schema::create('social_links', function (Blueprint $table) {
            $table->id();

            $table->string('name', 60);

            // Panjang mengikuti kolom URL lain di project (google_maps_url).
            $table->string('url', 2048);

            $table->string('icon', 32)->default('link');

            $table->boolean('is_active')->default(true);

            $table->unsignedInteger('sort_order')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Halaman Bio: saring aktif lalu urutkan.
            $table->index(['is_active', 'sort_order'], 'social_links_active_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_links');
    }
};
