<?php

namespace Tests\Feature\Locations;

use App\Models\Location;
use App\Models\LocationArea;
use App\Models\LocationAreaSlugRedirect;
use App\Models\LocationGroup;
use App\Models\LocationProvinceSlugRedirect;
use App\Models\Province;
use App\Services\LocationAreaSlugService;
use App\Services\LocationProvinceSlugService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kebijakan slug provinsi + area dan redirect 301.
 *
 * Halaman area dipakai sebagai destination iklan, jadi URL yang pernah terbit
 * tidak boleh mati -- termasuk ketika segmen provinsinya yang berganti.
 */
class LocationSlugRedirectTest extends TestCase
{
    use RefreshDatabase;

    protected function slugService(): LocationAreaSlugService
    {
        return app(LocationAreaSlugService::class);
    }

    protected function provinceSlugService(): LocationProvinceSlugService
    {
        return app(LocationProvinceSlugService::class);
    }

    /**
     * Provinsi tetap dengan slug yang dapat ditebak, supaya URL dua segmen di
     * seluruh test ini deterministik.
     */
    protected function province(): Province
    {
        return Province::query()->firstWhere('slug', 'jawa-barat')
            ?? Province::factory()->published()->create(['name' => 'Jawa Barat', 'slug' => 'jawa-barat']);
    }

    protected function publishedArea(string $slug): LocationArea
    {
        $group = LocationGroup::factory()->for($this->province(), 'province')->create();

        $area = LocationArea::factory()->for($group, 'group')->published()->create(['slug' => $slug]);
        Location::factory()->for($area, 'area')->published()->create();

        return $area->fresh();
    }

    /** URL publik dua segmen di bawah provinsi tetap di atas. */
    protected function url(string $areaSlug, string $provinceSlug = 'jawa-barat'): string
    {
        return route('locations.area', [$provinceSlug, $areaSlug]);
    }

    // ------------------------------------------------------- canonical 200

    public function test_a_published_area_is_reachable_at_its_slug(): void
    {
        $this->publishedArea('cianjur');

        $this->get($this->url('cianjur'))->assertOk();
    }

    // ---------------------------------------------------------- redirect

    public function test_the_old_slug_redirects_permanently(): void
    {
        $area = $this->publishedArea('cianjur');

        $this->slugService()->apply($area, 'cianjur-kota');

        $this->get($this->url('cianjur'))
            ->assertStatus(301)
            ->assertRedirect($this->url('cianjur-kota'));

        $this->get($this->url('cianjur-kota'))->assertOk();
    }

    public function test_repeated_slug_changes_all_point_at_the_newest_canonical(): void
    {
        $area = $this->publishedArea('slug-a');

        $this->slugService()->apply($area, 'slug-b');
        $this->slugService()->apply($area->fresh(), 'slug-c');

        // Kedua slug lama menuju canonical terbaru dalam SATU lompatan.
        foreach (['slug-a', 'slug-b'] as $old) {
            $this->get($this->url($old))
                ->assertStatus(301)
                ->assertRedirect($this->url('slug-c'));
        }

        $this->get($this->url('slug-c'))->assertOk();
    }

    public function test_redirects_never_form_a_loop(): void
    {
        $area = $this->publishedArea('slug-a');

        $this->slugService()->apply($area, 'slug-b');
        $this->slugService()->apply($area->fresh(), 'slug-a');

        // Kembali ke slug semula: barisan redirect lama dibuang supaya
        // canonical tidak sekaligus menjadi redirect.
        $this->assertFalse(
            LocationAreaSlugRedirect::query()->where('old_slug', 'slug-a')->exists(),
            'Slug canonical tidak boleh tercatat sebagai redirect.'
        );

        $this->get($this->url('slug-a'))->assertOk();

        $this->get($this->url('slug-b'))
            ->assertStatus(301)
            ->assertRedirect($this->url('slug-a'));
    }

    public function test_a_draft_area_records_no_redirect_when_its_slug_changes(): void
    {
        $area = LocationArea::factory()->create(['slug' => 'draft-lama']);

        $this->slugService()->apply($area, 'draft-baru');

        // Belum pernah terbit -> tidak ada URL publik yang perlu diselamatkan.
        $this->assertSame(0, LocationAreaSlugRedirect::query()->count());
    }

