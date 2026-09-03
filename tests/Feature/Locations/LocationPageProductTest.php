<?php

namespace Tests\Feature\Locations;

use App\Enums\ProductCategory;
use App\Enums\ProductType;
use App\Enums\UserRole;
use App\Filament\Resources\LocationPages\Pages\CreateLocationPage;
use App\Filament\Resources\LocationPages\Pages\EditLocationPage;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Location;
use App\Models\LocationArea;
use App\Models\LocationGroup;
use App\Models\LocationPage;
use App\Models\Product;
use App\Models\User;
use App\Services\LocationPageCatalogService;
use App\Services\LocationPageProductService;
use App\Services\ProductCatalogService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Section produk pada Halaman Slug Lokasi.
 *
 * Aturan yang dijaga di sini:
 *
 *   1. CRUD Produk tetap satu-satunya sumber data. Halaman hanya menyimpan
 *      relasi -- tidak ada nama, harga, foto, atau deskripsi yang disalin.
 *   2. Mematikan saklar MENYEMBUNYIKAN section, bukan menghapus pilihannya.
 *   3. show_on_homepage tidak berpengaruh apa pun di sini.
 *   4. Seluruh id diperiksa di SERVER, bukan hanya di form.
 */
class LocationPageProductTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function actingAsRole(UserRole $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role->value);

        $this->actingAs($user);

        return $user->fresh();
    }

    /** @return array{0: LocationGroup, 1: Location} */
    protected function groupWithLocation(): array
    {
        $group = LocationGroup::factory()->create();
        $area = LocationArea::factory()->for($group, 'group')->create();
        $location = Location::factory()->for($area, 'area')->create(['name' => 'Gerobak Uji']);

        return [$group, $location];
    }

    protected function pageWithProducts(Product ...$products): LocationPage
    {
        [$group, $location] = $this->groupWithLocation();

        $page = LocationPage::factory()->showingProducts()->create(['slug' => 'alamat-produk']);
        $page->groups()->attach($group);
        $page->locations()->attach($location);
        $page->products()->attach(collect($products)->map->getKey()->all());

        return $page;
    }

    // ---------------------------------------------------------------- schema

    public function test_the_toggle_column_exists_and_defaults_to_false(): void
    {
        $this->assertTrue(Schema::hasColumn('location_pages', 'show_products'));

        // Halaman yang sudah ada tidak boleh berubah tampilannya hanya karena
        // migration dijalankan.
        $page = LocationPage::factory()->create();

        $this->assertFalse($page->fresh()->show_products);
    }

    public function test_the_pivot_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('location_page_product'));

        foreach (['location_page_id', 'product_id'] as $column) {
            $this->assertTrue(Schema::hasColumn('location_page_product', $column));
        }
    }

    public function test_the_pivot_rejects_the_same_product_twice(): void
    {
        $page = LocationPage::factory()->create();
        $product = Product::factory()->create();

        $page->products()->attach($product);

        $this->expectException(QueryException::class);

        // Unique constraint di level database, bukan hanya sync() aplikasi.
        $page->products()->attach($product);
    }

    public function test_a_page_holds_several_products_and_a_product_serves_several_pages(): void
    {
        $first = Product::factory()->create();
        $second = Product::factory()->create();

        $pageA = LocationPage::factory()->create();
        $pageB = LocationPage::factory()->create();

        $pageA->products()->attach([$first->getKey(), $second->getKey()]);
        $pageB->products()->attach($first->getKey());

        $this->assertSame(2, $pageA->products()->count());
        $this->assertSame(1, $pageB->products()->count());
        $this->assertSame(2, $first->pages()->count());
    }

    /**
     * Halaman tidak menyimpan satu pun salinan fakta produk.
     */
    public function test_the_pivot_stores_no_copy_of_the_product_data(): void
    {
        $columns = Schema::getColumnListing('location_page_product');

        foreach (['name', 'price', 'price_text', 'image_path', 'description', 'emoji', 'category', 'type'] as $copied) {
            $this->assertNotContains($copied, $columns, "Kolom {$copied} menyalin data produk ke pivot.");
        }
    }

    // ------------------------------------------------------- validasi server

    public function test_a_product_id_that_does_not_exist_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('tidak ditemukan');

        app(LocationPageProductService::class)->assertProductsExist([999999]);
    }

    public function test_a_soft_deleted_product_cannot_be_selected(): void
    {
        $product = Product::factory()->create();
        $product->delete();

        $this->expectException(ValidationException::class);

        app(LocationPageProductService::class)->assertProductsExist([$product->getKey()]);
    }

    public function test_the_toggle_requires_at_least_one_product(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('minimal satu produk');

        app(LocationPageProductService::class)->assertSelectionIsUsable(true, []);
    }

    public function test_an_empty_selection_is_fine_while_the_toggle_is_off(): void
    {
        app(LocationPageProductService::class)->assertSelectionIsUsable(false, []);

        $this->addToAssertionCount(1);
    }

    public function test_ids_are_normalised_to_unique_integers(): void
    {
        $service = app(LocationPageProductService::class);

        $this->assertSame([3, 5], $service->normalizeIds(['3', 5, '3', 'bukan-angka', null]));
        $this->assertSame([], $service->normalizeIds('bukan array'));
    }

    /**
     * Label pilihan menyebut kategori, tipe, dan status -- bukan hanya nama.
     */
    public function test_the_options_describe_each_product(): void
    {
        $active = Product::factory()
            ->category(ProductCategory::Varian)
            ->type(ProductType::Mix)
            ->create(['name' => 'Dimsum Mix', 'sort_order' => 1]);

        $inactive = Product::factory()
            ->inactive()
            ->category(ProductCategory::Frozen)
            ->type(ProductType::FrozenFood)
            ->create(['name' => 'Frozen Pack', 'sort_order' => 2]);

        $options = app(LocationPageProductService::class)->options();

        $this->assertSame('Dimsum Mix · Varian · Mix · AKTIF', $options[$active->getKey()]);
        // Produk nonaktif tetap boleh dipilih, tetapi diberi tanda.
        $this->assertSame('Frozen Pack · Frozen · Frozen Food · NONAKTIF', $options[$inactive->getKey()]);
    }

    public function test_a_deleted_product_stays_visible_only_while_still_attached(): void
    {
        $product = Product::factory()->create(['name' => 'Pilihan Lama']);
        $product->delete();

        $service = app(LocationPageProductService::class);

        $this->assertArrayNotHasKey($product->getKey(), $service->options());
        $this->assertStringContainsString(
            'TERHAPUS',
            $service->options([$product->getKey()])[$product->getKey()],
        );
    }

    // ------------------------------------------------------------ form admin

    public function test_the_form_has_the_toggle_and_the_product_select(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $component = Livewire::test(CreateLocationPage::class);

        $component->assertFormFieldExists('show_products');
        $component->assertFormFieldExists('product_ids');
    }

    public function test_the_select_is_hidden_until_the_toggle_is_on(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        Livewire::test(CreateLocationPage::class)
            ->fillForm(['show_products' => false])
            ->assertFormFieldHidden('product_ids')
            ->fillForm(['show_products' => true])
            ->assertFormFieldVisible('product_ids');
    }

    public function test_a_page_can_be_created_with_products(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        [$group, $location] = $this->groupWithLocation();
        $product = Product::factory()->create(['name' => 'Dimsum Ayam']);

        Livewire::test(CreateLocationPage::class)
            ->fillForm([
                'title' => 'Alamat Dengan Produk',
                'slug' => 'alamat-dengan-produk',
                'group_ids' => [$group->getKey()],
                'location_ids' => [$location->getKey()],
                'show_products' => true,
                'product_ids' => [$product->getKey()],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $page = LocationPage::query()->where('slug', 'alamat-dengan-produk')->firstOrFail();

        $this->assertTrue($page->show_products);
        $this->assertSame([$product->getKey()], $page->products()->pluck('products.id')->all());
    }

    public function test_the_toggle_without_a_product_is_refused_by_the_form(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        [$group, $location] = $this->groupWithLocation();

        Livewire::test(CreateLocationPage::class)
            ->fillForm([
                'title' => 'Tanpa Produk',
                'slug' => 'tanpa-produk',
                'group_ids' => [$group->getKey()],
                'location_ids' => [$location->getKey()],
                'show_products' => true,
                'product_ids' => [],
            ])
            ->call('create')
            ->assertHasFormErrors(['product_ids']);

        $this->assertSame(0, LocationPage::query()->count());
    }

    public function test_a_forged_product_id_is_refused(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        [$group, $location] = $this->groupWithLocation();

        Livewire::test(CreateLocationPage::class)
            ->fillForm([
                'title' => 'Produk Karangan',
                'slug' => 'produk-karangan',
                'group_ids' => [$group->getKey()],
                'location_ids' => [$location->getKey()],
                'show_products' => true,
                'product_ids' => [123456],
            ])
            ->call('create')
            ->assertHasFormErrors(['product_ids']);

        $this->assertSame(0, LocationPage::query()->count());
    }

    /**
     * Pilihan yang tersimpan sudah terpasang di komponennya saat form dibuka,
     * apa pun status saklarnya -- itulah yang membuat menyalakan saklar
     * memunculkan pilihan lama, bukan daftar kosong.
     */
    public function test_the_selection_is_loaded_when_the_form_is_opened(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $product = Product::factory()->create();
        $page = $this->pageWithProducts($product);

        $state = Livewire::test(EditLocationPage::class, ['record' => $page->getKey()])->get('data');

        // Select multiple Filament menyimpan state-nya sebagai string.
        $this->assertSame([$product->getKey()], array_map('intval', $state['product_ids']));
    }

    /**
     * Inti requirement: mematikan saklar TIDAK menghapus relasinya.
     *
     * Filament membuang state komponen tersembunyi dari data terdehidrasi,
     * sehingga handler tidak menerima product_ids sama sekali. Ketiadaan key
     * itulah yang dipakai untuk memutuskan "jangan sentuh relasi" -- bukan
     * array kosong, yang justru akan menghapusnya.
     */
    public function test_turning_the_toggle_off_keeps_the_relation(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $product = Product::factory()->create();
        $page = $this->pageWithProducts($product);

        Livewire::test(EditLocationPage::class, ['record' => $page->getKey()])
            ->fillForm(['show_products' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $page->refresh();

        $this->assertFalse($page->show_products);
        $this->assertSame(
            [$product->getKey()],
            $page->products()->pluck('products.id')->all(),
            'Mematikan saklar tidak boleh menghapus pilihan produknya.',
        );
    }

    public function test_turning_the_toggle_back_on_restores_the_selection(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $product = Product::factory()->create();
        $page = $this->pageWithProducts($product);

        // Matikan, simpan.
        Livewire::test(EditLocationPage::class, ['record' => $page->getKey()])
            ->fillForm(['show_products' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        // Buka ulang: pilihan lama harus sudah terpasang di komponennya,
        // sehingga menyalakan saklar memunculkannya kembali.
        $component = Livewire::test(EditLocationPage::class, ['record' => $page->fresh()->getKey()]);

        $this->assertSame(
            [$product->getKey()],
            array_map('intval', $component->get('data')['product_ids']),
        );

        $component
            ->fillForm(['show_products' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $page->refresh();

        $this->assertTrue($page->show_products);
        $this->assertSame([$product->getKey()], $page->products()->pluck('products.id')->all());
    }

    public function test_the_product_selection_can_be_changed(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $first = Product::factory()->create(['name' => 'Pertama']);
        $second = Product::factory()->create(['name' => 'Kedua']);

        $page = $this->pageWithProducts($first);

        Livewire::test(EditLocationPage::class, ['record' => $page->getKey()])
            ->fillForm(['product_ids' => [$second->getKey()]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([$second->getKey()], $page->fresh()->products()->pluck('products.id')->all());
    }

    // --------------------------------------------------------------- publik

    public function test_the_section_is_absent_while_the_toggle_is_off(): void
    {
        $product = Product::factory()->create(['name' => 'Dimsum Ayam']);
        $page = $this->pageWithProducts($product);
        $page->update(['show_products' => false]);

        $this->get('/alamat/alamat-produk')
            ->assertOk()
            ->assertDontSee('Produk yang Tersedia', false)
            ->assertDontSee('Dimsum Ayam', false);
    }

    public function test_the_section_shows_the_selected_products(): void
    {
        $product = Product::factory()->withNumericPrice(5000)->create(['name' => 'Dimsum Ayam']);
        $this->pageWithProducts($product);

        $this->get('/alamat/alamat-produk')
            ->assertOk()
            ->assertSee('Produk yang Tersedia', false)
            ->assertSee('Dimsum Ayam', false)
            ->assertSee('Rp5.000', false);
    }

    public function test_only_the_chosen_products_appear(): void
    {
        $chosen = Product::factory()->create(['name' => 'Dipilih']);
        Product::factory()->create(['name' => 'Tidak Dipilih']);

        $this->pageWithProducts($chosen);

        $this->get('/alamat/alamat-produk')
            ->assertOk()
            ->assertSee('Dipilih', false)
            ->assertDontSee('Tidak Dipilih', false);
    }

    public function test_an_inactive_product_is_hidden_but_stays_attached(): void
    {
        $active = Product::factory()->create(['name' => 'Masih Aktif', 'sort_order' => 1]);
        $inactive = Product::factory()->inactive()->create(['name' => 'Sudah Nonaktif', 'sort_order' => 2]);

        $page = $this->pageWithProducts($active, $inactive);

        $this->get('/alamat/alamat-produk')
            ->assertOk()
            ->assertSee('Masih Aktif', false)
            ->assertDontSee('Sudah Nonaktif', false);

        // Relasinya tetap ada, jadi mengaktifkan produknya kembali cukup.
        $this->assertSame(2, $page->products()->count());
    }

    public function test_a_soft_deleted_product_is_hidden(): void
    {
        $kept = Product::factory()->create(['name' => 'Tetap Ada']);
        $removed = Product::factory()->create(['name' => 'Sudah Dihapus']);

        $this->pageWithProducts($kept, $removed);

        $removed->delete();

        $this->get('/alamat/alamat-produk')
            ->assertOk()
            ->assertSee('Tetap Ada', false)
            ->assertDontSee('Sudah Dihapus', false);
    }

    /**
     * Seluruh produk terpilih menjadi nonaktif: section-nya hilang rapi tanpa
     * judul menggantung, kartu kosong, atau gambar rusak.
     */
    public function test_the_section_disappears_when_every_product_becomes_inactive(): void
    {
        $product = Product::factory()->create(['name' => 'Dimsum Ayam']);
        $this->pageWithProducts($product);

        $this->get('/alamat/alamat-produk')->assertOk()->assertSee('Produk yang Tersedia', false);

        $product->update(['is_active' => false]);

        $response = $this->get('/alamat/alamat-produk')->assertOk();

        $response->assertDontSee('Produk yang Tersedia', false);
        $response->assertDontSee('<img src=""', false);
        $response->assertDontSee('Dimsum Ayam', false);
    }

    public function test_the_homepage_switch_does_not_affect_the_location_page(): void
    {
        // Produk yang TIDAK disorot di homepage tetap tampil di sini.
        $product = Product::factory()->create(['name' => 'Bukan Sorotan', 'show_on_homepage' => false]);
        $this->pageWithProducts($product);

        $this->get('/alamat/alamat-produk')->assertOk()->assertSee('Bukan Sorotan', false);

        // Dan tetap tidak muncul di homepage.
        $this->get('/')->assertOk()->assertDontSee('Bukan Sorotan', false);
    }

    public function test_the_order_follows_the_product_catalog(): void
    {
        $third = Product::factory()->create(['name' => 'Ketiga', 'sort_order' => 30]);
        $first = Product::factory()->create(['name' => 'Pertama', 'sort_order' => 10]);
        $second = Product::factory()->create(['name' => 'Kedua', 'sort_order' => 20]);

        // Dipasang dengan urutan acak; urutan pemilihan tidak menentukan apa pun.
        $this->pageWithProducts($third, $first, $second);

        $html = $this->get('/alamat/alamat-produk')->assertOk()->getContent();

        $this->assertLessThan(strpos($html, 'Pertama'), strpos($html, 'Produk yang Tersedia'));
        $this->assertLessThan(strpos($html, 'Kedua'), strpos($html, 'Pertama'));
        $this->assertLessThan(strpos($html, 'Ketiga'), strpos($html, 'Kedua'));
    }

    public function test_a_product_without_a_price_shows_no_price_label(): void
    {
        $product = Product::factory()->create(['name' => 'Tanpa Harga']);
        $this->pageWithProducts($product);

        $this->get('/alamat/alamat-produk')
            ->assertOk()
            ->assertSee('Tanpa Harga', false)
            ->assertDontSee('Rp0', false);
    }

    public function test_the_emoji_is_used_when_a_product_has_no_photo(): void
    {
        $product = Product::factory()->withEmoji('🥟')->create(['name' => 'Berlambang']);
        $this->pageWithProducts($product);

        $this->get('/alamat/alamat-produk')
            ->assertOk()
            ->assertSee('🥟', false)
            ->assertDontSee('<img src=""', false);
    }

    /**
     * Jumlah query TIDAK bertambah per produk.
     *
     * Diuji sebagai sifat, bukan sebagai angka mati: satu halaman dengan satu
     * produk dan satu halaman dengan dua belas produk harus menghasilkan
     * jumlah query yang sama persis. Angka mutlaknya milik halaman lokasi yang
     * sudah ada dan tidak perlu dikunci di sini.
     */
    public function test_the_section_adds_no_query_per_product(): void
    {
        $one = $this->pageWithManyProducts('alamat-satu-produk', 1);
        $many = $this->pageWithManyProducts('alamat-banyak-produk', 12);

        $withOne = $this->countQueries('/alamat/'.$one);
        $withMany = $this->countQueries('/alamat/'.$many);

        $this->assertSame(
            count($withOne),
            count($withMany),
            "Query bertambah saat produknya banyak.\nSatu produk: ".count($withOne)
            .' query. Dua belas produk: '.count($withMany).' query.',
        );

        // Dan tepat SATU di antaranya yang menyentuh pivot produk.
        $this->assertSame(
            1,
            count(array_filter($withMany, fn (string $sql): bool => str_contains($sql, 'location_page_product'))),
            'Pivot produk seharusnya dibaca sekali saja.',
        );
    }

    protected function pageWithManyProducts(string $slug, int $count): string
    {
        [$group, $location] = $this->groupWithLocation();

        $page = LocationPage::factory()->showingProducts()->create(['slug' => $slug]);
        $page->groups()->attach($group);
        $page->locations()->attach($location);
        $page->products()->attach(
            Product::factory()->count($count)->create()->pluck('id')->all()
        );

        return $slug;
    }

    /**
     * @return list<string>
     */
    protected function countQueries(string $uri): array
    {
        // Permintaan pertama mengisi cache; yang dihitung adalah render dingin
        // berikutnya pada halaman yang berbeda, jadi cache-nya masih kosong.
        $queries = [];

        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->get($uri)->assertOk();

        DB::flushQueryLog();

        return $queries;
    }

    // ---------------------------------------------------------------- cache

    public function test_changing_the_toggle_invalidates_the_page_cache(): void
    {
        $product = Product::factory()->create();
        $page = $this->pageWithProducts($product);

        $before = LocationPageCatalogService::cacheVersion();
        $page->update(['show_products' => false]);

        $this->assertGreaterThan($before, LocationPageCatalogService::cacheVersion());
    }

    public function test_changing_the_selection_invalidates_the_page_cache(): void
    {
        $first = Product::factory()->create();
        $second = Product::factory()->create();
        $page = $this->pageWithProducts($first);

        $before = LocationPageCatalogService::cacheVersion();

        app(LocationPageProductService::class)->sync($page, [$second->getKey()]);

        $this->assertGreaterThan(
            $before,
            LocationPageCatalogService::cacheVersion(),
            'sync() menulis tanpa event model, jadi versinya harus dinaikkan sendiri.',
        );
    }

    public function test_editing_a_product_invalidates_the_page_cache(): void
    {
        $product = Product::factory()->create(['name' => 'Nama Lama']);
        $this->pageWithProducts($product);

        $this->get('/alamat/alamat-produk')->assertOk()->assertSee('Nama Lama', false);

        $product->update(['name' => 'Nama Baru', 'price' => 7000]);

        $this->get('/alamat/alamat-produk')
            ->assertOk()
            ->assertSee('Nama Baru', false)
            ->assertSee('Rp7.000', false)
            ->assertDontSee('Nama Lama', false);
    }

    public function test_every_product_lifecycle_event_invalidates_the_page_cache(): void
    {
        $product = Product::factory()->create();
        $this->pageWithProducts($product);

        foreach (['delete', 'restore'] as $action) {
            $before = LocationPageCatalogService::cacheVersion();

            $product->{$action}();

            $this->assertGreaterThan(
                $before,
                LocationPageCatalogService::cacheVersion(),
                "{$action}() pada produk tidak menaikkan versi cache halaman lokasi.",
            );
        }
    }

    public function test_reordering_products_invalidates_both_caches(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $a = Product::factory()->create(['sort_order' => 1]);
        $b = Product::factory()->create(['sort_order' => 2]);

        $beforeProducts = ProductCatalogService::cacheVersion();
        $beforePages = LocationPageCatalogService::cacheVersion();

        Livewire::test(ListProducts::class)
            ->call('reorderTable', [(string) $b->getKey(), (string) $a->getKey()]);

        $this->assertGreaterThan($beforeProducts, ProductCatalogService::cacheVersion());
        $this->assertGreaterThan(
            $beforePages,
            LocationPageCatalogService::cacheVersion(),
            'Urutan produk juga menentukan susunan kartu di halaman lokasi.',
        );
    }

    public function test_deactivating_a_product_takes_effect_immediately(): void
    {
        $product = Product::factory()->create(['name' => 'Dimsum Ayam']);
        $this->pageWithProducts($product);

        $this->get('/alamat/alamat-produk')->assertOk()->assertSee('Dimsum Ayam', false);

        $product->update(['is_active' => false]);

        $this->get('/alamat/alamat-produk')->assertOk()->assertDontSee('Dimsum Ayam', false);
    }

    // -------------------------------------------------------- authorization

    public function test_an_operator_cannot_reach_the_product_selection(): void
    {
        $product = Product::factory()->create();
        $page = $this->pageWithProducts($product);

        $this->actingAsRole(UserRole::Operator);

        // Operator tidak punya satu pun permission Halaman Slug Lokasi.
        $this->get('/admin/halaman-lokasi')->assertForbidden();
        $this->get("/admin/halaman-lokasi/{$page->getKey()}/edit")->assertForbidden();
    }

    public function test_admin_and_super_admin_can_edit_the_selection(): void
    {
        foreach ([UserRole::SuperAdmin, UserRole::Admin] as $role) {
            $product = Product::factory()->create();
            $page = LocationPage::factory()->showingProducts()->create();
            $page->products()->attach($product);

            $this->actingAsRole($role);

            $this->get("/admin/halaman-lokasi/{$page->getKey()}/edit")->assertOk();
        }
    }

    // ------------------------------------------------- tidak merembet ke lain

    public function test_the_feature_does_not_touch_the_gerobak_selection(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $product = Product::factory()->create();
        $page = $this->pageWithProducts($product);

        $locationIds = $page->locations()->pluck('locations.id')->all();
        $groupIds = $page->groups()->pluck('location_groups.id')->all();

        Livewire::test(EditLocationPage::class, ['record' => $page->getKey()])
            ->fillForm(['show_products' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $page->refresh();

        $this->assertSame($locationIds, $page->locations()->pluck('locations.id')->all());
        $this->assertSame($groupIds, $page->groups()->pluck('location_groups.id')->all());
    }

    public function test_the_catalog_page_is_unaffected(): void
    {
        $onPage = Product::factory()->create(['name' => 'Dipakai Halaman']);
        $notOnPage = Product::factory()->create(['name' => 'Tidak Dipakai']);

        $this->pageWithProducts($onPage);

        // /produk tetap katalog lengkap: dipakai halaman lokasi atau tidak,
        // keduanya tampil.
        $this->get('/produk')
            ->assertOk()
            ->assertSee('Dipakai Halaman', false)
            ->assertSee('Tidak Dipakai', false);
    }

    public function test_the_homepage_is_unaffected(): void
    {
        $onPage = Product::factory()->create(['name' => 'Hanya Halaman Lokasi']);
        $onHomepage = Product::factory()->onHomepage()->create(['name' => 'Kartu Homepage']);

        $this->pageWithProducts($onPage);

        $this->get('/')
            ->assertOk()
            ->assertSee('Kartu Homepage', false)
            ->assertDontSee('Hanya Halaman Lokasi', false);
    }
}
