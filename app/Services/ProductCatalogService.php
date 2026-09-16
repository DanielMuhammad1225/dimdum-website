<?php

namespace App\Services;

use App\Enums\ProductCategory;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Satu-satunya sumber data produk untuk halaman publik.
 *
 * Controller memanggil service ini; Blade hanya menerima array yang sudah
 * jadi. Tidak ada query di Blade dan tidak ada model Eloquent yang sampai ke
 * view.
 *
 * Strategi cache mengikuti modul halaman lokasi: VERSI, bukan penghapusan per
 * key. Setiap perubahan produk -- isi, status aktif, saklar homepage, harga,
 * foto, maupun urutan -- menaikkan satu nomor versi, sehingga seluruh key
 * turunannya basi bersamaan. Cache aplikasi tidak pernah dikosongkan
 * seluruhnya.
 *
 * Versinya SENGAJA terpisah dari cache konten homepage (HomepageSetting) dan
 * dari cache halaman lokasi. Mengubah produk tidak mengubah teks CMS homepage,
 * jadi membuang cache itu hanya akan menambah query tanpa alasan.
 */
class ProductCatalogService
{
    public const VERSION_KEY = 'products.cache_version';

    /**
     * Jumlah kartu produk maksimum di homepage.
     */
    public const HOMEPAGE_LIMIT = 6;

    /**
     * TTL data. Bukan penentu kesegaran -- versi yang menentukan -- tetapi
     * memastikan entri versi lama tidak menumpuk selamanya.
     */
    public const TTL_SECONDS = 3600;

    /**
     * Naikkan versi cache produk.
     *
     * Cache::add + increment dipilih supaya operasinya atomik pada driver
     * yang mendukung, bukan baca-lalu-tulis yang bisa saling menimpa. Seluruh
     * cache aplikasi TIDAK pernah dikosongkan.
     */
    public static function flushCache(): void
    {
        if (Cache::add(self::VERSION_KEY, 1)) {
            return;
        }

        Cache::increment(self::VERSION_KEY);
    }

