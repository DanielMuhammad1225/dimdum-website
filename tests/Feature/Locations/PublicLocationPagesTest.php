<?php

namespace Tests\Feature\Locations;

use App\Models\Location;
use App\Models\LocationArea;
use App\Models\LocationGroup;
use App\Models\LocationImage;
use App\Models\Province;
use App\Services\LocationCatalogService;
use Database\Seeders\HomepageContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Halaman publik /lokasi, /lokasi/{province}, dan /lokasi/{province}/{area}.
 */
class PublicLocationPagesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Provinsi tetap dengan slug yang dapat ditebak, supaya URL dua segmen di
     * seluruh test ini deterministik.
     */
    protected function province(string $slug = 'jawa-barat', string $name = 'Jawa Barat'): Province
    {
        return Province::query()->firstWhere('slug', $slug)
            ?? Province::factory()->published()->create(['name' => $name, 'slug' => $slug]);
    }

    protected function group(?Province $province = null): LocationGroup
    {
        $province ??= $this->province();

        return LocationGroup::query()->where('province_id', $province->getKey())->first()
            ?? LocationGroup::factory()->for($province, 'province')->create(['name' => 'Kabupaten Uji']);
    }

    protected function areaWithLocation(string $slug, array $areaAttributes = [], array $locationAttributes = []): LocationArea
    {
        $area = LocationArea::factory()->for($this->group(), 'group')
            ->for($this->group(), 'group')
            ->published()
            ->create(['slug' => $slug, ...$areaAttributes]);

        Location::factory()->for($area, 'area')->published()->create($locationAttributes);

        return $area->fresh();
    }

    /** URL area publik: dua segmen. */
    protected function areaUrl(string $areaSlug, string $provinceSlug = 'jawa-barat'): string
    {
        return route('locations.area', [$provinceSlug, $areaSlug]);
    }

    protected function provinceUrl(string $provinceSlug = 'jawa-barat'): string
    {
        return route('locations.province', $provinceSlug);
    }

    // ------------------------------------------------------------- /lokasi

    public function test_the_index_page_responds_successfully(): void
    {
        $this->get('/lokasi')->assertOk();
    }

    public function test_the_index_lists_only_provinces_with_visible_locations(): void
    {
        $this->areaWithLocation('cianjur', ['name' => 'Cianjur']);

        // Provinsi terbit tetapi areanya masih draft -> tidak boleh tampil.
        $empty = $this->province('banten', 'Banten');
        LocationArea::factory()->for($this->group($empty), 'group')->create(['name' => 'Area Draft']);

        // Provinsi draft dengan area terbit -> tetap tidak boleh tampil.
        $draftProvince = Province::factory()->create(['name' => 'Provinsi Draft', 'slug' => 'provinsi-draft']);
        $draftArea = LocationArea::factory()->for($this->group(), 'group')
            ->for($this->group($draftProvince), 'group')
            ->published()
            ->create(['name' => 'Area Tersembunyi']);
        Location::factory()->for($draftArea, 'area')->published()->create();

        $response = $this->get('/lokasi');

        $response->assertOk()
            ->assertSee('Jawa Barat', false)
            ->assertDontSee('Banten', false)
            ->assertDontSee('Provinsi Draft', false)
            ->assertDontSee('Area Tersembunyi', false);

        $this->assertStringContainsString($this->provinceUrl(), $response->getContent());
    }

    // --------------------------------------------------- /lokasi/{province}

    public function test_the_province_page_groups_areas_under_their_city_or_group(): void
    {
        $province = $this->province();
        $administrative = LocationGroup::factory()->for($province, 'province')
            ->create(['name' => 'Kabupaten Cianjur']);
        $marketing = LocationGroup::factory()->for($province, 'province')->marketingGroup()
            ->create(['name' => 'Bandung Raya']);

        foreach ([[$administrative, 'cipanas', 'Cipanas'], [$marketing, 'dago', 'Dago']] as [$group, $slug, $name]) {
            $area = LocationArea::factory()->for($group, 'group')->published()->create(['slug' => $slug, 'name' => $name]);
            Location::factory()->for($area, 'area')->published()->create();
        }

        $content = $this->get($this->provinceUrl())->assertOk()->getContent();

        foreach ([
            'Kabupaten Cianjur', 'Bandung Raya', 'Cipanas', 'Dago',
            'Kota/Kabupaten Administratif', 'Grup Wilayah/Pemasaran',
        ] as $needle) {
            $this->assertStringContainsString($needle, $content, "{$needle} tidak dirender.");
        }

        // Kota/Grup adalah heading, BUKAN tautan -- ia tidak punya halaman.
        $this->assertStringContainsString($this->areaUrl('cipanas'), $content);
        $this->assertStringNotContainsString('/lokasi/jawa-barat/kabupaten-cianjur', $content);
    }

    public function test_a_group_slug_has_no_public_route_of_its_own(): void
    {
        $province = $this->province();
        $group = LocationGroup::factory()->for($province, 'province')->create(['slug' => 'kabupaten-cianjur']);
        $area = LocationArea::factory()->for($group, 'group')->published()->create(['slug' => 'cipanas']);
        Location::factory()->for($area, 'area')->published()->create();

        // Slug grup di posisi Area harus 404, bukan menampilkan halaman apa pun.
        $this->get($this->areaUrl('kabupaten-cianjur'))->assertNotFound();
    }

    public function test_hidden_provinces_return_404(): void
    {
        foreach ([
            'draft' => Province::factory()->create(['slug' => 'p-draft']),
            'nonaktif' => Province::factory()->inactive()->create(['slug' => 'p-nonaktif']),
            'terjadwal' => Province::factory()->scheduled()->create(['slug' => 'p-terjadwal']),
        ] as $label => $province) {
            $area = LocationArea::factory()->for($this->group($province), 'group')->published()->create();
            Location::factory()->for($area, 'area')->published()->create();

            $this->get(route('locations.province', $province->slug))
                ->assertNotFound("Provinsi {$label} seharusnya 404.");
        }
    }

    public function test_the_province_page_shows_an_empty_state_without_visible_areas(): void
    {
        $province = $this->province();
        LocationArea::factory()->for($this->group($province), 'group')->create();

        // Provinsi terbit tanpa area tampil tetap 200 -- bukan 404 -- supaya
        // iklan yang sudah berjalan tidak mendarat di halaman error.
        $this->get($this->provinceUrl())
            ->assertOk()
            ->assertSee('sedang disiapkan', false);
    }

    public function test_the_index_shows_an_empty_state_when_nothing_is_published(): void
    {
        $this->get('/lokasi')
            ->assertOk()
            ->assertSee('Informasi lokasi segera hadir', false)
            ->assertSee('Kembali ke Beranda', false);
    }

    public function test_the_province_page_orders_areas_by_sort_order_then_name(): void
    {
        $this->areaWithLocation('bandung', ['name' => 'Bandung', 'sort_order' => 5]);
        $this->areaWithLocation('cianjur', ['name' => 'Cianjur', 'sort_order' => 1]);

        $content = $this->get($this->provinceUrl())->getContent();

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

        $this->get($this->areaUrl('cianjur'))
            ->assertOk()
            ->assertSee('Lokasi Gerobak DIMDUM di Cianjur', false)
            ->assertSee('Pilih Lokasi Gerobak', false);
    }

    public function test_draft_inactive_scheduled_and_deleted_areas_return_404(): void
    {
        $draft = LocationArea::factory()->for($this->group(), 'group')->create(['slug' => 'draft']);
        Location::factory()->for($draft, 'area')->published()->create();

        $inactive = LocationArea::factory()->for($this->group(), 'group')->inactive()->create(['slug' => 'nonaktif']);
        Location::factory()->for($inactive, 'area')->published()->create();

        $scheduled = LocationArea::factory()->for($this->group(), 'group')->scheduled()->create(['slug' => 'terjadwal']);
        Location::factory()->for($scheduled, 'area')->published()->create();

        $deleted = $this->areaWithLocation('terhapus');
        $deleted->delete();

        foreach (['draft', 'nonaktif', 'terjadwal', 'terhapus', 'tidak-ada'] as $slug) {
            $this->get($this->areaUrl($slug))->assertNotFound();
        }
    }

    public function test_a_published_area_without_visible_locations_stays_200_with_an_empty_state(): void
    {
        LocationArea::factory()->for($this->group(), 'group')->published()->create(['slug' => 'kuningan', 'name' => 'Kuningan']);

        $this->get($this->areaUrl('kuningan'))
            ->assertOk()
            ->assertSee('Titik lokasi sedang diperbarui', false)
            ->assertSee('Lihat Area Lain', false)
            // Tidak ada CTA atau alamat karangan.
            ->assertDontSee('Buka di Google Maps', false);
    }

    public function test_only_visible_locations_are_rendered(): void
    {
        $area = LocationArea::factory()->for($this->group(), 'group')->published()->create(['slug' => 'cianjur']);

        Location::factory()->for($area, 'area')->published()->create(['name' => 'Gerobak Tampil']);
        Location::factory()->for($area, 'area')->create(['name' => 'Gerobak Draft']);
        Location::factory()->for($area, 'area')->inactive()->create(['name' => 'Gerobak Nonaktif']);
        Location::factory()->for($area, 'area')->scheduled()->create(['name' => 'Gerobak Terjadwal']);

        $deleted = Location::factory()->for($area, 'area')->published()->create(['name' => 'Gerobak Terhapus']);
        $deleted->delete();

        $this->get($this->areaUrl('cianjur'))
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

        $this->assertSame(1, substr_count($this->get($this->areaUrl('cianjur'))->getContent(), '<h1'));
        $this->assertSame(1, substr_count($this->get('/lokasi')->getContent(), '<h1'));
    }

    public function test_pages_contain_no_dead_links(): void
    {
        $this->areaWithLocation('cianjur');

        foreach (['/lokasi', $this->provinceUrl(), $this->areaUrl('cianjur')] as $url) {
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

        $content = $this->get($this->areaUrl('cianjur'))->getContent();

        preg_match_all('/<a\s[^>]*target="_blank"[^>]*>/i', $content, $matches);

        $this->assertNotEmpty($matches[0], 'Seharusnya ada tautan tab baru.');

        foreach ($matches[0] as $tag) {
            $this->assertStringContainsString('rel="noopener noreferrer"', $tag, "Tautan tanpa rel aman: {$tag}");
        }
    }

    public function test_the_gallery_only_shows_photos_from_this_area(): void
    {
        $area = LocationArea::factory()->for($this->group(), 'group')->published()->create(['slug' => 'cianjur']);
        $location = Location::factory()->for($area, 'area')->published()->create();
        LocationImage::factory()->for($location)->cover()->create([
            'image_path' => 'locations/aaa/foto-wilayah-ini.jpg',
            'alt_text' => 'Foto wilayah ini',
        ]);

        $otherArea = LocationArea::factory()->for($this->group(), 'group')->published()->create(['slug' => 'karawang']);
        $otherLocation = Location::factory()->for($otherArea, 'area')->published()->create();
        LocationImage::factory()->for($otherLocation)->cover()->create([
            'image_path' => 'locations/bbb/foto-wilayah-lain.jpg',
            'alt_text' => 'Foto wilayah lain',
        ]);

        // Berkasnya belum ada di disk, jadi keduanya dibuang lapisan render.
        // Yang diuji di sini: tidak ada kebocoran antar wilayah.
        $content = $this->get($this->areaUrl('cianjur'))->getContent();

        $this->assertStringNotContainsString('foto-wilayah-lain', $content);
        $this->assertStringNotContainsString('Foto wilayah lain', $content);
    }

    public function test_a_missing_image_file_is_never_rendered(): void
    {
        $area = LocationArea::factory()->for($this->group(), 'group')->published()->create(['slug' => 'cianjur']);
        $location = Location::factory()->for($area, 'area')->published()->create();
        LocationImage::factory()->for($location)->create(['image_path' => 'locations/aaa/tidak-ada.jpg']);

        $content = $this->get($this->areaUrl('cianjur'))->getContent();

        $this->assertStringNotContainsString('tidak-ada.jpg', $content);
        $this->assertStringNotContainsString('src=""', $content);
    }

    public function test_the_gallery_section_is_hidden_when_there_are_no_photos(): void
    {
        $this->areaWithLocation('cianjur', ['name' => 'Cianjur']);

        $this->get($this->areaUrl('cianjur'))
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

        $content = $this->get($this->areaUrl('cianjur'))->getContent();

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

        $content = $this->get($this->areaUrl('cianjur'))->getContent();

        $this->assertStringContainsString('Tautan peta belum tersedia', $content);
        $this->assertStringNotContainsString('href="#"', $content);
    }

    /**
     * Nilai berbahaya yang ditulis LANGSUNG ke database tidak boleh menjadi
     * href. Pemeriksaan diulang saat render, bukan hanya saat validasi form.
     */
    public function test_a_dangerous_maps_url_written_straight_into_the_database_is_never_rendered(): void
    {
        $area = LocationArea::factory()->for($this->group(), 'group')->published()->create(['slug' => 'cianjur']);

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

        $content = $this->get($this->areaUrl('cianjur'))->getContent();

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

        $this->get($this->areaUrl('cianjur'))
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

        $content = $this->get($this->areaUrl('cianjur'))->getContent();

        foreach (['<script>alert("area")', '<script>alert("headline")', '<script>alert("gerobak")', '<script>alert("alamat")', '<img src=x'] as $payload) {
            $this->assertStringNotContainsString($payload, $content, "Payload tidak ter-escape: {$payload}");
        }

        $this->assertStringContainsString('&lt;script&gt;', $content);
    }

    // --------------------------------------------------------------- query

    public function test_the_area_page_query_count_does_not_grow_with_the_number_of_locations(): void
    {
        $area = LocationArea::factory()->for($this->group(), 'group')->published()->create(['slug' => 'cianjur']);

        Location::factory()->for($area, 'area')->published()->create();

        $baseline = $this->countQueries($this->areaUrl('cianjur'));

        Location::factory()->count(12)->for($area, 'area')->published()->create();

        $withMany = $this->countQueries($this->areaUrl('cianjur'));

        $this->assertSame(
            $baseline,
            $withMany,
            'Jumlah query harus tetap meski jumlah gerobak bertambah (tidak ada N+1).'
        );
    }

    public function test_the_area_page_query_count_stays_constant_with_images(): void
    {
        $area = LocationArea::factory()->for($this->group(), 'group')->published()->create(['slug' => 'cianjur']);
        $location = Location::factory()->for($area, 'area')->published()->create();

        $baseline = $this->countQueries($this->areaUrl('cianjur'));

        LocationImage::factory()->count(8)->for($location)->create();

        $this->assertSame($baseline, $this->countQueries($this->areaUrl('cianjur')));
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
            ->assertSee($this->areaUrl($area->slug), false)
            ->assertSee('Lihat Semua Lokasi', false)
            // Konteks provinsi berasal dari hierarki, bukan kolom teks.
            ->assertSee('Jawa Barat', false)
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

        LocationArea::factory()->for($this->group(), 'group')->create(['slug' => 'draft']);
        $inactive = LocationArea::factory()->for($this->group(), 'group')->inactive()->create(['slug' => 'nonaktif']);
        Location::factory()->for($inactive, 'area')->published()->create();

        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        $this->assertStringContainsString('application/xml', $response->headers->get('Content-Type'));

        $xml = $response->getContent();

        $this->assertStringContainsString(route('home'), $xml);
        $this->assertStringContainsString(route('locations.index'), $xml);
        $this->assertStringContainsString($this->areaUrl('cianjur'), $xml);
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
