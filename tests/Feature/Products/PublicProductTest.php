<?php

namespace Tests\Feature\Products;

use App\Enums\ProductCategory;
use App\Enums\ProductType;
use App\Models\Product;
use App\Services\LocationPageCatalogService;
use App\Services\ProductCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Halaman publik: kartu produk homepage dan katalog /produk.
 *
 * Yang dijaga: batas enam kartu di homepage, tombol "Lihat Semua" yang hanya
 * muncul saat memang ada sisa, katalog lengkap di /produk, produk nonaktif
 * yang tidak pernah bocor, dan jumlah query yang tidak bertambah per produk.
 */
class PublicProductTest extends TestCase
{
    use RefreshDatabase;

    protected function catalog(): ProductCatalogService
    {
        return app(ProductCatalogService::class);
    }

    /**
     * @return list<Product>
     */
    protected function homepageProducts(int $count): array
    {
        $products = [];

        for ($i = 1; $i <= $count; $i++) {
            $products[] = Product::factory()->onHomepage()->create([
                'name' => 'Produk '.$i,
                'sort_order' => $i,
            ]);
        }

        return $products;
    }

    // -------------------------------------------------------------- homepage

    public function test_the_homepage_shows_products_from_the_database(): void
    {
        Product::factory()->onHomepage()->withNumericPrice(5000)->create(['name' => 'Dimsum Ayam']);

        $this->get('/')
            ->assertOk()
            ->assertSee('Dimsum Ayam', false)
            ->assertSee('Rp5.000', false);
    }

    public function test_the_homepage_shows_at_most_six_products(): void
    {
        $this->homepageProducts(9);

        $response = $this->get('/')->assertOk();

        foreach (range(1, 6) as $i) {
            $response->assertSee('Produk '.$i, false);
        }

        foreach ([7, 8, 9] as $i) {
            $response->assertDontSee('Produk '.$i, false);
        }

        $this->assertCount(6, $this->catalog()->homepageProducts()['items']);
    }

    public function test_the_homepage_orders_by_sort_order_then_name(): void
    {
        Product::factory()->onHomepage()->create(['name' => 'Zebra', 'sort_order' => 1]);
        Product::factory()->onHomepage()->create(['name' => 'Bakpao', 'sort_order' => 2]);
        Product::factory()->onHomepage()->create(['name' => 'Apel', 'sort_order' => 2]);

        $names = array_column($this->catalog()->homepageProducts()['items'], 'name');

        $this->assertSame(['Zebra', 'Apel', 'Bakpao'], $names);
    }

    public function test_the_see_all_button_is_hidden_at_six_products_or_fewer(): void
    {
        $this->homepageProducts(6);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('Lihat Semua', false);

        $this->assertFalse($this->catalog()->homepageProducts()['has_more']);
    }

    public function test_the_see_all_button_appears_beyond_six_products(): void
    {
        $this->homepageProducts(7);

        $this->get('/')
            ->assertOk()
            ->assertSee('Lihat Semua', false)
            ->assertSee(route('products.index'), false);

        $this->assertTrue($this->catalog()->homepageProducts()['has_more']);
    }

    /**
     * Batasnya adalah produk yang DIPILIH untuk homepage, bukan seluruh isi
     * katalog. Produk yang hanya ada di /produk tidak memunculkan tombolnya.
     */
    public function test_products_outside_the_homepage_do_not_trigger_the_button(): void
    {
        $this->homepageProducts(3);
        Product::factory()->count(10)->create();

        $this->get('/')
            ->assertOk()
            ->assertDontSee('Lihat Semua', false);
    }

    public function test_an_inactive_product_never_reaches_the_homepage(): void
    {
        Product::factory()->onHomepage()->inactive()->create(['name' => 'Produk Nonaktif']);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('Produk Nonaktif', false);
    }

    public function test_a_product_not_chosen_for_the_homepage_is_absent_there(): void
    {
        Product::factory()->create(['name' => 'Hanya di Katalog']);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('Hanya di Katalog', false);
    }

    public function test_a_soft_deleted_product_disappears_from_both_pages(): void
    {
        $product = Product::factory()->onHomepage()->create(['name' => 'Produk Terhapus']);

        $product->delete();

        $this->get('/')->assertOk()->assertDontSee('Produk Terhapus', false);
        $this->get('/produk')->assertOk()->assertDontSee('Produk Terhapus', false);
    }

