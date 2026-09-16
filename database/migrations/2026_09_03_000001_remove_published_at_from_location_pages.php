<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buang jadwal terbit dari Halaman Slug Lokasi.
 *
 * Sebelumnya sebuah halaman butuh DUA syarat yang mudah tertukar: `is_active`
 * menyala DAN `published_at` terisi. Menyalakan Aktif saja menghasilkan
 * halaman yang terasa selesai tetapi URL-nya 404 -- perangkap yang memang
 * sempat terjadi. Satu saklar sudah cukup.
 *
 * Visibilitas publik kini:
 *   tidak terhapus  DAN  is_active  DAN  masih di dalam periode (bila diisi)
 *
 * starts_at dan ends_at DIPERTAHANKAN: keduanya periode berlaku halaman, dan
 * memang berbeda tugas dari saklar aktif.
 *
 * TIDAK ADA BARIS YANG DIHAPUS. Halaman yang selama ini tertahan sebagai
 * draft (aktif tetapi tanpa waktu terbit) otomatis menjadi terlihat -- itulah
 * yang diminta pemilik project.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('location_pages', 'published_at')) {
            return;
        }

        /*
         | Index penggantinya DIBUAT LEBIH DULU, supaya query visibilitas
         | tidak pernah kehilangan penopangnya di tengah migration.
         */
        $this->ensureIndex('location_pages', ['is_active', 'sort_order'], 'location_pages_active_order_index');
        $this->ensureIndex('location_pages', ['is_featured', 'is_active', 'sort_order'], 'location_pages_featured_active_index');

        /*
         | MySQL menyusutkan index sendiri saat kolomnya hilang; SQLite TIDAK,
         | dan justru menolak DROP COLUMN selama masih ada index yang
         | memakainya. Setiap index yang menyentuh published_at karena itu
         | dibuang eksplisit lebih dulu.
         */
        $this->dropIndexesUsing('location_pages', ['published_at']);

        Schema::table('location_pages', function (Blueprint $table) {
            $table->dropColumn('published_at');
        });
    }

    public function down(): void
    {
        /*
         | Sengaja tidak dibalik. Kolomnya mudah dikembalikan, nilainya tidak:
         | jadwal terbit lama tidak tersimpan di mana pun. Rollback yang
         | menghasilkan kolom kosong hanya akan menyembunyikan seluruh halaman
         | yang sekarang terlihat.
         */
    }

    /**
     * @param  list<string>  $columns
     */
    protected function dropIndexesUsing(string $table, array $columns): void
    {
        foreach (Schema::getIndexes($table) as $index) {
            $name = $index['name'] ?? null;

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
};
