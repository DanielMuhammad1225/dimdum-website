<?php

namespace Tests\Feature\Bio;

use App\Enums\BioLocationMode;
use App\Models\Location;
use App\Models\LocationGroup;
use App\Models\LocationPage;
use App\Services\BioCatalogService;
use App\Services\LocationPageCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Bio\Concerns\BuildsBio;
use Tests\TestCase;

/**
 * /bio/lokasi pada kedua mode sumber data.
 */
class BioLocationsTest extends TestCase
{
    use BuildsBio;
    use RefreshDatabase;

    protected function pagesMode(): void
    {
        $this->bio(['location_mode' => BioLocationMode::LocationPages->value]);
    }

    /**
     * @param  list<LocationGroup>  $groups
     * @param  array<string, mixed>  $attributes
     */
    protected function page(array $groups, array $attributes = []): LocationPage
    {
        $page = LocationPage::factory()->onBio()->create($attributes);
        $page->groups()->attach(collect($groups)->map->getKey()->all());

        return $page;
    }

    // ------------------------------------------------ mode seluruh gerobak

    public function test_the_all_locations_mode_lists_every_visible_cart(): void
    {
        $this->bio();

        $this->chain(location: [
            'name' => 'Gerobak Alun-Alun',
            'full_address' => 'Jalan Siliwangi 12',
            'landmark' => 'Depan masjid agung',
            'operational_hours_text' => 'Setiap hari 10.00-21.00',
            'latitude' => -6.8168,
            'longitude' => 107.1425,
        ]);

        $this->get(route('bio.locations'))
            ->assertOk()
            ->assertSee('Gerobak Alun-Alun', false)
            ->assertSee('Jalan Siliwangi 12', false)
            ->assertSee('Depan masjid agung', false)
            ->assertSee('Setiap hari 10.00-21.00', false)
            ->assertSee('Buka di Maps', false)
            ->assertSee('rel="noopener noreferrer"', false);
    }

    /**
     * Gerobak tampil hanya bila dirinya DAN seluruh induknya aktif serta
     * tidak terhapus -- satu per satu tingkat.
     */
    public function test_every_level_of_the_hierarchy_controls_visibility(): void
    {
        $this->bio();

        $visible = $this->chain('Prov Tampil', 'Grup Tampil', 'Area Tampil', ['name' => 'Gerobak Tampil']);

        $inactiveCart = $this->chain('Prov A', 'Grup A', 'Area A', ['name' => 'Gerobak Nonaktif', 'is_active' => false]);

        $inactiveArea = $this->chain('Prov B', 'Grup B', 'Area B', ['name' => 'Gerobak Area Mati']);
        $inactiveArea['area']->update(['is_active' => false]);

        $inactiveGroup = $this->chain('Prov C', 'Grup C', 'Area C', ['name' => 'Gerobak Grup Mati']);
        $inactiveGroup['group']->update(['is_active' => false]);

        $inactiveProvince = $this->chain('Prov D', 'Grup D', 'Area D', ['name' => 'Gerobak Provinsi Mati']);
        $inactiveProvince['province']->update(['is_active' => false]);

        $deletedCart = $this->chain('Prov E', 'Grup E', 'Area E', ['name' => 'Gerobak Terhapus']);
        $deletedCart['location']->delete();

        $deletedArea = $this->chain('Prov F', 'Grup F', 'Area F', ['name' => 'Gerobak Area Terhapus']);
        $deletedArea['location']->delete();
        $deletedArea['area']->delete();

        $this->get(route('bio.locations'))
            ->assertOk()
            ->assertSee('Gerobak Tampil', false)
            ->assertDontSee('Gerobak Nonaktif', false)
            ->assertDontSee('Gerobak Area Mati', false)
            ->assertDontSee('Gerobak Grup Mati', false)
            ->assertDontSee('Gerobak Provinsi Mati', false)
            ->assertDontSee('Gerobak Terhapus', false)
            ->assertDontSee('Gerobak Area Terhapus', false);
    }

