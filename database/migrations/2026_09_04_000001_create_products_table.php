<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Katalog produk DIMDUM.
 *
 * Satu CRUD umum untuk seluruh produk. Kategori dan tipe adalah dua fakta
 * berbeda dan disimpan terpisah: kategori menentukan pengelompokan di halaman
 * /produk, tipe menerangkan bentuk penjualannya.
 *
 * Fase ini SENGAJA tanpa slug dan tanpa halaman detail produk -- tidak ada URL
 * per produk, jadi tidak ada yang perlu dijaga keunikannya maupun dialihkan
 * saat berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            $table->string('name');

            // Nilai enum divalidasi aplikasi (ProductCategory/ProductType).
            // Disimpan sebagai string, bukan ENUM database: menambah kategori
            // baru nanti tidak boleh memerlukan ALTER TABLE.
            $table->string('category', 32)->index();
            $table->string('type', 32)->index();

            $table->text('description')->nullable();

            /*
             | Dua bentuk harga dengan tugas berbeda, bukan duplikat:
             |
             |   price       fakta angka dalam RUPIAH PENUH (tanpa sen).
             |               Dipakai bila admin hanya ingin menulis nominal.
             |   price_text  kalimat harga apa adanya, mis. "Rp5.000/pcs".
             |               Menang atas price bila diisi, karena ia memuat
             |               satuan dan keterangan yang tidak bisa disimpulkan
             |               dari angka.
             |
             | Keduanya boleh kosong: produk tanpa harga pasti tidak
             | menampilkan label harga sama sekali, bukan "Rp0".
             */
            $table->unsignedInteger('price')->nullable();
            $table->string('price_text', 120)->nullable();

            /*
             | Foto opsional. Hanya path relatif pada disk 'public' yang
             | disimpan -- bukan URL dan bukan path filesystem.
             |
             | Dimensi, MIME, dan ukuran SENGAJA tidak disimpan: keduanya
             | dibaca dari berkas nyata saat payload dibangun lalu ikut
             | tersimpan di cache, sehingga angkanya tidak pernah basi
             | terhadap file yang benar-benar ada (lihat ImageMetadata).
             */
            $table->string('image_path')->nullable();
            $table->string('image_alt')->nullable();

            /*
             | Fallback saat foto kosong. Dibatasi pendek karena ini memang
             | untuk satu emoji, bukan kalimat.
             */
            $table->string('emoji', 32)->nullable();

            $table->boolean('is_active')->default(true)->index();
            $table->boolean('show_on_homepage')->default(false)->index();

            $table->unsignedInteger('sort_order')->default(0)->index();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Kartu produk homepage: saring lalu urutkan.
            $table->index(['is_active', 'show_on_homepage', 'sort_order'], 'products_homepage_index');

            // Halaman /produk: saring aktif, kelompokkan kategori, urutkan.
            $table->index(['is_active', 'category', 'sort_order'], 'products_catalog_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