    public function test_the_homepage_empty_state_shows_when_no_product_is_chosen(): void
    {
        Product::factory()->count(3)->create();

        $this->get('/')
            ->assertOk()
            ->assertSee(config('homepage.products.empty_state.title'), false);
    }

    /**
     * Kartu homepage sengaja hanya memuat nama, gambar, dan harga.
     */
    public function test_the_homepage_card_does_not_show_the_description(): void
    {
        Product::factory()->onHomepage()->create([
            'name' => 'Dimsum Ayam',
            'description' => 'Keterangan yang hanya untuk halaman katalog.',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Dimsum Ayam', false)
            ->assertDontSee('Keterangan yang hanya untuk halaman katalog.', false);
    }

    public function test_a_product_without_a_price_shows_no_price_label(): void
    {
        Product::factory()->onHomepage()->create(['name' => 'Tanpa Harga']);

        $this->get('/')
            ->assertOk()
            ->assertSee('Tanpa Harga', false)
            // "Rp0" adalah kesalahan yang paling mungkin terjadi di sini.
            ->assertDontSee('Rp0', false);
    }

    public function test_price_text_is_rendered_verbatim(): void
    {
        Product::factory()->onHomepage()->withPriceText('Rp5.000/pcs')->create(['name' => 'Satuan']);

        $this->get('/')->assertOk()->assertSee('Rp5.000/pcs', false);
    }

    // --------------------------------------------------------------- /produk

    public function test_the_catalog_page_lists_every_active_product(): void
    {
        Product::factory()->onHomepage()->create(['name' => 'Di Homepage']);
        Product::factory()->create(['name' => 'Tidak Di Homepage']);

        $this->get('/produk')
            ->assertOk()
            ->assertSee('Di Homepage', false)
            ->assertSee('Tidak Di Homepage', false);
    }

    public function test_the_catalog_page_hides_inactive_products(): void
    {
        Product::factory()->create(['name' => 'Produk Aktif']);
        Product::factory()->inactive()->create(['name' => 'Produk Nonaktif']);

        $this->get('/produk')
            ->assertOk()
            ->assertSee('Produk Aktif', false)
            ->assertDontSee('Produk Nonaktif', false);
    }

    public function test_the_catalog_groups_products_by_category(): void
    {
        Product::factory()->category(ProductCategory::Menu)->create(['name' => 'Dimsum Ayam']);
        Product::factory()->category(ProductCategory::Varian)->create(['name' => 'Ekkado']);
        Product::factory()->category(ProductCategory::Frozen)->create(['name' => 'Frozen Pack']);

        $sections = $this->catalog()->catalog();

        $this->assertSame(
            [ProductCategory::Menu->value, ProductCategory::Varian->value, ProductCategory::Frozen->value],
            array_column($sections, 'key'),
            'Urutan kelompok harus mengikuti enum, bukan urutan data.',
        );

        $response = $this->get('/produk')->assertOk();

        foreach (ProductCategory::cases() as $category) {
            $response->assertSee($category->publicHeading(), false);
        }
    }

    public function test_an_empty_category_produces_no_heading(): void
    {
        Product::factory()->category(ProductCategory::Menu)->create(['name' => 'Dimsum Ayam']);

        $this->get('/produk')
            ->assertOk()
            ->assertSee(ProductCategory::Menu->publicHeading(), false)
            ->assertDontSee(ProductCategory::Frozen->publicHeading(), false);
    }

    public function test_the_catalog_shows_description_and_price(): void
    {
        Product::factory()
            ->withNumericPrice(12500)
            ->create([
                'name' => 'Paket Hemat',
                'description' => 'Isi sepuluh potong campur.',
                'type' => ProductType::Paket->value,
            ]);

        $this->get('/produk')
            ->assertOk()
            ->assertSee('Paket Hemat', false)
            ->assertSee('Rp12.500', false)
            ->assertSee('Isi sepuluh potong campur.', false);
    }

    public function test_the_catalog_page_has_an_empty_state(): void
    {
        $this->get('/produk')
            ->assertOk()
            ->assertSee('Daftar produk segera hadir', false);
    }

    public function test_the_catalog_page_declares_title_description_and_canonical(): void
    {
        Product::factory()->create(['name' => 'Dimsum Ayam']);

        $this->get('/produk')
            ->assertOk()
            ->assertSee('<title>Daftar Produk | DIMDUM</title>', false)
            ->assertSee('<meta name="description"', false)
            ->assertSee('<link rel="canonical" href="'.route('products.index').'"', false);
    }

    public function test_the_catalog_page_has_exactly_one_h1(): void
    {
        Product::factory()->create();

        $html = $this->get('/produk')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '<h1'), 'Halaman /produk harus punya tepat satu H1.');
    }

