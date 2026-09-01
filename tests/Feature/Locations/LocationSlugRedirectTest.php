<?php

namespace Tests\Feature\Locations;

use App\Models\Location;
use App\Models\LocationArea;
use App\Models\LocationAreaSlugRedirect;
use App\Services\LocationAreaSlugService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kebijakan slug wilayah dan redirect 301.
 *
 * Halaman wilayah dipakai sebagai destination iklan, jadi URL yang pernah
 * terbit tidak boleh mati.
 */
class LocationSlugRedirectTest extends TestCase
{
    use RefreshDatabase;

    protected function slugService(): LocationAreaSlugService
    {
        return app(LocationAreaSlugService::class);
    }

    protected function publishedArea(string $slug): LocationArea
    {
        $area = LocationArea::factory()->published()->create(['slug' => $slug]);
        Location::factory()->for($area, 'area')->published()->create();

        return $area->fresh();
    }

    // ------------------------------------------------------- canonical 200

    public function test_a_published_area_is_reachable_at_its_slug(): void
    {
        $this->publishedArea('cianjur');

        $this->get('/lokasi/cianjur')->assertOk();
    }

    // ---------------------------------------------------------- redirect

    public function test_the_old_slug_redirects_permanently(): void
    {
        $area = $this->publishedArea('cianjur');

        $this->slugService()->apply($area, 'cianjur-kota');

        $this->get('/lokasi/cianjur')
            ->assertStatus(301)
            ->assertRedirect(route('locations.area', 'cianjur-kota'));

        $this->get('/lokasi/cianjur-kota')->assertOk();
    }

    public function test_repeated_slug_changes_all_point_at_the_newest_canonical(): void
    {
        $area = $this->publishedArea('slug-a');

        $this->slugService()->apply($area, 'slug-b');
        $this->slugService()->apply($area->fresh(), 'slug-c');

        // Kedua slug lama menuju canonical terbaru dalam SATU lompatan.
        foreach (['slug-a', 'slug-b'] as $old) {
            $this->get("/lokasi/{$old}")
                ->assertStatus(301)
                ->assertRedirect(route('locations.area', 'slug-c'));
        }

        $this->get('/lokasi/slug-c')->assertOk();
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

        $this->get('/lokasi/slug-a')->assertOk();

        $this->get('/lokasi/slug-b')
            ->assertStatus(301)
            ->assertRedirect(route('locations.area', 'slug-a'));
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

        $this->get('/lokasi/cianjur')->assertNotFound();
        $this->get('/lokasi/cianjur-kota')->assertNotFound();
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

        $second = LocationArea::factory()->create(['slug' => 'wilayah-lain']);

        $this->assertFalse(
            $this->slugService()->isAvailable('slug-lama', $second->getKey()),
            'Slug lama milik wilayah lain tidak boleh diambil alih.'
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

        $content = $this->get('/lokasi/cianjur?utm_source=meta&utm_medium=cpc&utm_campaign=lokasi&gclid=abc&fbclid=xyz&filter=Cipanas')
            ->assertOk()
            ->getContent();

        preg_match('#<link rel="canonical" href="([^"]+)"#', $content, $matches);

        $this->assertSame(route('locations.area', 'cianjur'), $matches[1] ?? null);

        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'gclid', 'fbclid', 'filter='] as $needle) {
            $this->assertStringNotContainsString($needle, $matches[1] ?? '');
        }
    }

    public function test_ad_parameters_do_not_change_the_rendered_content(): void
    {
        $this->publishedArea('cianjur');

        $plain = $this->contentRegion($this->get('/lokasi/cianjur')->getContent());
        $tagged = $this->contentRegion($this->get('/lokasi/cianjur?utm_source=meta&gclid=abc')->getContent());

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