    public static function cacheVersion(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 0);
    }

    protected function key(string $suffix): string
    {
        return 'products.v'.self::cacheVersion().'.'.$suffix;
    }

    /**
     * Jalankan query dengan fallback yang SANGAT sempit.
     *
     * Hanya satu kondisi yang ditoleransi: tabelnya belum dibuat (migration
     * belum dijalankan). Error database lain DILEMPAR ULANG -- menelannya akan
     * membuat production tampak sehat padahal databasenya bermasalah.
     *
     * @template TValue
     *
     * @param  callable(): TValue  $query
     * @param  TValue  $fallback
     * @return TValue
     */
    protected function guardMissingTable(callable $query, mixed $fallback): mixed
    {
        try {
            return $query();
        } catch (QueryException $exception) {
            if (! $this->isMissingTable($exception)) {
                throw $exception;
            }

            Log::warning('Tabel produk belum tersedia, katalog menampilkan daftar kosong.', [
                'message' => $exception->getMessage(),
            ]);

            return $fallback;
        }
    }

    protected function isMissingTable(QueryException $exception): bool
    {
        // SQLSTATE 42S02 = base table or view not found (MySQL).
        if (($exception->errorInfo[0] ?? null) === '42S02') {
            return true;
        }

        // SQLite hanya menyebutkannya di pesan.
        return str_contains($exception->getMessage(), 'no such table');
    }

    // ------------------------------------------------------------- homepage

    /**
     * Kartu produk untuk homepage.
     *
     * 'has_more' menandai bahwa produk yang dipilih untuk homepage LEBIH dari
     * batas tampil, sehingga tombol "Lihat Semua" punya alasan nyata untuk
     * muncul. Nilainya diambil dari query yang sama (LIMIT + 1), bukan dari
     * count() terpisah.
     *
     * @return array{items: list<array<string, mixed>>, has_more: bool}
     */
    public function homepageProducts(): array
    {
        /** @var array{items: list<array<string, mixed>>, has_more: bool} */
        return Cache::remember(
            $this->key('homepage'),
            self::TTL_SECONDS,
            fn (): array => $this->guardMissingTable(
                fn (): array => $this->buildHomepageProducts(),
                ['items' => [], 'has_more' => false],
            ),
        );
    }

    /**
     * @return array{items: list<array<string, mixed>>, has_more: bool}
     */
    protected function buildHomepageProducts(): array
    {
        $products = Product::query()
            ->active()
            ->onHomepage()
            ->ordered()
            // Satu baris lebih banyak dari yang ditampilkan: cukup untuk tahu
            // ada sisa tanpa menghitung seluruh tabel.
            ->limit(self::HOMEPAGE_LIMIT + 1)
            ->get();

        $hasMore = $products->count() > self::HOMEPAGE_LIMIT;

        return [
            'items' => $products
                ->take(self::HOMEPAGE_LIMIT)
                ->map(fn (Product $product): array => $this->card($product))
                ->values()
                ->all(),
            'has_more' => $hasMore,
        ];
    }

    // -------------------------------------------------------------- katalog

    /**
     * Seluruh produk aktif, dikelompokkan per kategori.
     *
     * Termasuk produk yang TIDAK dipilih untuk homepage -- halaman /produk
     * adalah katalog lengkap, bukan perpanjangan section homepage.
     *
     * @return list<array{key: string, heading: string, items: list<array<string, mixed>>}>
     */
    public function catalog(): array
    {
        /** @var list<array{key: string, heading: string, items: list<array<string, mixed>>}> */
        return Cache::remember(
            $this->key('catalog'),
            self::TTL_SECONDS,
            fn (): array => $this->guardMissingTable(fn (): array => $this->buildCatalog(), []),
        );
    }

    /**
     * @return list<array{key: string, heading: string, items: list<array<string, mixed>>}>
     */
    protected function buildCatalog(): array
    {
        $products = Product::query()->active()->ordered()->get();

        $groups = [];

        foreach ($products as $product) {
            $category = $product->category;

            // Nilai kategori di luar enum tidak pernah dikelompokkan: ia
            // hanya bisa muncul dari tulisan langsung ke database.
            if (! $category instanceof ProductCategory) {
                continue;
            }

            $groups[$category->value][] = $this->card($product, withDescription: true);
        }

        $sections = [];

        // Urutan kelompok ditentukan enum, bukan urutan kemunculan data,
        // supaya susunan halaman tidak berubah saat produk ditambah.
        foreach (self::orderedCategories() as $category) {
            if (empty($groups[$category->value])) {
                continue;
            }

            $sections[] = [
                'key' => $category->value,
                'heading' => $category->publicHeading(),
                'items' => $groups[$category->value],
            ];
        }

        return $sections;
    }

    /**
     * @return list<ProductCategory>
     */
    protected static function orderedCategories(): array
    {
        $categories = ProductCategory::cases();

        usort(
            $categories,
            fn (ProductCategory $a, ProductCategory $b): int => $a->displayOrder() <=> $b->displayOrder(),
        );

        return $categories;
    }

    // --------------------------------------------------------------- kartu

    /**
     * Bentuk satu kartu produk yang dipahami Blade.
     *
     * 'price' bernilai null bila produk tidak punya harga sama sekali; Blade
     * memakai itu untuk TIDAK mencetak baris harga, bukan mencetak baris
     * kosong.
     *
     * 'image' bernilai null bila fotonya kosong ATAU berkasnya tidak ada di
     * disk, sehingga <img src=""> tidak mungkin terbentuk. Saat itu terjadi,
     * 'emoji' yang mengambil alih.
     *
     * @return array<string, mixed>
     */
    protected function card(Product $product, bool $withDescription = false): array
    {
        $card = [
            'id' => $product->id,
            'name' => $product->name,
            'price' => $product->priceLabel(),
            'image' => ImageMetadata::resolve($product->image_path, $product->imageAltText()),
            'emoji' => $product->fallbackEmoji(),
        ];

        if ($withDescription) {
            $description = is_string($product->description) ? trim($product->description) : '';

            $card['description'] = $description !== '' ? $description : null;
            $card['type'] = $product->typeLabel();
        }

        return $card;
    }
}