    /**
     * Parameter iklan boleh ada di address bar, tetapi tidak pernah ikut ke
     * canonical.
     */
    public function test_ad_parameters_never_reach_the_canonical(): void
    {
        Product::factory()->create();

        $this->get('/produk?utm_source=ig&gclid=abc')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.route('products.index').'"', false)
            ->assertDontSee('utm_source', false);
    }

    // ---------------------------------------------------------------- query

    public function test_the_homepage_query_count_does_not_grow_per_product(): void
    {
        $this->homepageProducts(6);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->get('/')->assertOk();

        /*
         | Empat query tetap: site settings, homepage settings, halaman lokasi,
         | dan produk. Jumlahnya KONSTAN -- tidak bertambah per kartu, per
         | section, maupun per komponen Blade.
         */
        $this->assertLessThanOrEqual(
            4,
            count($queries),
            'Homepage melakukan query berlebih: '.implode(' | ', $queries),
        );
    }

    public function test_the_catalog_page_query_count_does_not_grow_per_product(): void
    {
        Product::factory()->count(30)->create();

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->get('/produk')->assertOk();

        // Site settings + daftar produk. Tidak ada satu pun query per kartu.
        $this->assertLessThanOrEqual(
            2,
            count($queries),
            'Halaman /produk melakukan query berlebih: '.implode(' | ', $queries),
        );
    }

    public function test_a_cached_page_is_served_without_further_queries(): void
    {
        $this->homepageProducts(3);

        // Permintaan pertama mengisi cache.
        $this->get('/produk')->assertOk();

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->get('/produk')->assertOk();

        $this->assertSame(
            [],
            array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'products'))),
            'Katalog yang sudah ter-cache tidak boleh menyentuh tabel produk lagi.',
        );
    }

    // ---------------------------------------------------------------- cache

    public function test_a_change_appears_immediately_on_both_pages(): void
    {
        $product = Product::factory()->onHomepage()->create(['name' => 'Nama Lama']);

        $this->get('/')->assertOk()->assertSee('Nama Lama', false);
        $this->get('/produk')->assertOk()->assertSee('Nama Lama', false);

        $product->update(['name' => 'Nama Baru']);

        $this->get('/')->assertOk()->assertSee('Nama Baru', false)->assertDontSee('Nama Lama', false);
        $this->get('/produk')->assertOk()->assertSee('Nama Baru', false)->assertDontSee('Nama Lama', false);
    }

    public function test_deactivating_a_product_takes_effect_immediately(): void
    {
        $product = Product::factory()->onHomepage()->create(['name' => 'Produk Uji Cache']);

        $this->get('/')->assertOk()->assertSee('Produk Uji Cache', false);

        $product->update(['is_active' => false]);

        $this->get('/')->assertOk()->assertDontSee('Produk Uji Cache', false);
        $this->get('/produk')->assertOk()->assertDontSee('Produk Uji Cache', false);
    }

    public function test_toggling_the_homepage_switch_takes_effect_immediately(): void
    {
        $product = Product::factory()->create(['name' => 'Naik Ke Homepage']);

        $this->get('/')->assertOk()->assertDontSee('Naik Ke Homepage', false);

        $product->update(['show_on_homepage' => true]);

        $this->get('/')->assertOk()->assertSee('Naik Ke Homepage', false);
    }

    public function test_the_catalog_cache_key_is_separate_from_the_location_pages_one(): void
    {
        $this->assertNotSame(
            ProductCatalogService::VERSION_KEY,
            LocationPageCatalogService::VERSION_KEY,
        );
    }

    /**
     * Halaman produk tidak boleh membuang seluruh cache aplikasi.
     */
    public function test_the_module_never_flushes_the_whole_cache(): void
    {
        $sources = [
            app_path('Services/ProductCatalogService.php'),
            app_path('Models/Product.php'),
            app_path('Filament/Resources/Products/Tables/ProductsTable.php'),
            app_path('Http/Controllers/ProductController.php'),
        ];

        foreach ($sources as $file) {
            $this->assertStringNotContainsString(
                'Cache::'.'flush(',
                (string) file_get_contents($file),
                basename($file).' mengosongkan seluruh cache aplikasi.',
            );
        }
    }
}
