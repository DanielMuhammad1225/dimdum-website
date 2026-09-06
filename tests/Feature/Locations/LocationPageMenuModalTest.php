<?php

namespace Tests\Feature\Locations;

use App\Enums\UserRole;
use App\Filament\Resources\LocationPages\Pages\EditLocationPage;
use App\Models\Location;
use App\Models\LocationArea;
use App\Models\LocationGroup;
use App\Models\LocationPage;
use App\Models\Product;
use App\Models\User;
use App\Services\LocationPageCatalogService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Dua perubahan tampilan yang diuji bersama karena keduanya menyangkut
 * "apa yang boleh muncul di mana":
 *
 *   1. Produk pada halaman lokasi tidak lagi menjadi section permanen. Ia
 *      dibuka lewat tombol "Lihat Menu" sebagai dialog.
 *   2. Daftar lokasi di homepage kini ditentukan saklar "Tampilkan di
 *      Homepage", bukan sekadar oleh status publikasi halamannya.
 *
 * Aturan yang paling mudah dilanggar dan karena itu dikunci di sini: halaman
 * yang saklarnya mati TETAP dapat dibuka lewat URL-nya.
 */
class LocationPageMenuModalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** @return array{0: LocationGroup, 1: Location} */
    protected function groupWithLocation(): array
    {
        $group = LocationGroup::factory()->create();
        $area = LocationArea::factory()->for($group, 'group')->create();
        $location = Location::factory()->for($area, 'area')->create(['name' => 'Gerobak Uji']);

        return [$group, $location];
    }

    /**
     * Halaman yang tampil publik dan punya gerobak layak tampil.
     */
    protected function page(array $attributes = [], Product ...$products): LocationPage
    {
        [$group, $location] = $this->groupWithLocation();

        $page = LocationPage::factory()->create([
            'slug' => 'alamat-menu',
            'title' => 'Alamat Menu',
            ...$attributes,
        ]);

        $page->groups()->attach($group);
        $page->locations()->attach($location);

        if ($products !== []) {
            $page->products()->attach(collect($products)->map->getKey()->all());
        }

        return $page->fresh();
    }

    protected function pageWithMenu(Product ...$products): LocationPage
    {
        return $this->page(['show_products' => true], ...$products);
    }

    // ------------------------------------------------ produk bukan section

    /**
     * Section permanen benar-benar hilang: yang tersisa hanya tombol dan
     * dialognya.
     */
    public function test_products_are_no_longer_a_permanent_section(): void
    {
        $this->pageWithMenu(Product::factory()->create(['name' => 'Dimsum Ayam']));

        $html = $this->get('/alamat/alamat-menu')->assertOk()->getContent();

        $this->assertStringNotContainsString('<section id="produk"', $html);
        $this->assertStringNotContainsString('Produk yang Tersedia', $html);

        // Kartunya sekarang hidup di dalam <dialog>, bukan di alur baca.
        $this->assertStringContainsString('<dialog', $html);
        $this->assertLessThan(
            strpos($html, 'Dimsum Ayam'),
            strpos($html, '<dialog'),
            'Kartu produk harus berada DI DALAM dialog, bukan sebelum dialognya.',
        );
    }

    /**
     * Teks yang diminta hilang benar-benar tidak ada lagi, dan modal lokasi
     * tidak menaut ke /produk.
     */
    public function test_the_retired_copy_and_the_catalog_link_are_gone(): void
    {
        $this->pageWithMenu(Product::factory()->create());

        $html = $this->get('/alamat/alamat-menu')->assertOk()->getContent();

        foreach ([
            'Ketersediaan produk bisa berbeda di setiap gerobak',
            'Lihat seluruh produk',
        ] as $retired) {
            $this->assertStringNotContainsString($retired, $html);
        }

        $this->assertStringNotContainsString(route('products.index'), $html);
    }

    /**
     * Belum ada halaman detail produk, jadi tidak boleh ada tombol yang
     * menjanjikannya.
     */
    public function test_the_modal_has_no_detail_button(): void
    {
        $this->pageWithMenu(Product::factory()->create());

        $this->get('/alamat/alamat-menu')
            ->assertOk()
            ->assertDontSee('Lihat Detail', false);
    }

    // ------------------------------------------------------- tombol menu

    public function test_the_menu_button_appears_when_valid_products_exist(): void
    {
        $this->pageWithMenu(Product::factory()->withNumericPrice(5000)->create(['name' => 'Dimsum Ayam']));

        $this->get('/alamat/alamat-menu')
            ->assertOk()
            ->assertSee('Lihat Menu', false)
            ->assertSee('data-menu-modal-open', false)
            ->assertSee('Dimsum Ayam', false)
            ->assertSee('Rp5.000', false);
    }

    public function test_the_menu_button_is_absent_without_the_toggle(): void
    {
        $this->page([], Product::factory()->create(['name' => 'Dimsum Ayam']));

        $this->get('/alamat/alamat-menu')
            ->assertOk()
            ->assertDontSee('Lihat Menu', false)
            ->assertDontSee('data-menu-modal', false);
    }

    public function test_the_menu_button_is_absent_without_any_product(): void
    {
        $this->pageWithMenu();

        $this->get('/alamat/alamat-menu')
            ->assertOk()
            ->assertDontSee('Lihat Menu', false)
            ->assertDontSee('data-menu-modal', false);
    }

    public function test_the_menu_button_is_absent_when_every_product_is_inactive(): void
    {
        $this->pageWithMenu(Product::factory()->inactive()->create(['name' => 'Dimsum Ayam']));

        $this->get('/alamat/alamat-menu')
            ->assertOk()
            ->assertDontSee('Lihat Menu', false)
            ->assertDontSee('Dimsum Ayam', false);
    }

    public function test_the_modal_only_carries_the_chosen_active_products(): void
    {
        $chosen = Product::factory()->create(['name' => 'Dipilih', 'sort_order' => 1]);
        $inactive = Product::factory()->inactive()->create(['name' => 'Dipilih Tapi Nonaktif', 'sort_order' => 2]);
        Product::factory()->create(['name' => 'Tidak Dipilih', 'sort_order' => 3]);

        $this->pageWithMenu($chosen, $inactive);

        $this->get('/alamat/alamat-menu')
            ->assertOk()
            ->assertSee('Dipilih', false)
            ->assertDontSee('Dipilih Tapi Nonaktif', false)
            ->assertDontSee('Tidak Dipilih', false);
    }

    public function test_the_modal_orders_by_the_product_catalog(): void
    {
        $third = Product::factory()->create(['name' => 'Ketiga', 'sort_order' => 30]);
        $first = Product::factory()->create(['name' => 'Pertama', 'sort_order' => 10]);
        $second = Product::factory()->create(['name' => 'Kedua', 'sort_order' => 20]);

        // Dipasang acak; urutan pemilihan tidak menentukan apa pun.
        $this->pageWithMenu($third, $first, $second);

        $html = $this->get('/alamat/alamat-menu')->assertOk()->getContent();

        $this->assertLessThan(strpos($html, 'Kedua'), strpos($html, 'Pertama'));
        $this->assertLessThan(strpos($html, 'Ketiga'), strpos($html, 'Kedua'));
    }

    // ----------------------------------------------------- aksesibilitas

    /**
     * Dialog dibangun di atas <dialog> asli, jadi focus trap, Escape, latar
     * inert, dan pengembalian fokus ke pemicu dijamin browser. Yang diuji di
     * sini adalah kontrak markup yang membuat semua itu berlaku.
     */
    public function test_the_modal_is_wired_up_accessibly(): void
    {
        $this->pageWithMenu(Product::factory()->create());

        $html = $this->get('/alamat/alamat-menu')->assertOk()->getContent();

        // Elemen dialog asli -- bukan div tiruan.
        $this->assertStringContainsString('<dialog', $html);
        $this->assertStringContainsString('id="menu-produk"', $html);

        // Dinamai oleh judulnya sendiri.
        $this->assertStringContainsString('aria-labelledby="menu-produk-judul"', $html);
        $this->assertStringContainsString('id="menu-produk-judul"', $html);

        // Pemicu menyebutkan bahwa ia membuka dialog, dan dialog mana.
        $this->assertStringContainsString('aria-haspopup="dialog"', $html);
        $this->assertStringContainsString('aria-controls="menu-produk"', $html);

        // Ada jalan keluar yang bisa diklik, dan namanya terbaca pembaca layar.
        $this->assertStringContainsString('data-menu-modal-close', $html);
        $this->assertStringContainsString('Tutup menu', $html);
    }

    /**
     * Perilaku yang tidak bisa diuji dari markup dikunci di sumber JS-nya.
     *
     * Bukan pengganti uji browser, tetapi cukup untuk menangkap penghapusan
     * tidak sengaja: tiga jalur penutupan, kunci scroll, dan tidak adanya
     * dependency baru.
     */
    public function test_the_modal_script_keeps_its_three_ways_out(): void
    {
        $script = (string) file_get_contents(resource_path('js/app.js'));

        // Tombol.
        $this->assertStringContainsString('data-menu-modal-close', $script);
        // Klik overlay: backdrop menargetkan elemen dialognya sendiri.
        $this->assertStringContainsString('event.target === menuModal', $script);
        // Escape ditangani <dialog>, dan 'close' menyatukan ketiga jalurnya.
        $this->assertStringContainsString("addEventListener('close'", $script);
        // Kunci scroll halaman.
        $this->assertStringContainsString('lockScroll', $script);
        // showModal() -- itulah yang memberi focus trap dan pengembalian fokus.
        $this->assertStringContainsString('showModal()', $script);

        // Tidak ada dependency baru untuk dialog ini.
        $packages = json_decode((string) file_get_contents(base_path('package.json')), true);

        foreach (array_keys($packages['dependencies'] ?? []) as $dependency) {
            $this->assertStringNotContainsStringIgnoringCase('modal', $dependency);
            $this->assertStringNotContainsStringIgnoringCase('dialog', $dependency);
        }
    }

    // ------------------------------------------------- toggle homepage

    public function test_the_form_labels_the_toggle_as_a_homepage_switch(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(UserRole::SuperAdmin->value);
        $this->actingAs($user->fresh());

        $page = $this->page();

        $html = $this->get("/admin/halaman-lokasi/{$page->getKey()}/edit")->assertOk()->getContent();

        $this->assertStringContainsString('Tampilkan di Homepage', $html);
        // Label lama tidak boleh tertinggal di mana pun.
        $this->assertStringNotContainsString('Sorot di homepage', $html);
    }

    /**
     * Kolom yang dipakai adalah is_featured yang sudah ada -- BUKAN kolom
     * duplikat baru.
     */
    public function test_the_toggle_reuses_the_existing_column(): void
    {
        $columns = Schema::getColumnListing('location_pages');

        $this->assertContains('is_featured', $columns);

        foreach (['show_on_homepage', 'is_homepage', 'display_on_homepage'] as $duplicate) {
            $this->assertNotContains(
                $duplicate,
                $columns,
                "Kolom {$duplicate} menduplikasi is_featured.",
            );
        }
    }

    public function test_only_pages_with_the_toggle_reach_the_homepage(): void
    {
        $this->page(['slug' => 'tampil', 'title' => 'Alamat Ditampilkan', 'is_featured' => true]);
        $this->page(['slug' => 'sembunyi', 'title' => 'Alamat Disembunyikan', 'is_featured' => false]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Alamat Ditampilkan', false)
            ->assertDontSee('Alamat Disembunyikan', false);
    }

    /**
     * Inti requirement: saklar homepage TIDAK menutup halamannya.
     */
    public function test_a_hidden_page_is_still_reachable_by_url(): void
    {
        $page = $this->page(['slug' => 'sembunyi', 'title' => 'Alamat Disembunyikan', 'is_featured' => false]);

        $this->assertTrue($page->isPubliclyVisible());

        $this->get('/alamat/sembunyi')
            ->assertOk()
            ->assertSee('Alamat Disembunyikan', false);
    }

    public function test_a_hidden_page_stays_in_the_sitemap(): void
    {
        $this->page(['slug' => 'sembunyi', 'is_featured' => false]);

        // Tidak tampil di homepage bukan berarti tidak layak diindeks.
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee(route('location-pages.show', 'sembunyi'), false);
    }

    public function test_an_inactive_page_never_reaches_the_homepage_even_with_the_toggle(): void
    {
        $this->page([
            'slug' => 'nonaktif',
            'title' => 'Alamat Nonaktif',
            'is_featured' => true,
            'is_active' => false,
        ]);

        $this->get('/')->assertOk()->assertDontSee('Alamat Nonaktif', false);
    }

    public function test_a_page_outside_its_period_never_reaches_the_homepage(): void
    {
        $this->page([
            'slug' => 'kedaluwarsa',
            'title' => 'Alamat Kedaluwarsa',
            'is_featured' => true,
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subDay(),
        ]);

        $this->get('/')->assertOk()->assertDontSee('Alamat Kedaluwarsa', false);
    }

    public function test_a_soft_deleted_page_never_reaches_the_homepage(): void
    {
        $page = $this->page(['title' => 'Alamat Terhapus', 'is_featured' => true]);

        $page->delete();

        $this->get('/')->assertOk()->assertDontSee('Alamat Terhapus', false);
    }

    public function test_a_page_without_visible_carts_never_reaches_the_homepage(): void
    {
        // Punya saklar, tapi tanpa gerobak sama sekali.
        LocationPage::factory()->onHomepage()->create(['title' => 'Alamat Tanpa Gerobak']);

        $this->get('/')->assertOk()->assertDontSee('Alamat Tanpa Gerobak', false);
    }

    // ------------------------------------------------------------- cache

    public function test_flipping_the_toggle_takes_effect_immediately(): void
    {
        $page = $this->page(['title' => 'Alamat Cache', 'is_featured' => false]);

        $this->get('/')->assertOk()->assertDontSee('Alamat Cache', false);

        $page->update(['is_featured' => true]);

        $this->get('/')->assertOk()->assertSee('Alamat Cache', false);

        $page->update(['is_featured' => false]);

        $this->get('/')->assertOk()->assertDontSee('Alamat Cache', false);
    }

    public function test_flipping_the_toggle_raises_the_cache_version(): void
    {
        $page = $this->page(['is_featured' => false]);

        $before = LocationPageCatalogService::cacheVersion();

        $page->update(['is_featured' => true]);

        $this->assertGreaterThan($before, LocationPageCatalogService::cacheVersion());
    }

    public function test_saving_the_toggle_from_the_panel_keeps_the_admin_signed_in(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(UserRole::SuperAdmin->value);
        $this->actingAs($user->fresh());

        $page = $this->page(['is_featured' => false]);

        Livewire::test(EditLocationPage::class, ['record' => $page->getKey()])
            ->fillForm(['is_featured' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($page->fresh()->is_featured);

        // Masih sebagai pengguna yang sama sesudahnya.
        $this->get('/admin/halaman-lokasi')->assertOk();
    }
}