    public function test_an_old_slug_of_a_hidden_area_returns_404(): void
    {
        $area = $this->publishedArea('cianjur');
        $this->slugService()->apply($area, 'cianjur-kota');

        $area->fresh()->update(['is_active' => false]);

        $this->get($this->url('cianjur'))->assertNotFound();
        $this->get($this->url('cianjur-kota'))->assertNotFound();
    }

    // ------------------------------------------- kombinasi provinsi + area

    public function test_an_old_province_slug_with_the_current_area_redirects(): void
    {
        $this->publishedArea('cianjur');

        $this->provinceSlugService()->apply($this->province()->fresh(), 'jabar');

        $this->get($this->url('cianjur', 'jawa-barat'))
            ->assertStatus(301)
            ->assertRedirect($this->url('cianjur', 'jabar'));

        $this->get($this->url('cianjur', 'jabar'))->assertOk();
    }

    public function test_the_current_province_with_an_old_area_slug_redirects(): void
    {
        $area = $this->publishedArea('cianjur');

        $this->slugService()->apply($area, 'cianjur-kota');

        $this->get($this->url('cianjur'))
            ->assertStatus(301)
            ->assertRedirect($this->url('cianjur-kota'));
    }

    public function test_both_segments_outdated_resolve_in_a_single_hop(): void
    {
        $area = $this->publishedArea('cianjur');

        $this->slugService()->apply($area, 'cianjur-kota');
        $this->provinceSlugService()->apply($this->province()->fresh(), 'jabar');

        // Satu lompatan langsung ke canonical terbaru, bukan berantai.
        $this->get($this->url('cianjur', 'jawa-barat'))
            ->assertStatus(301)
            ->assertRedirect($this->url('cianjur-kota', 'jabar'));

        $this->get($this->url('cianjur-kota', 'jabar'))->assertOk();
    }

    public function test_a_mismatched_province_and_area_combination_is_404(): void
    {
        $this->publishedArea('cianjur');

        // Provinsi lain yang benar-benar ada, tetapi bukan induk area ini.
        $other = Province::factory()->published()->create(['slug' => 'banten', 'name' => 'Banten']);
        $otherGroup = LocationGroup::factory()->for($other, 'province')->create();
        $otherArea = LocationArea::factory()->for($otherGroup, 'group')->published()->create(['slug' => 'serang']);
        Location::factory()->for($otherArea, 'area')->published()->create();

        // Kombinasi karangan tidak boleh menghasilkan halaman duplikat.
        $this->get($this->url('cianjur', 'banten'))->assertNotFound();
        $this->get($this->url('serang'))->assertNotFound();
        $this->get($this->url('cianjur', 'provinsi-tidak-ada'))->assertNotFound();
    }

    public function test_a_province_page_redirects_from_its_old_slug(): void
    {
        $this->publishedArea('cianjur');

        $this->provinceSlugService()->apply($this->province()->fresh(), 'jabar');

        $this->get(route('locations.province', 'jawa-barat'))
            ->assertStatus(301)
            ->assertRedirect(route('locations.province', 'jabar'));

        $this->get(route('locations.province', 'jabar'))->assertOk();
    }

    public function test_repeated_province_slug_changes_never_chain(): void
    {
        $this->publishedArea('cianjur');

        $this->provinceSlugService()->apply($this->province()->fresh(), 'jabar');
        $this->provinceSlugService()->apply(Province::query()->firstWhere('slug', 'jabar'), 'jawa-barat-baru');

        foreach (['jawa-barat', 'jabar'] as $old) {
            $this->get(route('locations.province', $old))
                ->assertStatus(301)
                ->assertRedirect(route('locations.province', 'jawa-barat-baru'));
        }
    }

    public function test_a_draft_province_records_no_redirect_when_its_slug_changes(): void
    {
        $province = Province::factory()->create(['slug' => 'draft-lama']);

        $this->provinceSlugService()->apply($province, 'draft-baru');

        $this->assertSame(0, LocationProvinceSlugRedirect::query()->count());
    }

    // --------------------------------------------------------- collision

    public function test_a_slug_used_by_another_area_is_rejected(): void
    {
        LocationArea::factory()->create(['slug' => 'karawang']);
        $area = LocationArea::factory()->create(['slug' => 'cianjur']);

        $this->assertFalse($this->slugService()->isAvailable('karawang', $area->getKey()));

        $this->expectException(\RuntimeException::class);
        $this->slugService()->apply($area, 'karawang');
    }

