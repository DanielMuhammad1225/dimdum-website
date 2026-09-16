<?php

namespace Tests\Feature\Locations;

use App\Enums\UserRole;
use App\Filament\Resources\LocationPages\Pages\CreateLocationPage;
use App\Filament\Resources\LocationPages\Pages\EditLocationPage;
use App\Filament\Resources\LocationPages\Pages\ListLocationPages;
use App\Models\Location;
use App\Models\LocationArea;
use App\Models\LocationGroup;
use App\Models\LocationPage;
use App\Models\LocationPageSlugRedirect;
use App\Models\User;
use App\Services\LocationPageSlugService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Publikasi Halaman Slug Lokasi setelah jadwal terbit dibuang.
 *
 * Satu saklar menentukan segalanya:
 *
 *     tidak terhapus  DAN  is_active  DAN  masih di dalam periode (bila diisi)
 *
 * Tidak ada lagi draft maupun "belum terbit": halaman aktif LANGSUNG dapat
 * dibuka. starts_at/ends_at tetap ada dan tetap opsional.
 */
class LocationPagePublicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function actingAsSuperAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(UserRole::SuperAdmin->value);
        $this->actingAs($user->fresh());

        return $user;
    }

    /** @return array{0: LocationGroup, 1: Location} */
    protected function groupWithLocation(): array
    {
        $group = LocationGroup::factory()->create();
        $area = LocationArea::factory()->for($group, 'group')->create();
        $location = Location::factory()->for($area, 'area')->create(['name' => 'Gerobak Uji']);

        return [$group, $location];
    }

    protected function pageWithContent(array $attributes = []): LocationPage
    {
        [$group, $location] = $this->groupWithLocation();

        // onHomepage() sejak saklar "Tampilkan di Homepage" menentukan
        // keanggotaan: kelas ini menguji aturan publikasi, bukan saklar itu.
        $page = LocationPage::factory()->onHomepage()->create($attributes);
        $page->groups()->attach($group);
        $page->locations()->attach($location);

        return $page->fresh();
    }

    // -------------------------------------------------------------- schema

    public function test_the_publish_time_column_is_gone(): void
    {
        $this->assertFalse(Schema::hasColumn('location_pages', 'published_at'));
    }

    public function test_the_period_columns_are_kept(): void
    {
        $this->assertTrue(Schema::hasColumn('location_pages', 'starts_at'));
        $this->assertTrue(Schema::hasColumn('location_pages', 'ends_at'));
    }

    // -------------------------------------------------------- route publik

    public function test_an_active_page_is_reachable_straight_away(): void
    {
        $page = $this->pageWithContent(['slug' => 'alamat-cianjur']);

        $url = route('location-pages.show', 'alamat-cianjur');

        $this->get($url)
            ->assertOk()
            ->assertSee('Gerobak Uji')
            ->assertSee('<link rel="canonical" href="'.$url.'">', false);

        $this->assertSame(url('/alamat/alamat-cianjur'), $url);
        $this->assertTrue($page->isPubliclyVisible());
    }

    /**
     * Halaman yang dibuat lewat panel harus langsung terbuka -- tidak ada
     * langkah kedua yang harus diingat admin.
     */
    public function test_a_page_created_in_the_panel_is_reachable_immediately(): void
    {
        $this->actingAsSuperAdmin();
        [$group, $location] = $this->groupWithLocation();

        Livewire::test(CreateLocationPage::class)
            ->fillForm([
                'title' => 'Alamat Baru',
                'slug' => 'alamat-baru',
                'group_ids' => [$group->getKey()],
                'location_ids' => [$location->getKey()],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->get('/alamat/alamat-baru')->assertOk()->assertSee('Gerobak Uji');
    }

    public function test_an_inactive_page_is_not_found(): void
    {
        $page = $this->pageWithContent(['slug' => 'nonaktif']);
        $page->forceFill(['is_active' => false])->save();

        $this->get('/alamat/nonaktif')->assertNotFound();
    }

    public function test_a_page_whose_period_has_not_started_is_not_found(): void
    {
        $page = $this->pageWithContent(['slug' => 'belum-mulai']);
        $page->forceFill(['starts_at' => now()->addWeek()])->save();

        $this->get('/alamat/belum-mulai')->assertNotFound();
    }

    public function test_a_page_whose_period_has_ended_is_not_found(): void
    {
        $page = $this->pageWithContent(['slug' => 'sudah-lewat']);
        $page->forceFill(['starts_at' => now()->subMonth(), 'ends_at' => now()->subDay()])->save();

        $this->get('/alamat/sudah-lewat')->assertNotFound();
    }

    public function test_a_page_inside_its_period_is_reachable(): void
    {
        $page = $this->pageWithContent(['slug' => 'sedang-berlaku']);
        $page->forceFill(['starts_at' => now()->subDay(), 'ends_at' => now()->addWeek()])->save();

        $this->get('/alamat/sedang-berlaku')->assertOk();
    }

    public function test_an_unknown_slug_is_not_found(): void
    {
        $this->get('/alamat/tidak-ada')->assertNotFound();
    }

    public function test_a_soft_deleted_page_is_not_found(): void
    {
        $page = $this->pageWithContent(['slug' => 'terhapus']);
        $page->delete();

        $this->get('/alamat/terhapus')->assertNotFound();
    }

    // ---------------------------------------------------------------- slug

    public function test_an_old_slug_redirects_301_to_the_newest_one(): void
    {
        $page = $this->pageWithContent(['slug' => 'alamat-lama']);

        app(LocationPageSlugService::class)->apply($page, 'alamat-baru');

        $this->assertTrue(
            LocationPageSlugRedirect::query()->where('old_slug', 'alamat-lama')->exists(),
            'Slug lama harus tercatat sebagai redirect meski tidak ada lagi status terbit.',
        );

        $this->get('/alamat/alamat-lama')
            ->assertStatus(301)
            ->assertRedirect(route('location-pages.show', 'alamat-baru'));

        $this->get('/alamat/alamat-baru')->assertOk();
    }

    /**
     * Dulu redirect hanya dicatat bila halaman "pernah terbit". Syarat itu
     * bersandar pada published_at dan kini tidak ada lagi -- setiap
     * penggantian slug pada halaman tersimpan harus mencatat redirect.
     */
    public function test_an_inactive_page_also_records_a_redirect_when_its_slug_changes(): void
    {
        $page = $this->pageWithContent(['slug' => 'pernah-hidup']);
        $page->forceFill(['is_active' => false])->save();

        app(LocationPageSlugService::class)->apply($page->fresh(), 'slug-baru');

        $this->assertTrue(
            LocationPageSlugRedirect::query()->where('old_slug', 'pernah-hidup')->exists(),
        );

        // Halamannya sendiri masih nonaktif, jadi tetap 404 -- bukan redirect
        // menuju halaman mati.
        $this->get('/alamat/pernah-hidup')->assertNotFound();
    }

    public function test_repeated_slug_changes_still_take_a_single_hop(): void
    {
        $page = $this->pageWithContent(['slug' => 'satu']);
        $service = app(LocationPageSlugService::class);

        $service->apply($page, 'dua');
        $service->apply($page->fresh(), 'tiga');

        foreach (['satu', 'dua'] as $old) {
            $this->get('/alamat/'.$old)
                ->assertStatus(301)
                ->assertRedirect(route('location-pages.show', 'tiga'));
        }
    }

    public function test_changing_the_slug_through_the_panel_records_a_redirect(): void
    {
        $this->actingAsSuperAdmin();

        $page = $this->pageWithContent(['slug' => 'slug-awal']);

        Livewire::test(EditLocationPage::class, ['record' => $page->getRouteKey()])
            ->fillForm(['slug' => 'slug-akhir'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('slug-akhir', $page->fresh()->slug);
        $this->get('/alamat/slug-awal')->assertStatus(301);
    }

    // --------------------------------------------------- homepage & sitemap

    public function test_the_homepage_only_lists_visible_pages(): void
    {
        $this->pageWithContent(['title' => 'Halaman Tampil']);

        $inactive = $this->pageWithContent(['title' => 'Halaman Nonaktif']);
        $inactive->forceFill(['is_active' => false])->save();

        $expired = $this->pageWithContent(['title' => 'Halaman Kedaluwarsa']);
        $expired->forceFill(['starts_at' => now()->subMonth(), 'ends_at' => now()->subDay()])->save();

        $upcoming = $this->pageWithContent(['title' => 'Halaman Mendatang']);
        $upcoming->forceFill(['starts_at' => now()->addWeek()])->save();

        $this->get('/')
            ->assertOk()
            ->assertSee('Halaman Tampil')
            ->assertDontSee('Halaman Nonaktif')
            ->assertDontSee('Halaman Kedaluwarsa')
            ->assertDontSee('Halaman Mendatang');
    }

    public function test_the_sitemap_only_lists_visible_pages(): void
    {
        $this->pageWithContent(['slug' => 'masuk-sitemap']);

        $inactive = $this->pageWithContent(['slug' => 'nonaktif-sitemap']);
        $inactive->forceFill(['is_active' => false])->save();

        $expired = $this->pageWithContent(['slug' => 'kedaluwarsa-sitemap']);
        $expired->forceFill(['starts_at' => now()->subMonth(), 'ends_at' => now()->subDay()])->save();

        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString(route('location-pages.show', 'masuk-sitemap'), $xml);
        $this->assertStringNotContainsString('nonaktif-sitemap', $xml);
        $this->assertStringNotContainsString('kedaluwarsa-sitemap', $xml);
    }

    /**
     * Cache versi harus ikut basi begitu saklar aktif berubah, kalau tidak
     * halaman yang baru dinonaktifkan masih tersaji dari cache.
     */
    public function test_deactivating_a_page_takes_effect_immediately(): void
    {
        $page = $this->pageWithContent(['slug' => 'segera-mati']);

        $this->get('/alamat/segera-mati')->assertOk();

        $page->forceFill(['is_active' => false])->save();

        $this->get('/alamat/segera-mati')->assertNotFound();
        $this->get('/sitemap.xml')->assertOk()->assertDontSee('segera-mati');
    }

    // --------------------------------------------------------- status panel

    public function test_the_model_reports_only_the_three_allowed_statuses(): void
    {
        $this->assertSame('AKTIF', LocationPage::factory()->create()->statusLabel());
        $this->assertSame('NONAKTIF', LocationPage::factory()->inactive()->create()->statusLabel());
        $this->assertSame('DI LUAR PERIODE', LocationPage::factory()->upcoming()->create()->statusLabel());
        $this->assertSame('DI LUAR PERIODE', LocationPage::factory()->expired()->create()->statusLabel());
    }

    public function test_the_visibility_issue_never_mentions_a_publish_time(): void
    {
        $this->assertNull(LocationPage::factory()->create()->publicVisibilityIssue());

        foreach ([
            LocationPage::factory()->inactive()->create(),
            LocationPage::factory()->upcoming()->create(),
            LocationPage::factory()->expired()->create(),
        ] as $page) {
            $issue = (string) $page->publicVisibilityIssue();

            $this->assertNotSame('', $issue);
            $this->assertStringNotContainsStringIgnoringCase('waktu terbit', $issue);
            $this->assertStringNotContainsStringIgnoringCase('draft', $issue);
        }
    }

    public function test_the_form_states_the_status_without_draft_wording(): void
    {
        $this->actingAsSuperAdmin();

        $live = $this->pageWithContent(['slug' => 'hidup']);
        $html = Livewire::test(EditLocationPage::class, ['record' => $live->getRouteKey()])->html();

        $this->assertStringContainsString('Status publik', $html);
        $this->assertStringContainsString('AKTIF', $html);
        $this->assertStringNotContainsString('BELUM TERBIT', $html);
        $this->assertStringNotContainsStringIgnoringCase('waktu terbit', $html);
    }

    public function test_the_preview_button_follows_the_new_rule(): void
    {
        $this->actingAsSuperAdmin();

        $live = LocationPage::factory()->create();
        $inactive = LocationPage::factory()->inactive()->create();
        $expired = LocationPage::factory()->expired()->create();

        Livewire::test(EditLocationPage::class, ['record' => $live->getRouteKey()])
            ->assertActionVisible('preview');

        Livewire::test(EditLocationPage::class, ['record' => $inactive->getRouteKey()])
            ->assertActionHidden('preview');

        Livewire::test(ListLocationPages::class)
            ->assertTableActionVisible('preview', $live)
            ->assertTableActionHidden('preview', $inactive)
            ->assertTableActionHidden('preview', $expired);
    }

    // -------------------------------------------------------------- session

    public function test_saving_a_page_keeps_the_admin_signed_in(): void
    {
        $user = $this->actingAsSuperAdmin();
        $page = $this->pageWithContent();

        Livewire::test(EditLocationPage::class, ['record' => $page->getRouteKey()])
            ->fillForm(['title' => 'Judul Diperbarui', 'is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(auth()->check(), 'Admin logout setelah menyimpan halaman.');
        $this->assertSame($user->id, auth()->id());
        $this->assertFalse($page->fresh()->isPubliclyVisible());
    }
}
