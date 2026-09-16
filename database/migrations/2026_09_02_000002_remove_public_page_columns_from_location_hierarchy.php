<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lepaskan kepemilikan halaman publik dari master hierarki lokasi.
 *
 * Provinsi, Kota/Grup, Area, dan Gerobak kembali menjadi MASTER DATA murni:
 * identitas, induk, status operasional, urutan, audit, dan soft delete.
 * Seluruh atribut publikasi -- slug, headline, deskripsi landing, SEO, dan
 * waktu terbit -- pindah ke modul Halaman Slug Lokasi.
 *
 * `is_active` DIPERTAHANKAN di keempat tabel: artinya kini murni status
 * operasional (gerobak sedang beroperasi, area sedang dipakai), bukan lagi
 * status publikasi.
 *
 * Migration ini menolak berjalan bila salah satu tabel masih berisi data,
 * karena membuang kolom berarti membuang nilainya tanpa bisa dikembalikan.
 *
 * DUA HAL YANG MEMBUATNYA TIDAK LURUS:
 *
 *  1. URUTAN INDEX. MySQL memakai index terkiri sebuah foreign key untuk
 *     menegakkan constraint-nya. locations_area_slug_unique (location_area_id,
 *     slug) adalah satu-satunya index yang melayani FK location_area_id, jadi
 *     membuangnya lebih dulu ditolak dengan error 1553. Index pengganti
 *     karena itu DIBUAT LEBIH DULU, baru unique-nya dibuang.
 *
 *  2. IDEMPOTEN. DDL MySQL tidak transaksional: bila satu langkah gagal,
 *     langkah sebelumnya tetap menempel sementara baris migration belum
 *     tercatat. Setiap langkah karena itu memeriksa kondisi nyata lebih dulu,
 *     sehingga menjalankan ulang migration ini aman.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->assertHierarchyIsEmpty();

        $this->cleanProvinces();
        $this->cleanLocationGroups();
        $this->cleanLocationAreas();
        $this->cleanLocations();
    }

    public function down(): void
    {
        /*
         | Sengaja tidak dibalik. Mengembalikan kolomnya mudah, tetapi nilainya
         | tidak: slug, SEO, dan waktu terbit lama tidak tersimpan di mana pun.
         | Rollback yang menghasilkan kolom kosong hanya akan menyamarkan
         | kehilangan data. Gunakan backup bila memang perlu kembali.
         */
    }

    protected function cleanProvinces(): void
    {
        if (! Schema::hasColumn('provinces', 'slug')) {
            return;
        }

        Schema::table('provinces', function (Blueprint $table) {
            $table->index(['is_active', 'sort_order'], 'provinces_active_order_index');
        });

        $columns = ['slug', 'description', 'seo_title', 'seo_description', 'published_at'];

        $this->dropIndexesUsing('provinces', $columns);

        Schema::table('provinces', function (Blueprint $table) use ($columns) {
            $table->dropColumn($columns);
        });
    }

    protected function cleanLocationGroups(): void
    {
        if (! Schema::hasColumn('location_groups', 'slug')) {
            return;
        }

        /*
         | FK province_id tetap terlayani location_groups_visibility_index
         | (province_id, is_active, sort_order), jadi unique slug boleh
         | langsung dibuang.
         */
        $this->dropIndexesUsing('location_groups', ['slug', 'description']);

        Schema::table('location_groups', function (Blueprint $table) {
            $table->dropColumn(['slug', 'description']);
        });
    }

    protected function cleanLocationAreas(): void
    {
        if (! Schema::hasColumn('location_areas', 'slug')) {
            return;
        }

        Schema::table('location_areas', function (Blueprint $table) {
            $table->index(['is_active', 'sort_order'], 'location_areas_active_order_index');
        });

        $columns = ['slug', 'headline', 'description', 'seo_title', 'seo_description', 'published_at'];

        $this->dropIndexesUsing('location_areas', $columns);

        Schema::table('location_areas', function (Blueprint $table) use ($columns) {
            $table->dropColumn($columns);
        });

        /*
         | MySQL menyusutkan index komposit sendiri saat salah satu kolomnya
         | hilang, sedangkan sapuan di atas membuangnya utuh pada SQLite.
         | Dibangun ulang eksplisit supaya bentuk akhir schema sama di kedua
         | engine, bukan bergantung pada perilaku masing-masing.
         */
        $this->ensureIndex(
            'location_areas',
            ['location_group_id', 'is_active', 'sort_order'],
            'location_areas_group_visibility_index',
        );
    }

    protected function cleanLocations(): void
    {
        if (! Schema::hasColumn('locations', 'slug')) {
            return;
        }

        // Pengganti index FK DIBUAT LEBIH DULU -- lihat catatan (1) di atas.
        Schema::table('locations', function (Blueprint $table) {
            $table->index(
                ['location_area_id', 'is_active', 'sort_order'],
                'locations_area_active_order_index',
            );
        });

        $this->dropIndexesUsing('locations', ['slug', 'published_at']);

        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn(['slug', 'published_at']);
        });
    }

    /**
     * Buang SETIAP index yang menyentuh salah satu kolom yang akan dihapus.
     *
     * Dibuat generik, bukan daftar nama, karena dua alasan:
     *
     *   - MySQL menyusutkan index sendiri saat kolomnya hilang; SQLite TIDAK,
     *     dan justru menolak DROP COLUMN selama masih ada index yang
     *     memakainya. Menyebut nama satu per satu berarti setiap index yang
     *     ditambahkan migration lain harus diingat ulang di sini.
     *   - Nama index tersebar di beberapa migration sebelumnya
     *     (visibility, group_visibility, published_at, unique slug), dan
     *     melewatkan satu saja membuat migration gagal di tengah jalan.
     *
     * @param  list<string>  $columns
     */
    protected function dropIndexesUsing(string $table, array $columns): void
    {
        foreach (Schema::getIndexes($table) as $index) {
            $name = $index['name'] ?? null;

            // PRIMARY tidak pernah disentuh.
            if (! is_string($name) || ($index['primary'] ?? false)) {
                continue;
            }

            if (array_intersect($index['columns'] ?? [], $columns) === []) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($name) {
                $blueprint->dropIndex($name);
            });
        }
    }

    /**
     * Bangun index bila belum ada.
     *
     * @param  list<string>  $columns
     */
    protected function ensureIndex(string $table, array $columns, string $name): void
    {
        foreach (Schema::getIndexes($table) as $existing) {
            if (($existing['name'] ?? null) === $name) {
                return;
            }
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name) {
            $blueprint->index($columns, $name);
        });
    }

    protected function assertHierarchyIsEmpty(): void
    {
        foreach (['provinces', 'location_groups', 'location_areas', 'locations'] as $table) {
            $rows = DB::table($table)->count();

            if ($rows > 0) {
                throw new RuntimeException(
                    "Migration dihentikan: tabel {$table} berisi {$rows} baris. "
                    .'Membuang kolom slug, SEO, dan published_at akan menghilangkan '
                    .'nilai yang sudah diisi tanpa bisa dikembalikan. Pindahkan dulu '
                    .'isinya ke Halaman Slug Lokasi, atau kosongkan tabelnya.'
                );
            }
        }
    }
};
