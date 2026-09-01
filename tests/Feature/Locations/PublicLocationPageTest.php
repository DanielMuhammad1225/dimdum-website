<?php

namespace Tests\Feature\Locations;

use App\Models\Location;
use App\Models\LocationArea;
use App\Models\LocationGroup;
use App\Models\LocationPage;
use App\Models\LocationPageSlugRedirect;
use App\Models\Province;
use App\Services\LocationPageSlugService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Halaman publik /alamat/{slug}.
 *
 * Yang dijaga: hanya gerobak yang DIPILIH admin dan masih di dalam cakupan
 * yang tampil; slug lama tetap hidup lewat satu lompatan 301; dan halaman
 * yang belum layak tampil tetap 404 -- bukan dialihkan ke halaman mati.
 */
class PublicLocationPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Satu halaman terbit lengkap dengan cakupan dan isinya.
     *
     * @param  list<Location>  $locations
     */
    protected function publishedPage(array $groups, array $locations, array $attributes = []): LocationPage
    {
        $page = LocationPage::factory()->published()->create($attributes);

        $page->groups()->attach(array_map(fn (LocationGroup $g): int => $g->getKey(), $groups));
        $page->locations()->attach(array_map(fn (Location $l): int => $l->getKey(), $locations));

        return $page->fresh();
    }

    protected function url(LocationPage $page): string
    {
        return route('location-pages.show', $page->slug);
    }

    // ------------------------------------------------------------ rendering

    public function test_a_published_page_renders(): void
    {
        $group = LocationGroup::factory()->create();
        $location = Location::factory()
            ->for(LocationArea::factory()->for($group, 'group'), 'area')
            ->create(['name' => 'Gerobak Pasar', 'full_address' => 'Jalan Uji Nomor 7']);

        $page = $this->publishedPage([$group], [$location], [
            'title' => 'Alamat Gerobak Cianjur',
            'short_description' => 'Titik gerobak yang sedang beroperasi.',
        ]);

        $this->get($this->url($page))
            ->assertOk()
            ->assertSee('Alamat Gerobak Cianjur')
            ->assertSee('Titik gerobak yang sedang beroperasi.')
            ->assertSee('Gerobak Pasar')
            ->assertSee('Jalan Uji Nomor 7');
    }

    public function test_an_unknown_slug_is_not_found(): void
    {
        $this->get('/alamat/tidak-ada')->assertNotFound();
    }

    public function test_a_draft_page_is_not_found(): void
    {
        $page = LocationPage::factory()->create();

        $this->get($this->url($page))->assertNotFound();
    }

    public function test_a_scheduled_page_is_not_found_until_its_time(): void
    {
        $page = LocationPage::factory()->scheduled()->create();

        $this->get($this->url($page))->assertNotFound();
    }

    public function test_an_inactive_page_is_not_found(): void
    {
        $page = LocationPage::factory()->inactive()->create();

        $this->get($this->url($page))->assertNotFound();
    }

    public function test_a_page_outside_its_period_is_not_found(): void
    {
        $page = LocationPage::factory()->expired()->create();

        $this->get($this->url($page))->assertNotFound();
    }

    // ------------------------------------------------------- isi halaman

    public function test_only_the_chosen_locations_appear(): void
    {
        $group = LocationGroup::factory()->create();
        $area = LocationArea::factory()->for($group, 'group')->create();

        $chosen = Location::factory()->for($area, 'area')->create(['name' => 'Gerobak Dipilih']);
        $ignored = Location::factory()->for($area, 'area')->create(['name' => 'Gerobak Diabaikan']);

        $page = $this->publishedPage([$group], [$chosen]);

        $this->get($this->url($page))
            ->assertOk()
            ->assertSee('Gerobak Dipilih')
            ->assertDontSee('Gerobak Diabaikan');
    }

    public function test_locations_from_different_areas_appear_when_their_group_is_covered(): void
    {
        $group = LocationGroup::factory()->create();

        $areaOne = LocationArea::factory()->for($group, 'group')->create(['name' => 'Area Utara']);
        $areaTwo = LocationArea::factory()->for($group, 'group')->create(['name' => 'Area Selatan']);

        $first = Location::factory()->for($areaOne, 'area')->create(['name' => 'Gerobak Utara']);
        $second = Location::factory()->for($areaTwo, 'area')->create(['name' => 'Gerobak Selatan']);

        $page = $this->publishedPage([$group], [$first, $second]);

        $this->get($this->url($page))
            ->assertOk()
            ->assertSee('Gerobak Utara')
            ->assertSee('Gerobak Selatan')
            // Area tampil sebagai konteks pengelompokan.
            ->assertSee('Area Utara')
            ->assertSee('Area Selatan');
    }

    /**
     * Gerobak yang keluar dari cakupan berhenti tampil, meskipun barisnya
     * masih terpasang di pivot.
     */
    public function test_a_location_outside_the_scope_never_renders(): void
    {
        $group = LocationGroup::factory()->create();
        $location = Location::factory()
            ->for(LocationArea::factory()->for($group, 'group'), 'area')
            ->create(['name' => 'Gerobak Pindah']);

        $page = $this->publishedPage([$group], [$location]);

        $this->get($this->url($page))->assertSee('Gerobak Pindah');

        // Dipindahkan langsung di database, melewati validasi form.
        $elsewhere = LocationArea::factory()->create();
        DB::table('locations')->where('id', $location->getKey())
            ->update(['location_area_id' => $elsewhere->getKey()]);

        $this->get($this->url($page))
            ->assertOk()
            ->assertDontSee('Gerobak Pindah');
    }

    public function test_an_inactive_ancestor_removes_the_location_from_the_page(): void
    {
        $province = Province::factory()->create();
        $group = LocationGroup::factory()->for($province, 'province')->create();
        $area = LocationArea::factory()->for($group, 'group')->create();
        $location = Location::factory()->for($area, 'area')->create(['name' => 'Gerobak Rantai']);

        $page = $this->publishedPage([$group], [$location]);

        foreach ([$province, $group, $area, $location] as $model) {
            $model->forceFill(['is_active' => false])->save();

            $this->get($this->url($page))
                ->assertOk()
                ->assertDontSee('Gerobak Rantai');

            $model->forceFill(['is_active' => true])->save();
        }
    }

    public function test_a_page_without_visible_locations_shows_a_safe_empty_state(): void
    {
        $page = LocationPage::factory()->published()->create();

        $this->get($this->url($page))
            ->assertOk()
            ->assertSee('sedang disiapkan', false)
            // Tidak ada alamat maupun tombol karangan.
            ->assertDontSee('Buka di Google Maps');
    }

    // -------------------------------------------------------------- slug

    public function test_an_old_slug_redirects_once_to_the_canonical(): void
    {
        $group = LocationGroup::factory()->create();
        $location = Location::factory()
            ->for(LocationArea::factory()->for($group, 'group'), 'area')->create();

        $page = $this->publishedPage([$group], [$location], ['slug' => 'alamat-lama']);

        app(LocationPageSlugService::class)->apply($page, 'alamat-baru');

        $this->assertTrue(
            LocationPageSlugRedirect::query()->where('old_slug', 'alamat-lama')->exists(),
            'Slug lama harus tercatat sebagai redirect.',
        );

        $this->get('/alamat/alamat-lama')
            ->assertStatus(301)
            ->assertRedirect(route('location-pages.show', 'alamat-baru'));

        $this->get('/alamat/alamat-baru')->assertOk();
    }

    public function test_repeated_slug_changes_still_take_a_single_hop(): void
    {
        $page = LocationPage::factory()->published()->create(['slug' => 'satu']);
        $service = app(LocationPageSlugService::class);

        $service->apply($page, 'dua');
        $service->apply($page->fresh(), 'tiga');

        // Kedua slug lama menunjuk langsung ke canonical terbaru.
        foreach (['satu', 'dua'] as $old) {
            $this->get('/alamat/'.$old)
                ->assertStatus(301)
                ->assertRedirect(route('location-pages.show', 'tiga'));
        }
    }

    public function test_returning_to_an_old_slug_removes_its_redirect(): void
    {
        $page = LocationPage::factory()->published()->create(['slug' => 'awal']);
        $service = app(LocationPageSlugService::class);

        $service->apply($page, 'baru');
        $service->apply($page->fresh(), 'awal');

        $this->assertFalse(
            LocationPageSlugRedirect::query()->where('old_slug', 'awal')->exists(),
            'Slug canonical tidak boleh sekaligus menjadi redirect.',
        );

        $this->get('/alamat/awal')->assertOk();
    }

    public function test_an_old_slug_of_a_draft_page_is_not_found_instead_of_redirected(): void
    {
        $page = LocationPage::factory()->published()->create(['slug' => 'lama']);

        app(LocationPageSlugService::class)->apply($page, 'baru');

        $page->fresh()->forceFill(['is_active' => false])->save();

        // Mengalihkan ke halaman mati lebih buruk daripada 404 yang jujur.
        $this->get('/alamat/lama')->assertNotFound();
    }

    public function test_a_slug_taken_by_another_page_is_refused(): void
    {
        LocationPage::factory()->create(['slug' => 'dipakai']);

        $this->assertFalse(app(LocationPageSlugService::class)->isAvailable('dipakai'));
    }

    public function test_a_slug_belonging_to_a_soft_deleted_page_is_refused(): void
    {
        $page = LocationPage::factory()->create(['slug' => 'terhapus']);
        $page->delete();

        $this->assertFalse(app(LocationPageSlugService::class)->isAvailable('terhapus'));
    }

    public function test_a_unique_slug_gets_a_numeric_suffix(): void
    {
        LocationPage::factory()->create(['slug' => 'alamat-cianjur']);

        $this->assertSame(
            'alamat-cianjur-2',
            app(LocationPageSlugService::class)->uniqueSlug('Alamat Cianjur'),
        );
    }

    // --------------------------------------------------------------- SEO

    public function test_the_page_declares_canonical_title_and_structured_data(): void
    {
        $group = LocationGroup::factory()->create();
        $location = Location::factory()
            ->for(LocationArea::factory()->for($group, 'group'), 'area')
            ->create(['name' => 'Gerobak SEO']);

        $page = $this->publishedPage([$group], [$location], [
            'title' => 'Alamat Cianjur',
            'seo_description' => 'Deskripsi ringkas untuk mesin pencari.',
        ]);

        $canonical = route('location-pages.show', $page->slug);
        $html = $this->get($this->url($page))->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="'.$canonical.'">', $html);
        $this->assertStringContainsString('Deskripsi ringkas untuk mesin pencari.', $html);
        $this->assertStringContainsString('application/ld+json', $html);
        $this->assertStringContainsString('FoodEstablishment', $html);
        $this->assertStringContainsString('BreadcrumbList', $html);
    }

    public function test_the_page_has_exactly_one_h1(): void
    {
        $page = LocationPage::factory()->published()->create(['title' => 'Alamat Tunggal']);

        $html = $this->get($this->url($page))->getContent();

        $this->assertSame(1, substr_count($html, '<h1'), 'Halaman harus punya tepat satu H1.');
    }

    /**
     * Parameter iklan boleh tetap ada di address bar, tetapi tidak pernah
     * masuk canonical maupun key cache.
     */
    public function test_ad_parameters_never_reach_the_canonical(): void
    {
        $page = LocationPage::factory()->published()->create();
        $canonical = route('location-pages.show', $page->slug);

        $html = $this->get($this->url($page).'?utm_source=meta&utm_medium=cpc&gclid=abc&fbclid=xyz')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="'.$canonical.'">', $html);
        foreach (['utm_source', 'utm_medium', 'gclid', 'fbclid'] as $parameter) {
            $this->assertStringNotContainsString($parameter, $html);
        }
    }

    // ----------------------------------------------------------- sitemap

    public function test_the_sitemap_lists_pages_and_never_the_hierarchy(): void
    {
        $group = LocationGroup::factory()->create();
        $location = Location::factory()
            ->for(LocationArea::factory()->for($group, 'group'), 'area')->create();

        $listed = $this->publishedPage([$group], [$location], ['slug' => 'masuk-sitemap']);
        $draft = LocationPage::factory()->create(['slug' => 'masih-draft']);
        $empty = LocationPage::factory()->published()->create(['slug' => 'tanpa-gerobak']);

        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString(route('location-pages.show', 'masuk-sitemap'), $xml);
        $this->assertStringNotContainsString('masih-draft', $xml);
        // Halaman tanpa gerobak tampil tidak dijanjikan ke mesin pencari.
        $this->assertStringNotContainsString('tanpa-gerobak', $xml);
        $this->assertStringNotContainsString('/lokasi', $xml);
    }

    // ---------------------------------------------------------- homepage

    public function test_the_homepage_links_to_location_pages(): void
    {
        $group = LocationGroup::factory()->create();
        $location = Location::factory()
            ->for(LocationArea::factory()->for($group, 'group'), 'area')->create();

        $page = $this->publishedPage([$group], [$location], ['title' => 'Alamat Homepage']);

        $this->get('/')
            ->assertOk()
            ->assertSee('Alamat Homepage')
            ->assertSee(route('location-pages.show', $page->slug));
    }

    public function test_the_homepage_shows_an_empty_state_without_any_page(): void
    {
        // Empty state berasal dari Homepage CMS, bukan alamat karangan.
        $this->get('/')
            ->assertOk()
            ->assertSee('Informasi lokasi segera hadir')
            ->assertDontSee('Buka di Google Maps');
    }

    public function test_a_page_without_visible_locations_stays_off_the_homepage(): void
    {
        LocationPage::factory()->published()->create(['title' => 'Alamat Kosong']);

        $this->get('/')->assertOk()->assertDontSee('Alamat Kosong');
    }

    public function test_the_page_never_carries_another_brand(): void
    {
        $group = LocationGroup::factory()->create();
        $location = Location::factory()
            ->for(LocationArea::factory()->for($group, 'group'), 'area')->create();

        $page = $this->publishedPage([$group], [$location]);

        $content = $this->get($this->url($page))->assertOk()->getContent();

        $this->assertStringNotContainsStringIgnoringCase('dimsumin', $content);
        $this->assertStringContainsString('DIMDUM', $content);
    }

    // ------------------------------------------------------------ query

    public function test_the_page_does_not_grow_its_query_count_per_location(): void
    {
        $group = LocationGroup::factory()->create();
        $area = LocationArea::factory()->for($group, 'group')->create();

        $locations = Location::factory()->count(2)->for($area, 'area')->create()->all();
        $page = $this->publishedPage([$group], $locations);

        $baseline = $this->countQueries(fn () => $this->get($this->url($page))->assertOk());

        // Cache di-invalidasi supaya request kedua benar-benar query ulang.
        Location::factory()->count(6)->for($area, 'area')->create()
            ->each(fn (Location $l) => $page->locations()->attach($l));

        $larger = $this->countQueries(fn () => $this->get($this->url($page))->assertOk());

        $this->assertSame(
            $baseline,
            $larger,
            "Jumlah query bertambah dari {$baseline} ke {$larger} saat gerobaknya bertambah -- ada N+1.",
        );
    }

    protected function countQueries(callable $callback): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        $callback();

        $count = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $count;
    }
}