    public function test_a_slug_belonging_to_a_soft_deleted_area_is_rejected(): void
    {
        $deleted = LocationArea::factory()->create(['slug' => 'kuningan']);
        $deleted->delete();

        $area = LocationArea::factory()->create(['slug' => 'cianjur']);

        // Kalau dipakai ulang, restore data lama akan menabrak unique index.
        $this->assertFalse($this->slugService()->isAvailable('kuningan', $area->getKey()));
    }

    public function test_a_slug_registered_as_another_areas_old_slug_is_rejected(): void
    {
        $first = $this->publishedArea('slug-lama');
        $this->slugService()->apply($first, 'slug-baru');

        $second = LocationArea::factory()->create(['slug' => 'area-lain']);

        $this->assertFalse(
            $this->slugService()->isAvailable('slug-lama', $second->getKey()),
            'Slug lama milik area lain tidak boleh diambil alih.'
        );
    }

    public function test_an_area_may_return_to_its_own_former_slug(): void
    {
        $area = $this->publishedArea('slug-a');
        $this->slugService()->apply($area, 'slug-b');

        // Slug lama milik DIRINYA SENDIRI boleh dipakai lagi.
        $this->assertTrue($this->slugService()->isAvailable('slug-a', $area->getKey()));
    }

    public function test_an_empty_slug_is_never_available(): void
    {
        $this->assertFalse($this->slugService()->isAvailable(''));
    }

    // ------------------------------------------------------------- canonical

    public function test_ad_parameters_never_enter_the_canonical_url(): void
    {
        $this->publishedArea('cianjur');

        $content = $this->get($this->url('cianjur').'?utm_source=meta&utm_medium=cpc&utm_campaign=lokasi&gclid=abc&fbclid=xyz&filter=Cipanas')
            ->assertOk()
            ->getContent();

        preg_match('#<link rel="canonical" href="([^"]+)"#', $content, $matches);

        $this->assertSame($this->url('cianjur'), $matches[1] ?? null);

        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'gclid', 'fbclid', 'filter='] as $needle) {
            $this->assertStringNotContainsString($needle, $matches[1] ?? '');
        }
    }

    public function test_ad_parameters_do_not_change_the_rendered_content(): void
    {
        $this->publishedArea('cianjur');

        $plain = $this->contentRegion($this->get($this->url('cianjur'))->getContent());
        $tagged = $this->contentRegion($this->get($this->url('cianjur').'?utm_source=meta&gclid=abc')->getContent());

        $this->assertSame($plain, $tagged, 'Parameter iklan tidak boleh mengubah isi halaman.');
    }

    /**
     * Bagian halaman yang ditentukan konten: <main>, judul, deskripsi, dan
     * canonical.
     *
     * Blok preload font dari Vite dikecualikan karena isinya bergantung pada
     * manifest build, bukan pada request.
     */
    protected function contentRegion(string $html): string
    {
        preg_match('#<main\b.*</main>#s', $html, $main);
        preg_match('#<title>.*</title>#s', $html, $title);
        preg_match('#<link rel="canonical"[^>]*>#', $html, $canonical);
        preg_match('#<meta name="description"[^>]*>#', $html, $description);

        return implode("\n", [
            $title[0] ?? '',
            $description[0] ?? '',
            $canonical[0] ?? '',
            $main[0] ?? '',
        ]);
    }

    // -------------------------------------------------------------- slug gerobak

    public function test_location_slugs_are_made_unique_within_their_area(): void
    {
        $area = LocationArea::factory()->create();

        Location::factory()->for($area, 'area')->create(['slug' => 'pasar-baru']);

        $this->assertSame(
            'pasar-baru-2',
            $this->slugService()->uniqueLocationSlug($area->getKey(), 'Pasar Baru'),
        );
    }

    public function test_location_slugs_may_repeat_across_areas(): void
    {
        $first = LocationArea::factory()->create();
        $second = LocationArea::factory()->create();

        Location::factory()->for($first, 'area')->create(['slug' => 'pasar-baru']);

        $this->assertSame(
            'pasar-baru',
            $this->slugService()->uniqueLocationSlug($second->getKey(), 'Pasar Baru'),
        );
    }
}
