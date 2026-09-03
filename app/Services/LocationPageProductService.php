<?php

namespace App\Services;

use App\Models\LocationPage;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pemilihan produk pada Halaman Slug Lokasi.
 *
 * Seluruh aturan diperiksa DI SERVER. Menyembunyikan atau menonaktifkan field
 * di form tidak pernah dianggap sebagai proteksi: id yang disisipkan langsung
 * ke payload Livewire harus ditolak di sini, bukan lolos karena UI-nya rapi.
 *
 * Yang dijaga:
 *   1. Setiap id produk harus benar-benar ada dan belum terhapus.
 *   2. Bila saklar section dinyalakan, minimal satu produk wajib dipilih --
 *      section produk yang menyala tanpa isi hanya menghasilkan judul kosong
 *      di halaman iklan.
 *   3. Mematikan saklar TIDAK menghapus relasi. Pilihan lama harus kembali
 *      apa adanya saat saklarnya dinyalakan kembali.
 */
class LocationPageProductService
{
    /**
     * Pilihan produk untuk multi-select admin.
     *
     * Label memuat kategori, tipe, dan status supaya admin tidak perlu
     * membuka menu Produk untuk tahu apa yang sedang ia pilih. Produk
     * NONAKTIF tetap muncul dan diberi tanda -- ia sah dipilih lebih dulu,
     * dan halaman publik yang menyembunyikannya.
     *
     * show_on_homepage SENGAJA tidak ikut disebut: saklar itu milik homepage
     * dan tidak berpengaruh apa pun di halaman slug lokasi.
     *
     * @param  list<int>  $selectedIds  id yang sudah terpasang, supaya pilihan
     *                                  lama tetap terlihat walau kini terhapus
     * @return array<int, string>
     */
    public function options(array $selectedIds = []): array
    {
        $products = Product::query()
            ->withTrashed()
            ->where(fn ($query) => $query
                ->whereNull('deleted_at')
                ->orWhereIn('id', $selectedIds))
            ->ordered()
            ->get();

        $options = [];

        foreach ($products as $product) {
            $options[$product->getKey()] = $this->label($product);
        }

        return $options;
    }

    protected function label(Product $product): string
    {
        $parts = [
            $product->name,
            $product->categoryLabel(),
            $product->typeLabel(),
        ];

        $parts[] = match (true) {
            $product->trashed() => 'TERHAPUS',
            ! $product->is_active => 'NONAKTIF',
            default => 'AKTIF',
        };

        return implode(' · ', $parts);
    }

    /**
     * Bersihkan payload menjadi daftar id unik bertipe int.
     *
     * @return list<int>
     */
    public function normalizeIds(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        $normalized = [];

        foreach ($ids as $id) {
            if (! is_numeric($id)) {
                continue;
            }

            $normalized[(int) $id] = true;
        }

        return array_map('intval', array_keys($normalized));
    }

    /**
     * Tolak id yang tidak menunjuk produk mana pun.
     *
     * Produk yang sudah terhapus juga ditolak: memilihnya berarti memasang
     * kartu yang tidak akan pernah tampil.
     *
     * @param  list<int>  $productIds
     *
     * @throws ValidationException
     */
    public function assertProductsExist(array $productIds): void
    {
        if ($productIds === []) {
            return;
        }

        $found = Product::query()->whereIn('id', $productIds)->pluck('id')->all();

        $missing = array_values(array_diff($productIds, array_map('intval', $found)));

        if ($missing === []) {
            return;
        }

        throw ValidationException::withMessages([
            'product_ids' => 'Produk berikut tidak ditemukan atau sudah dihapus: '
                .implode(', ', $missing).'.',
        ]);
    }

    /**
     * Section produk yang menyala wajib punya isi.
     *
     * @param  list<int>  $productIds
     *
     * @throws ValidationException
     */
    public function assertSelectionIsUsable(bool $showProducts, array $productIds): void
    {
        if (! $showProducts || $productIds !== []) {
            return;
        }

        throw ValidationException::withMessages([
            'product_ids' => 'Pilih minimal satu produk selama "Tampilkan Produk" dinyalakan, '
                .'atau matikan saklarnya.',
        ]);
    }

    /**
     * Periksa seluruh aturan sekaligus, sebelum apa pun ditulis.
     *
     * @param  list<int>  $productIds
     *
     * @throws ValidationException
     */
    public function assertSelection(bool $showProducts, array $productIds): void
    {
        $this->assertProductsExist($productIds);
        $this->assertSelectionIsUsable($showProducts, $productIds);
    }

    /**
     * Simpan pilihan produk sebuah halaman.
     *
     * Dipanggil HANYA ketika admin benar-benar mengirim pilihan. Saat saklar
     * section mati, field-nya tersembunyi dan Filament membuang state-nya dari
     * data terdehidrasi, sehingga pemanggil tidak punya apa pun untuk dikirim
     * ke sini -- dan relasi lama tetap utuh dengan sendirinya.
     *
     * @param  list<int>  $productIds
     */
    public function sync(LocationPage $page, array $productIds): void
    {
        DB::transaction(function () use ($page, $productIds): void {
            $page->products()->sync($productIds);
        });

        // sync() menulis lewat query builder tanpa event model, jadi versi
        // cache halaman tidak ikut naik dengan sendirinya.
        LocationPageCatalogService::flushCache();
    }
}
