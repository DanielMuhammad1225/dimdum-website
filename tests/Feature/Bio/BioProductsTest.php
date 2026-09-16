<?php

namespace Tests\Feature\Bio;

use App\Enums\ProductCategory;
use App\Enums\ProductType;
use App\Models\LocationPage;
use App\Models\Product;
use App\Services\BioCatalogService;
use App\Services\ProductCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Bio\Concerns\BuildsBio;
use Tests\TestCase;

/**
 * /bio/produk: produk aktif, tidak terhapus, dan show_on_bio.
 */
class BioProductsTest extends TestCase
{
    use BuildsBio;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bio();
    }

    public function test_only_active_bio_products_appear(): void
    {
        Product::factory()->onBio()->create(['name' => 'Produk Bio']);
        Product::factory()->onBio()->inactive()->create(['name' => 'Produk Bio Nonaktif']);
        Product::factory()->create(['name' => 'Produk Bukan Bio']);
        Product::factory()->onBio()->create(['name' => 'Produk Bio Terhapus'])->delete();

        $this->get(route('bio.products'))
            ->assertOk()
            ->assertSee('Produk Bio', false)
            ->assertDontSee('Produk Bio Nonaktif', false)
            ->assertDontSee('Produk Bukan Bio', false)
            ->assertDontSee('Produk Bio Terhapus', false);
    }

    /**
     * Saklar Bio dan saklar Homepage berdiri sendiri, ke dua arah.
     */
    public function test_the_bio_toggle_is_independent_from_the_homepage_toggle(): void
    {
        Product::factory()->onBio()->create(['name' => 'Hanya Bio', 'show_on_homepage' => false]);
        Product::factory()->onHomepage()->create(['name' => 'Hanya Homepage', 'show_on_bio' => false]);

        $this->get(route('bio.products'))
            ->assertOk()
            ->assertSee('Hanya Bio', false)
            ->assertDontSee('Hanya Homepage', false);

        $this->get('/')
            ->assertOk()
            ->assertSee('Hanya Homepage', false)
            ->assertDontSee('Hanya Bio', false);
    }

    public function test_the_catalog_page_is_unaffected_by_the_bio_toggle(): void
    {
        Product::factory()->onBio()->create(['name' => 'Di Bio']);
        Product::factory()->create(['name' => 'Tidak Di Bio']);

        $this->get('/produk')
            ->assertOk()
            ->assertSee('Di Bio', false)
            ->assertSee('Tidak Di Bio', false);
    }

    /**
     * Pilihan produk pada dialog halaman slug lokasi TIDAK ikut ke Bio, dan
     * sebaliknya: sumbernya sama, aturannya berbeda.
     */
    public function test_the_location_page_menu_and_the_bio_menu_stay_separate(): void
    {
        $modalOnly = Product::factory()->create(['name' => 'Hanya Dialog Lokasi']);
        Product::factory()->onBio()->create(['name' => 'Hanya Menu Bio']);

        $page = LocationPage::factory()->showingProducts()->create(['slug' => 'alamat-dialog']);
        $page->products()->attach($modalOnly);

        $this->get(route('bio.products'))
            ->assertOk()
            ->assertSee('Hanya Menu Bio', false)
            ->assertDontSee('Hanya Dialog Lokasi', false);
    }

    /**
     * Nama dan urutan kelompok berasal dari enum -- bukan ditulis di Blade.
     */
    public function test_products_are_grouped_by_the_category_enum(): void
    {
        Product::factory()->onBio()->category(ProductCategory::Frozen)->create(['name' => 'Frozen Pack']);
        Product::factory()->onBio()->category(ProductCategory::Menu)->create(['name' => 'Dimsum Ayam']);

        $html = $this->get(route('bio.products'))->assertOk()->getContent();

        $menuHeading = strpos($html, ProductCategory::Menu->publicHeading());
        $frozenHeading = strpos($html, ProductCategory::Frozen->publicHeading());

        $this->assertNotFalse($menuHeading);
        $this->assertNotFalse($frozenHeading);
        $this->assertLessThan($frozenHeading, $menuHeading, 'Urutan kelompok harus mengikuti enum.');
        $this->assertStringNotContainsString(ProductCategory::Varian->publicHeading(), $html);

        $view = (string) file_get_contents(resource_path('views/bio/products.blade.php'));

        foreach (ProductCategory::cases() as $category) {
            $this->assertStringNotContainsString($category->publicHeading(), $view, 'Nama kategori ditulis langsung di Blade.');
        }
    }

    public function test_the_card_shows_name_type_price_and_emoji_fallback(): void
    {
        Product::factory()
            ->onBio()
            ->type(ProductType::Paket)
            ->withNumericPrice(12500)
            ->withEmoji('🥟')
            ->create(['name' => 'Paket Hemat']);

        $this->get(route('bio.products'))
            ->assertOk()
            ->assertSee('Paket Hemat', false)
            ->assertSee(ProductType::Paket->label(), false)
            ->assertSee('Rp12.500', false)
            ->assertSee('🥟', false)
            ->assertDontSee('<img src=""', false)
            ->assertDontSee('Rp0', false);
    }

    public function test_a_missing_photo_file_never_becomes_an_empty_src(): void
    {
        Product::factory()->onBio()->withEmoji('🍤')->create([
            'name' => 'Foto Hilang',
            'image_path' => 'products/tidak-ada.jpg',
        ]);

        $this->get(route('bio.products'))
            ->assertOk()
            ->assertSee('🍤', false)
            ->assertDontSee('<img src=""', false)
            ->assertDontSee('tidak-ada.jpg', false);
    }

    public function test_the_page_has_an_empty_state(): void
    {
        $this->get(route('bio.products'))
            ->assertOk()
            ->assertSee('Menu sedang disiapkan', false);
    }

    /**
     * Kartu produk tidak membawa tombol detail -- halaman detail belum ada.
     */
    public function test_there_is_no_detail_button(): void
    {
        Product::factory()->onBio()->create();

        $this->get(route('bio.products'))
            ->assertOk()
            ->assertDontSee('Lihat Detail', false);
    }

    // ----------------------------------------------------------------- cache

    public function test_product_changes_appear_immediately(): void
    {
        $product = Product::factory()->onBio()->create(['name' => 'Nama Lama', 'price' => 5000]);

        $this->get(route('bio.products'))->assertOk()->assertSee('Nama Lama', false);

        $product->update(['name' => 'Nama Baru', 'price' => 7000]);

        $this->get(route('bio.products'))
            ->assertOk()
            ->assertSee('Nama Baru', false)
            ->assertSee('Rp7.000', false)
            ->assertDontSee('Nama Lama', false);
    }

    public function test_the_bio_toggle_takes_effect_immediately(): void
    {
        $product = Product::factory()->onBio()->create(['name' => 'Produk Saklar']);

        $this->get(route('bio.products'))->assertOk()->assertSee('Produk Saklar', false);

        $product->update(['show_on_bio' => false]);

        $this->get(route('bio.products'))->assertOk()->assertDontSee('Produk Saklar', false);
    }

    /**
     * Kunci cache Bio memuat versi katalog produk -- itulah yang membuat
     * reorder produk (tanpa event model) tetap tercermin.
     */
    public function test_the_cache_key_tracks_the_product_version(): void
    {
        Product::factory()->onBio()->create(['name' => 'Versi Awal']);

        $service = app(BioCatalogService::class);
        $before = $service->products();

        Product::query()->update(['name' => 'Ditulis Tanpa Event']);
        $this->assertSame($before, $service->products());

        ProductCatalogService::flushCache();

        $this->assertSame('Ditulis Tanpa Event', $service->products()[0]['items'][0]['name']);
    }

    // ----------------------------------------------------------------- query

    public function test_the_query_count_does_not_grow_per_product(): void
    {
        Product::factory()->onBio()->count(2)->create();
        $few = $this->productQueries();

        Product::factory()->onBio()->count(20)->create();
        $many = $this->productQueries();

        $this->assertSame(1, $few, 'Produk Bio seharusnya satu query.');
        $this->assertSame($few, $many);
    }

    protected function productQueries(): int
    {
        ProductCatalogService::flushCache();

        $queries = [];

        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->get(route('bio.products'))->assertOk();

        return count(array_filter($queries, fn (string $sql): bool => str_contains($sql, '"products"')));
    }
}
