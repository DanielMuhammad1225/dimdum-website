<?php

namespace Tests\Feature\Locations;

use App\Models\Location;
use App\Models\LocationArea;
use App\Models\LocationImage;
use App\Services\LocationCatalogService;
use Database\Seeders\HomepageContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Halaman publik /lokasi dan /lokasi/{slug}.
 */
class PublicLocationPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function areaWithLocation(string $slug, array $areaAttributes = [], array $locationAttributes = []): LocationArea
    {
        $area = LocationArea::factory()->published()->create(['slug' => $slug, ...$areaAttributes]);

        Location::factory()->for($area, 'area')->published()->create($locationAttributes);

        return $area->fresh();
    }

    // ------------------------------------------------------------- /lokasi

    public function test_the_index_page_responds_successfully(): void
    {
        $this->get('/lokasi')->assertOk();
    }

    public function test_the_index_lists_only_areas_with_visible_locations(): void
    {
        $withLocation = $this->areaWithLocation('cianjur', ['name' => 'Cianjur']);

        $emptyArea = LocationArea::factory()->published()->create(['name' => 'Wilayah Kosong']);
        $draftArea = LocationArea::factory()->create(['name' => 'Wilayah Draft']);
        $inactiveArea = LocationArea::factory()->inactive()->create(['name' => 'Wilayah Nonaktif']);
        Location::factory()->for($inactiveArea, 'area')->published()->create();

        $response = $this->get('/lokasi');

        $response->assertOk()
            ->assertSee('Cianjur', false)
            ->assertDontSee('Wilayah Kosong', false)
            ->assertDontSee('Wilayah Draft', false)
            ->assertDontSee('Wilayah Nonaktif', false);

        $this->assertStringContainsString(route('locations.area', $withLocation->slug), $response->getContent());
    }

    public function test_the_index_shows_an_empty_state_when_nothing_is_published(): void
    {
        $this->get('/lokasi')
            ->assertOk()
            ->assertSee('Informasi lokasi segera hadir', false)
            ->assertSee('Kembali ke Beranda', false);
    }

    public function test_the_index_orders_areas_by_sort_order_then_name(): void
    {
        $this->areaWithLocation('bandung', ['name' => 'Bandung', 'sort_order' => 5]);
        $this->areaWithLocation('cianjur', ['name' => 'Cianjur', 'sort_order' => 1]);

        $content = $this->get('/lokasi')->getContent();

        $this->assertLessThan(
            strpos($content, 'Bandung'),
            strpos($content, 'Cianjur'),
            'sort_order lebih kecil harus tampil lebih dulu.'
        );
    }

    // ------------------------------------------------------ /lokasi/{slug}

    public function test_a_published_area_page_responds_successfully(): void
    {
        $this->areaWithLocation('cianjur', ['name' => 'Cianjur']);

        $this->get('/lokasi/cianjur')
            ->assertOk()
            ->assertSee('Lokasi Gerobak DIMDUM di Cianjur', false)
            ->assertSee('Pilih Lokasi Gerobak', false);
    }

    public function test_draft_inactive_scheduled_and_deleted_areas_return_404(): void
    {
        $draft = LocationArea::factory()->create(['slug' => 'draft']);
        Location::factory()->for($draft, 'area')->published()->create();

        $inactive = LocationArea::factory()->inactive()->create(['slug' => 'nonaktif']);
        Location::factory()->for($inactive, 'area')->published()->create();

        $scheduled = LocationArea::factory()->scheduled()->create(['slug' => 'terjadwal']);
        Location::factory()->for($scheduled, 'area')->published()->create();

        $deleted = $this->areaWithLocation('terhapus');
        $deleted->delete();

        foreach (['draft', 'nonaktif', 'terjadwal', 'terhapus', 'tidak-ada'] as $slug) {
            $this->get("/lokasi/{$slug}")->assertNotFound();
        }
    }

    public function test_a_published_area_without_visible_locations_stays_200_with_an_empty_state(): void
    {
        LocationArea::factory()->published()->create(['slug' => 'kuningan', 'name' => 'Kuningan']);

        $this->get('/lokasi/kuningan')
            ->assertOk()
            ->assertSee('Titik lokasi sedang diperbarui', false)
            ->assertSee('Lihat Wilayah Lain', false)
            // Tidak ada CTA atau alamat karangan.
            ->assertDontSee('Buka di Google Maps', false);
    }

    public function test_only_visible_locations_are_rendered(): void
    {
        $area = LocationArea::factory()->published()->create(['slug' => 'cianjur']);

        Location::factory()->for($area, 'area')->published()->create(['name' => 'Gerobak Tampil']);
        Location::factory()->for($area, 'area')->create(['name' => 'Gerobak Draft']);
        Location::factory()->for($area, 'area')->inactive()->create(['name' => 'Gerobak Nonaktif']);
        Location::factory()->for($area, 'area')->scheduled()->create(['name' => 'Gerobak Terjadwal']);

        $deleted = Location::factory()->for($area, 'area')->published()->create(['name' => 'Gerobak Terhapus']);
        $deleted->delete();

        $this->get('/lokasi/cianjur')
            ->assertOk()
            ->assertSee('Gerobak Tampil', false)
            ->assertDontSee('Gerobak Draft', false)
            ->assertDontSee('Gerobak Nonaktif', false)
            ->assertDontSee('Gerobak Terjadwal', false)
            ->assertDontSee('Gerobak Terhapus', false);
    }

    public function test_exactly_one_h1_is_rendered(): void
    {
        $this->areaWithLocation('cianjur');

        $this->assertSame(1, substr_count($this->get('/lokasi/cianjur')->getContent(), '<h1'));
        $this->assertSame(1, substr_count($this->get('/lokasi')->getContent(), '<h1'));
    }

    public function test_pages_contain_no_dead_links(): void
    {
        $this->areaWithLocation('cianjur');

        foreach (['/lokasi', '/lokasi/cianjur'] as $url) {
            $content = $this->get($url)->getContent();

            $this->assertStringNotContainsString('href="#"', $content);
            $this->assertStringNotContainsString('href=""', $content);
            $this->assertStringNotContainsString('src=""', $content);
        }
    }

    public function test_external_links_always_carry_a_safe_rel(): void
    {
        $this->areaWithLocation('cianjur', [], [
            'latitude' => -6.8123456,
            'longitude' => 107.1234567,
            'whatsapp_number' => '6281234567890',
        ]);

        $content = $this->get('/lokasi/cianjur')->getContent();

        preg_match_all('/<a\s[^>]*target="_blank"[^>]*>/i', $content, $matches);

        $this->assertNotEmpty($matches[0], 'Seharusnya ada tautan tab baru.');

        foreach ($matches[0] as $tag) {
            $this->assertStringContainsString('rel="noopener noreferrer"', $tag, "Tautan tanpa rel aman: {$tag}");
        }
    }

    // ---------------------------------------------------------------- filter

    public function test_the_filter_appears_only_when_there_is_more_than_one_group(): void
    {
        $area = LocationArea::factory()->published()->create(['slug' => 'cianjur']);

        Location::factory()->for($area, 'area')->published()->create(['filter_label' => 'Cipanas']);

        $this->get('/lokasi/cianjur')
            ->assertOk()
            ->assertDontSee('data-location-filter', false);

        Location::factory()->for($area, 'area')->published()->create(['filter_label' => 'Pacet']);

        $this->get('/lokasi/cianjur')
            ->assertOk()
            ->assertSee('data-location-filter', false)
            ->assertSee('aria-pressed', false)
            ->assertSee('aria-live="polite"', false)
            ->assertSee('Cipanas', false)
            ->assertSee('Pacet', false);
    }

    public function test_the_filter_falls_back_to_district(): void
    {
        $area = LocationArea::factory()->published()->create(['slug' => 'cianjur']);

        Location::factory()->for($area, 'area')->published()->create(['filter_label' => null, 'district' => 'Cipanas']);
        Location::factory()->for($area, 'area')->published()->create(['filter_label' => null, 'district' => 'Pacet']);

        $this->get('/lokasi/cianjur')
            ->assertOk()
            ->assertSee('Cipanas', false)
            ->assertSee('Pacet', false);
    }

    public function test_all_cards_stay_visible_without_javascript(): void
    {
        $area = LocationArea::factory()->published()->create(['slug' => 'cianjur']);

        Location::factory()->for($area, 'area')->published()->create(['name' => 'Gerobak A', 'filter_label' => 'Cipanas']);
        Location::factory()->for($area, 'area')->published()->create(['name' => 'Gerobak B', 'filter_label' => 'Pacet']);

        // Bahkan dengan ?filter=, server tetap mengirim seluruh kartu:
        // penyembunyian dilakukan skrip, bukan server.
        $this->get('/lokasi/cianjur?filter=Cipanas')
            ->assertOk()
            ->assertSee('Gerobak A', false)
            ->assertSee('Gerobak B', false);
    }

    // -------------------------------------------------------------- galeri

    public function test_the_gallery_only_shows_photos_from_this_area(): void
    {
        $area = LocationArea::factory()->published()->create(['slug' => 'cianjur']);
        $location = Location::factory()->for($area, 'area')->published()->create();
        LocationImage::factory()->for($location)->cover()->create([
            'image_path' => 'locations/aaa/foto-wilayah-ini.jpg',
            'alt_text' => 'Foto wilayah ini',
        ]);

        $otherArea = LocationArea::factory()->published()->create(['slug' => 'karawang']);
        $otherLocation = Location::factory()->for($otherArea, 'area')->published()->create();
        LocationImage::factory()->for($otherLocation)->cover()->create([
            'image_path' => 'locations/bbb/foto-wilayah-lain.jpg',
            'alt_text' => 'Foto wilayah lain',
        ]);

        // Berkasnya belum ada di disk, jadi keduanya dibuang lapisan render.
        // Yang diuji di sini: tidak ada kebocoran antar wilayah.
        $content = $this->get('/lokasi/cianjur')->getContent();

        $this->assertStringNotContainsString('foto-wilayah-lain', $content);
        $this->assertStringNotContainsString('Foto wilayah lain', $content);
    }

    public function test_a_missing_image_file_is_never_rendered(): void
    {
        $area = LocationArea::factory()->published()->create(['slug' => 'cianjur']);
        $location = Location::factory()->for($area, 'area')->published()->create();
        LocationImage::factory()->for($location)->create(['image_path' => 'locations/aaa/tidak-ada.jpg']);

        $content = $this->get('/lokasi/cianjur')->getContent();

        $this->assertStringNotContainsString('tidak-ada.jpg', $content);
        $this->assertStringNotContainsString('src=""', $content);
    }

    public function test_the_gallery_section_is_hidden_when_there_are_no_photos(): void
    {
        $this->areaWithLocation('cianjur', ['name' => 'Cianjur']);

        $this->get('/lokasi/cianjur')
            ->assertOk()
            ->assertDontSee('Suasana Gerobak di Cianjur', false);
    }

    // ----------------------------------------------------------- kartu data

    public function test_cards_only_render_fields_that_have_data(): void
    {
        $this->areaWithLocation('cianjur', [], [
            'name' => 'Gerobak Contoh Uji',
            'full_address' => 'Alamat uji lengkap',
            'landmark' => null,
            'operational_hours_text' => null,
        ]);

        $content = $this->get('/lokasi/cianjur')->getContent();

        $this->assertStringContainsString('Alamat uji lengkap', $content);
        $this->assertStringNotContainsString('Patokan:', $content, 'Label patokan tidak boleh tampil tanpa isi.');
    }

    public function test_a_location_without_maps_data_shows_a_status_not_a_dead_button(): void
    {
        $this->areaWithLocation('cianjur', [], [
            'latitude' => null,
            'longitude' => null,
            'google_maps_url' => null,
        ]);

        $content = $this->get('/lokasi/cianjur')->getContent();

        $this->assertStringContainsString('Tautan peta belum tersedia', $content);
        $this->assertStringNotContainsString('href="#"', $content);
    }

    /**
     * Nilai berbahaya yang ditulis LANGSUNG ke database tidak boleh menjadi
     * href. Pemeriksaan diulang saat render, bukan hanya saat validasi form.
     */
    public function test_a_dangerous_maps_url_written_straight_into_the_database_is_never_rendered(): void
    {
        $area = LocationArea::factory()->published()->create(['slug' => 'cianjur']);

        foreach ([
            'javascript:alert(1)',
            'data:text/html,<script>alert(1)</script>',
            '//evil.example.com/maps',
            'https://google.com.evil.example.com/maps',
            'http://www.google.com/maps',
        ] as $index => $url) {
            Location::factory()->for($area, 'area')->published()->create([
                'name' => 'Gerobak '.$index,
                'google_maps_url' => $url,
            ]);
        }

        $content = $this->get('/lokasi/cianjur')->getContent();

        $this->assertStringNotContainsString('javascript:', $content);
        $this->assertStringNotContainsString('data:text/html', $content);
        $this->assertStringNotContainsString('evil.example.com', $content);
        $this->assertStringNotContainsString('http://www.google.com', $content);

        // Semuanya jatuh ke status "belum tersedia", bukan tombol berbahaya.
        $this->assertSame(5, substr_count($content, 'Tautan peta belum tersedia'));
    }

    public function test_coordinates_produce_a_server_built_maps_link(): void
    {
        $this->areaWithLocation('cianjur', [], [
            'latitude' => -6.8123456,
            'longitude' => 107.1234567,
        ]);

        $this->get('/lokasi/cianjur')
            ->assertOk()
            ->assertSee('https://www.google.com/maps/search/?api=1&amp;query=-6.8123456%2C107.1234567', false)
            ->assertSee('Buka di Google Maps', false);
    }

    // ----------------------------------------------------------------- XSS

    public function test_admin_supplied_copy_is_escaped(): void
    {
        $this->areaWithLocation('cianjur', [
            'name' => '<script>alert("area")</script>',
            'headline' => '<script>alert("headline")</script>',
            'description' => '<img src=x onerror=alert(1)>',
        ], [
            'name' => '<script>alert("gerobak")</script>',
            'full_address' => '<script>alert("alamat")</script>',
        ]);

        $content = $this->get('/lokasi/cianjur')->getContent();

        foreach (['<script>alert("area")', '<script>alert("headline")', '<script>alert("gerobak")', '<script>alert("alamat")', '<img src=x'] as $payload) {
            $this->assertStringNotContainsString($payload, $content, "Payload tidak ter-escape: {$payload}");
        }

        $this->assertStringContainsString('&lt;script&gt;', $content);
    }

    // --------------------------------------------------------------- query

    public function test_the_area_page_query_count_does_not_grow_with_the_number_of_locations(): void
    {
        $area = LocationArea::factory()->published()->create(['slug' => 'cianjur']);

        Location::factory()->for($area, 'area')->published()->create();

        $baseline = $this->countQueries('/lokasi/cianjur');

        Location::factory()->count(12)->for($area, 'area')->published()->create();

        $withMany = $this->countQueries('/lokasi/cianjur');

        $this->assertSame(
            $baseline,
            $withMany,
            'Jumlah query harus tetap meski jumlah gerobak bertambah (tidak ada N+1).'
        );
    }

    public function test_the_area_page_query_count_stays_constant_with_images(): void
    {
        $area = LocationArea::factory()->published()->create(['slug' => 'cianjur']);
        $location = Location::factory()->for($area, 'area')->published()->create();

        $baseline = $this->countQueries('/lokasi/cianjur');

        LocationImage::factory()->count(8)->for($location)->create();

        $this->assertSame($baseline, $this->countQueries('/lokasi/cianjur'));
    }

    public function test_the_index_query_count_stays_constant(): void
    {
        $this->areaWithLocation('a-satu');
        $baseline = $this->countQueries('/lokasi');

        foreach (['b-dua', 'c-tiga', 'd-empat'] as $slug) {
            $this->areaWithLocation($slug);
        }

        $this->assertSame($baseline, $this->countQueries('/lokasi'));
    }

    // ------------------------------------------------------------ homepage

    public function test_the_homepage_links_areas_dynamically(): void
    {
        $this->seed(HomepageContentSeeder::class);

        $this->get('/')
            ->assertOk()
            ->assertSee('Informasi lokasi segera hadir', false);

        $area = $this->areaWithLocation('cianjur', ['name' => 'Cianjur']);

        $this->get('/')
            ->assertOk()
            ->assertSee('Cianjur', false)
            ->assertSee(route('locations.area', $area->slug), false)
            ->assertSee('Lihat Semua Wilayah', false)
            ->assertDontSee('Informasi lokasi segera hadir', false);
    }

    public function test_the_homepage_keeps_its_cms_managed_heading(): void
    {
        $this->seed(HomepageContentSeeder::class);
        $this->areaWithLocation('cianjur', ['name' => 'Cianjur']);

        $this->get('/')
            ->assertOk()
            // Judul dan deskripsi section tetap dari Homepage CMS.
            ->assertSee(config('homepage.locations.title'), false)
            ->assertSee(config('homepage.locations.description'), false);
    }

    // -------------------------------------------------------------- sitemap

    public function test_the_sitemap_lists_only_publishable_urls(): void
    {
        $this->areaWithLocation('cianjur');

        LocationArea::factory()->create(['slug' => 'draft']);
        $inactive = LocationArea::factory()->inactive()->create(['slug' => 'nonaktif']);
        Location::factory()->for($inactive, 'area')->published()->create();

        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        $this->assertStringContainsString('application/xml', $response->headers->get('Content-Type'));

        $xml = $response->getContent();

        $this->assertStringContainsString(route('home'), $xml);
        $this->assertStringContainsString(route('locations.index'), $xml);
        $this->assertStringContainsString(route('locations.area', 'cianjur'), $xml);
        $this->assertStringNotContainsString('/lokasi/draft', $xml);
        $this->assertStringNotContainsString('/lokasi/nonaktif', $xml);

        $this->assertNotFalse(simplexml_load_string($xml), 'Sitemap harus XML yang valid.');
    }

    // ---------------------------------------------------- tabel belum ada

    /**
     * Halaman publik harus tetap hidup ketika migration modul lokasi belum
     * dijalankan -- misalnya pada deploy yang urutannya belum rapi.
     *
     * Ini fallback yang SANGAT sempit: hanya untuk tabel yang belum dibuat,
     * dan kejadiannya dicatat sebagai warning.
     */
    public function test_pages_survive_when_the_location_tables_do_not_exist_yet(): void
    {
        $this->seed(HomepageContentSeeder::class);

        Schema::dropIfExists('location_images');
        Schema::dropIfExists('location_area_slug_redirects');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('location_areas');

        LocationCatalogService::flushCache();

        $this->get('/')->assertOk();
        $this->get('/lokasi')->assertOk()->assertSee('Informasi lokasi segera hadir', false);
        $this->get('/sitemap.xml')->assertOk();
        $this->get('/lokasi/apa-saja')->assertNotFound();
    }

    // ------------------------------------------------------- non-regression

    public function test_no_other_brand_string_or_asset_leaks_into_the_output(): void
    {
        $this->seed(HomepageContentSeeder::class);
        $this->areaWithLocation('cianjur');

        foreach (['/', '/lokasi', '/lokasi/cianjur', '/sitemap.xml'] as $url) {
            $content = $this->get($url)->getContent();

            $this->assertStringNotContainsStringIgnoringCase('dimsumin', $content, "Nama brand lain muncul di {$url}");
            $this->assertStringNotContainsStringIgnoringCase('dim sum in', $content);
            $this->assertStringNotContainsStringIgnoringCase('Enak · Bebas Pilih', $content);
            $this->assertStringNotContainsStringIgnoringCase('Bebas Pilih · Hemat', $content);
        }
    }

    public function test_existing_pages_still_work(): void
    {
        $this->seed(HomepageContentSeeder::class);

        $this->get('/')->assertOk();
        $this->get('/admin/login')->assertOk();
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    protected function countQueries(string $url): int
    {
        /*
         | flushCache() menaikkan versi, sehingga key data lama tidak bisa
         | terbaca lagi dan yang terukur benar-benar jalur database.
         |
         | Versi TIDAK boleh di-reset (misalnya dengan forget): menurunkannya
         | kembali ke angka lama justru membuat entri basi versi itu hidup lagi.
         */
        LocationCatalogService::flushCache();

        $count = 0;

        DB::listen(function () use (&$count): void {
            $count++;
        });

        $this->get($url)->assertOk();

        return $count;
    }
}