    public function test_the_area_is_only_a_label_under_province_and_group_headings(): void
    {
        $this->bio();

        $this->chain('Jawa Barat', 'Cianjur', 'Cianjur Kota', ['name' => 'Gerobak Pasar']);

        $html = $this->get(route('bio.locations'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<h2[^>]*>\s*Jawa Barat\s*</h2>#', $html);
        $this->assertMatchesRegularExpression('#<h3[^>]*>\s*Cianjur\s*</h3>#', $html);
        $this->assertStringContainsString('Cianjur Kota', $html);
        // Area tidak pernah menjadi pilihan filter.
        $this->assertStringNotContainsString('name="area"', $html);
    }

    public function test_the_maps_button_is_absent_without_maps_data(): void
    {
        $this->bio();

        $this->chain(location: [
            'name' => 'Gerobak Tanpa Peta',
            'latitude' => null,
            'longitude' => null,
            'google_maps_url' => null,
        ]);

        $this->get(route('bio.locations'))
            ->assertOk()
            ->assertSee('Gerobak Tanpa Peta', false)
            ->assertDontSee('Buka di Maps', false);
    }

    // --------------------------------------------------------------- filter

    public function test_the_province_filter_narrows_the_list(): void
    {
        $this->bio();

        $west = $this->chain('Jawa Barat', 'Cianjur', 'Area Barat', ['name' => 'Gerobak Barat']);
        $this->chain('Jawa Timur', 'Malang', 'Area Timur', ['name' => 'Gerobak Timur']);

        $this->get(route('bio.locations', ['provinsi' => $west['province']->id]))
            ->assertOk()
            ->assertSee('Gerobak Barat', false)
            ->assertDontSee('Gerobak Timur', false)
            ->assertSee('Menampilkan 1 dari 2 gerobak', false);
    }

    public function test_the_group_filter_narrows_the_list(): void
    {
        $this->bio();

        $cianjur = $this->chain('Jawa Barat', 'Cianjur', 'Area 1', ['name' => 'Gerobak Cianjur']);
        $this->chain('Jawa Barat', 'Sukabumi', 'Area 2', ['name' => 'Gerobak Sukabumi']);

        $this->get(route('bio.locations', ['kota' => $cianjur['group']->id]))
            ->assertOk()
            ->assertSee('Gerobak Cianjur', false)
            ->assertDontSee('Gerobak Sukabumi', false);
    }

    /**
     * Pilihan Kota/Grup mengikuti provinsi terpilih, supaya kombinasi yang
     * mustahil tidak pernah ditawarkan.
     */
    public function test_group_options_follow_the_selected_province(): void
    {
        $this->bio();

        $west = $this->chain('Jawa Barat', 'Cianjur', 'Area 1');
        $east = $this->chain('Jawa Timur', 'Malang', 'Area 2');

        $html = $this->get(route('bio.locations', ['provinsi' => $west['province']->id]))->assertOk()->getContent();

        // Hanya isi <select> Kota/Grup yang diperiksa: id provinsi dan id
        // Kota/Grup bisa bernilai sama, dan keduanya muncul sebagai value.
        $groupSelect = $this->selectBlock($html, 'filter-kota');

        $this->assertStringContainsString('value="'.$west['group']->id.'"', $groupSelect);
        $this->assertStringContainsString($west['group']->name, $groupSelect);
        $this->assertStringNotContainsString($east['group']->name, $groupSelect);
    }

    protected function selectBlock(string $html, string $id): string
    {
        $this->assertSame(
            1,
            preg_match('#<select id="'.$id.'".*?</select>#s', $html, $match),
            "Select {$id} tidak ditemukan.",
        );

        return $match[0];
    }

    /**
     * Query string bisa diketik siapa saja. Id asing dan kombinasi mustahil
     * DIABAIKAN, bukan menghasilkan error atau daftar kosong.
     */
    public function test_unknown_or_mismatched_filters_are_ignored(): void
    {
        $this->bio();

        $west = $this->chain('Jawa Barat', 'Cianjur', 'Area 1', ['name' => 'Gerobak Barat']);
        $east = $this->chain('Jawa Timur', 'Malang', 'Area 2', ['name' => 'Gerobak Timur']);

        foreach ([
            ['provinsi' => '999999'],
            ['kota' => 'abc'],
            ['provinsi' => '-1', 'kota' => '1 OR 1=1'],
        ] as $query) {
            $this->get(route('bio.locations', $query))
                ->assertOk()
                ->assertSee('Gerobak Barat', false)
                ->assertSee('Gerobak Timur', false);
        }

        // Kota/Grup milik provinsi lain dibuang; filter provinsi tetap berlaku.
        $this->get(route('bio.locations', ['provinsi' => $west['province']->id, 'kota' => $east['group']->id]))
            ->assertOk()
            ->assertSee('Gerobak Barat', false)
            ->assertDontSee('Gerobak Timur', false);
    }

    /**
     * Filter bekerja tanpa JavaScript: form GET biasa dengan label dan
     * tombol kirim, bukan kontrol yang hanya hidup lewat skrip.
     */
    public function test_the_filter_is_a_plain_labelled_get_form(): void
    {
        $this->bio();
        $this->chain();

        $html = $this->get(route('bio.locations'))->assertOk()->getContent();

        $this->assertStringContainsString('<form method="GET" action="'.route('bio.locations').'"', $html);
        $this->assertStringContainsString('<label for="filter-provinsi"', $html);
        $this->assertStringContainsString('<label for="filter-kota"', $html);
        $this->assertStringContainsString('type="submit"', $html);
        $this->assertStringNotContainsString('onchange', $html);
    }

    /**
     * URL berfilter yang terlanjur dibagikan tetap aman setelah wilayahnya
     * kehilangan seluruh gerobak: filternya tidak lagi ditawarkan, jadi
     * diabaikan, dan pengunjung melihat daftar lengkap -- bukan error, bukan
     * halaman kosong.
     */
    public function test_a_shared_filter_url_for_an_emptied_region_falls_back_to_all(): void
    {
        $this->bio();

        $west = $this->chain('Jawa Barat', 'Cianjur', 'Area 1', ['name' => 'Gerobak Barat']);
        $this->chain('Jawa Timur', 'Malang', 'Area 2', ['name' => 'Gerobak Timur']);

        $sharedUrl = route('bio.locations', ['provinsi' => $west['province']->id]);

        $this->get($sharedUrl)->assertOk()->assertSee('Gerobak Barat', false)->assertDontSee('Gerobak Timur', false);

        $west['location']->update(['is_active' => false]);

        $html = $this->get($sharedUrl)
            ->assertOk()
            ->assertSee('Gerobak Timur', false)
            ->assertDontSee('Gerobak Barat', false)
            ->getContent();

        $this->assertStringNotContainsString('Jawa Barat', $this->selectBlock($html, 'filter-provinsi'));
    }

    public function test_the_page_has_an_empty_state_without_any_cart(): void
    {
        $this->bio();

        $this->get(route('bio.locations'))
            ->assertOk()
            ->assertSee('Lokasi sedang disiapkan', false)
            ->assertDontSee('<form method="GET"', false);
    }

    // ------------------------------------------- mode halaman slug terpilih

    public function test_the_pages_mode_links_each_page_to_its_slug(): void
    {
        $this->pagesMode();

        $chain = $this->chain();
        $page = $this->page([$chain['group']], ['title' => 'Alamat Cianjur', 'slug' => 'alamat-cianjur']);

        $this->get(route('bio.locations'))
            ->assertOk()
            ->assertSee('Alamat Cianjur', false)
            ->assertSee('href="'.route('location-pages.show', $page->slug).'"', false);
    }

    /**
     * Empat syarat: aktif, tidak terhapus, dalam periode, dan show_on_bio.
     */
    public function test_the_pages_mode_obeys_all_four_conditions(): void
    {
        $this->pagesMode();

        $group = $this->chain()['group'];

        $this->page([$group], ['title' => 'Halaman Layak', 'slug' => 'layak']);
        $this->page([$group], ['title' => 'Halaman Nonaktif', 'slug' => 'nonaktif', 'is_active' => false]);
        $this->page([$group], ['title' => 'Halaman Mendatang', 'slug' => 'mendatang', 'starts_at' => now()->addWeek()]);
        $this->page([$group], ['title' => 'Halaman Lewat', 'slug' => 'lewat', 'starts_at' => now()->subMonth(), 'ends_at' => now()->subDay()]);
        $this->page([$group], ['title' => 'Halaman Terhapus', 'slug' => 'terhapus'])->delete();

        $notOnBio = LocationPage::factory()->create(['title' => 'Halaman Bukan Bio', 'slug' => 'bukan-bio']);
        $notOnBio->groups()->attach($group);

        $this->get(route('bio.locations'))
            ->assertOk()
            ->assertSee('Halaman Layak', false)
            ->assertDontSee('Halaman Nonaktif', false)
            ->assertDontSee('Halaman Mendatang', false)
            ->assertDontSee('Halaman Lewat', false)
            ->assertDontSee('Halaman Terhapus', false)
            ->assertDontSee('Halaman Bukan Bio', false);
    }

    /**
     * Saklar Bio dan saklar Homepage berdiri sendiri, ke dua arah.
     */
    public function test_the_bio_toggle_is_independent_from_the_homepage_toggle(): void
    {
        $this->pagesMode();

        $chain = $this->chain(location: ['name' => 'Gerobak Uji Saklar']);

        $bioOnly = LocationPage::factory()->onBio()->create(['title' => 'Hanya Bio', 'slug' => 'hanya-bio', 'is_featured' => false]);
        $homeOnly = LocationPage::factory()->onHomepage()->create(['title' => 'Hanya Homepage', 'slug' => 'hanya-homepage', 'show_on_bio' => false]);

        foreach ([$bioOnly, $homeOnly] as $page) {
            $page->groups()->attach($chain['group']);
            $page->locations()->attach($chain['location']);
        }

        $this->get(route('bio.locations'))
            ->assertOk()
            ->assertSee('Hanya Bio', false)
            ->assertDontSee('Hanya Homepage', false);

        $this->get('/')
            ->assertOk()
            ->assertSee('Hanya Homepage', false)
            ->assertDontSee('Hanya Bio', false);
    }

    /**
     * Halaman yang mencakup dua provinsi cocok untuk filter keduanya, tetapi
     * hanya tampil SEKALI pada daftar "Semua".
     */
    public function test_a_multi_province_page_matches_both_filters_but_appears_once(): void
    {
        $this->pagesMode();

        $west = $this->chain('Jawa Barat', 'Cianjur', 'Area Barat');
        $east = $this->chain('Jawa Timur', 'Malang', 'Area Timur');

        $this->page([$west['group'], $east['group']], ['title' => 'Alamat Lintas Provinsi', 'slug' => 'lintas']);

        $all = $this->get(route('bio.locations'))->assertOk()->getContent();

        // Dihitung per KARTU -- lewat tautannya -- bukan per kemunculan
        // judul: judul juga ditulis ulang sebagai teks pembaca layar pada
        // tautan kartu yang sama.
        $this->assertSame(
            1,
            substr_count($all, 'href="'.route('location-pages.show', 'lintas').'"'),
            'Halaman tampil ganda di daftar Semua.',
        );
        $this->assertSame(1, substr_count($all, '<article'));

        foreach ([$west, $east] as $chain) {
            $this->get(route('bio.locations', ['provinsi' => $chain['province']->id]))
                ->assertOk()
                ->assertSee('Alamat Lintas Provinsi', false);

            $this->get(route('bio.locations', ['kota' => $chain['group']->id]))
                ->assertOk()
                ->assertSee('Alamat Lintas Provinsi', false);
        }
    }

    public function test_the_pages_mode_filters_by_covered_group(): void
    {
        $this->pagesMode();

        $cianjur = $this->chain('Jawa Barat', 'Cianjur', 'Area 1');
        $sukabumi = $this->chain('Jawa Barat', 'Sukabumi', 'Area 2');

        $this->page([$cianjur['group']], ['title' => 'Alamat Cianjur', 'slug' => 'cianjur']);
        $this->page([$sukabumi['group']], ['title' => 'Alamat Sukabumi', 'slug' => 'sukabumi']);

        $this->get(route('bio.locations', ['kota' => $sukabumi['group']->id]))
            ->assertOk()
            ->assertSee('Alamat Sukabumi', false)
            ->assertDontSee('Alamat Cianjur', false);
    }

    /**
     * Mengganti mode mengganti isi, bukan alamat.
     */
    public function test_switching_the_mode_keeps_the_same_url(): void
    {
        $settings = $this->bio();

        $chain = $this->chain(location: ['name' => 'Gerobak Mode Semua']);
        $this->page([$chain['group']], ['title' => 'Halaman Mode Slug', 'slug' => 'mode-slug']);

        $this->get('/bio/lokasi')
            ->assertOk()
            ->assertSee('Gerobak Mode Semua', false)
            ->assertDontSee('Halaman Mode Slug', false);

        $settings->update(['location_mode' => BioLocationMode::LocationPages->value]);

        $this->get('/bio/lokasi')
            ->assertOk()
            ->assertSee('Halaman Mode Slug', false)
            ->assertDontSee('Gerobak Mode Semua', false);
    }

    public function test_an_unknown_stored_mode_falls_back_to_all_carts(): void
    {
        $this->bio();
        DB::table('bio_settings')->update(['location_mode' => 'mode_karangan']);
        BioCatalogService::flushCache();

        $this->chain(location: ['name' => 'Gerobak Cadangan']);

        $this->get(route('bio.locations'))
            ->assertOk()
            ->assertSee('Gerobak Cadangan', false);
    }

    public function test_the_pages_mode_has_an_empty_state(): void
    {
        $this->pagesMode();

        $this->get(route('bio.locations'))
            ->assertOk()
            ->assertSee('Lokasi sedang disiapkan', false);
    }

    // ----------------------------------------------------------------- cache

    /**
     * Perubahan hierarki -- tanpa satu pun kait baru di model hierarki --
     * langsung terlihat di /bio/lokasi.
     */
    public function test_hierarchy_changes_appear_immediately(): void
    {
        $this->bio();

        $chain = $this->chain(location: ['name' => 'Gerobak Lama']);

        $this->get(route('bio.locations'))->assertOk()->assertSee('Gerobak Lama', false);

        $chain['location']->update(['name' => 'Gerobak Baru']);
        $this->get(route('bio.locations'))->assertOk()->assertSee('Gerobak Baru', false)->assertDontSee('Gerobak Lama', false);

        $chain['group']->update(['is_active' => false]);
        $this->get(route('bio.locations'))->assertOk()->assertDontSee('Gerobak Baru', false);

        $chain['group']->update(['is_active' => true]);
        $chain['province']->update(['name' => 'Provinsi Berganti']);
        $this->get(route('bio.locations'))->assertOk()->assertSee('Provinsi Berganti', false);
    }

    public function test_the_page_bio_toggle_takes_effect_immediately(): void
    {
        $this->pagesMode();

        $chain = $this->chain();
        $page = $this->page([$chain['group']], ['title' => 'Alamat Saklar', 'slug' => 'saklar']);

        $this->get(route('bio.locations'))->assertOk()->assertSee('Alamat Saklar', false);

        $page->update(['show_on_bio' => false]);

        $this->get(route('bio.locations'))->assertOk()->assertDontSee('Alamat Saklar', false);
    }

    /**
     * Kunci cache Bio memuat versi cache halaman lokasi -- itulah yang
     * membuat perubahan hierarki tidak perlu kait baru.
     */
    public function test_the_cache_key_tracks_the_location_version(): void
    {
        $this->bio();
        $this->chain(location: ['name' => 'Gerobak Versi']);

        $service = app(BioCatalogService::class);
        $before = $service->locations(BioLocationMode::AllActiveLocations->value);

        Location::query()->update(['name' => 'Ditulis Tanpa Event']);
        $this->assertSame($before, $service->locations(BioLocationMode::AllActiveLocations->value));

        LocationPageCatalogService::flushCache();
        $after = $service->locations(BioLocationMode::AllActiveLocations->value);

        $this->assertSame('Ditulis Tanpa Event', $after['items'][0]['name']);
    }

    // ----------------------------------------------------------------- query

    public function test_the_all_carts_mode_uses_one_query_regardless_of_size(): void
    {
        $this->bio();

        $this->chain('Prov 1', 'Grup 1', 'Area 1');
        $few = $this->locationQueries();

        foreach (range(2, 9) as $i) {
            $this->chain('Prov '.$i, 'Grup '.$i, 'Area '.$i);
        }

        $many = $this->locationQueries();

        $this->assertSame(1, $few, 'Mode seluruh gerobak seharusnya satu query.');
        $this->assertSame($few, $many, 'Query bertambah seiring jumlah gerobak.');
    }

    public function test_the_pages_mode_query_count_does_not_grow_per_page(): void
    {
        $this->pagesMode();

        $group = $this->chain('Prov 1', 'Grup 1', 'Area 1')['group'];
        $this->page([$group], ['slug' => 'satu']);
        $few = $this->locationQueries();

        foreach (range(2, 8) as $i) {
            $this->page([$this->chain('Prov '.$i, 'Grup '.$i, 'Area '.$i)['group']], ['slug' => 'halaman-'.$i]);
        }

        $many = $this->locationQueries();

        $this->assertSame($few, $many, 'Query bertambah seiring jumlah halaman.');
        $this->assertLessThanOrEqual(3, $few);
    }

    /**
     * Jumlah query yang menyentuh tabel lokasi saat /bio/lokasi dirender
     * dengan cache dingin.
     */
    protected function locationQueries(): int
    {
        LocationPageCatalogService::flushCache();

        $queries = [];

        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->get(route('bio.locations'))->assertOk();

        return count(array_filter(
            $queries,
            fn (string $sql): bool => str_contains($sql, 'locations')
                || str_contains($sql, 'location_pages')
                || str_contains($sql, 'location_groups')
                || str_contains($sql, 'provinces'),
        ));
    }
}
