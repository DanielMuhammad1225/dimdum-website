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
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Kenapa /alamat/{slug} menghasilkan 404, dan bagaimana admin mengetahuinya.
 *
 * Aturan visibility TIDAK diubah: halaman tetap harus aktif, sudah terbit,
 * dan berada dalam periodenya. Yang diperbaiki adalah panelnya -- menyalakan
 * "Aktif" tanpa mengisi "Waktu terbit" dulu menghasilkan halaman yang terasa
 * selesai padahal URL-nya 404, tanpa satu pun petunjuk di layar.
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

    // ------------------------------------------------- perilaku route publik

    public function test_a_published_page_returns_200_with_the_right_canonical(): void
    {
        [$group, $location] = $this->groupWithLocation();

        $page = LocationPage::factory()->published()->create(['slug' => 'alamat-cianjur']);
        $page->groups()->attach($group);
        $page->locations()->attach($location);

        $url = route('location-pages.show', 'alamat-cianjur');

        $this->get($url)
            ->assertOk()
            ->assertSee('Gerobak Uji')
            ->assertSee('<link rel="canonical" href="'.$url.'">', false);

        $this->assertSame(url('/alamat/alamat-cianjur'), $url);
    }

    public function test_an_unknown_slug_returns_404(): void
    {
        $this->get('/alamat/slug-yang-tidak-ada')->assertNotFound();
    }

    /**
     * Kondisi PERSIS seperti data yang dilaporkan: aktif, tetapi waktu
     * terbitnya tidak pernah diisi.
     */
    public function test_an_active_page_without_a_publish_time_still_returns_404(): void
    {
        [$group, $location] = $this->groupWithLocation();

        $page = LocationPage::factory()->create(['slug' => 'masih-draft', 'is_active' => true]);
        $page->groups()->attach($group);
        $page->locations()->attach($location);

        $this->assertNull($page->published_at);
        $this->get('/alamat/masih-draft')->assertNotFound();
    }

    public function test_an_inactive_page_returns_404(): void
    {
        $page = LocationPage::factory()->inactive()->create(['slug' => 'nonaktif']);

        $this->get('/alamat/nonaktif')->assertNotFound();
    }

    public function test_publishing_the_page_turns_the_same_url_into_200(): void
    {
        [$group, $location] = $this->groupWithLocation();

        $page = LocationPage::factory()->create(['slug' => 'akan-terbit', 'is_active' => true]);
        $page->groups()->attach($group);
        $page->locations()->attach($location);

        $this->get('/alamat/akan-terbit')->assertNotFound();

        // Satu-satunya yang berubah: waktu terbit terisi.
        $page->forceFill(['published_at' => now()->subMinute()])->save();

        $this->get('/alamat/akan-terbit')->assertOk()->assertSee('Gerobak Uji');
    }

    // ------------------------------------------------------ alasan yang jelas

    public function test_the_model_names_the_reason_a_page_is_not_reachable(): void
    {
        $draft = LocationPage::factory()->create(['is_active' => true]);
        $this->assertStringContainsString('DRAFT', (string) $draft->publicVisibilityIssue());
        $this->assertStringContainsString('Waktu terbit', (string) $draft->publicVisibilityIssue());

        $inactive = LocationPage::factory()->inactive()->create();
        $this->assertStringContainsString('NONAKTIF', (string) $inactive->publicVisibilityIssue());

        $scheduled = LocationPage::factory()->scheduled()->create();
        $this->assertStringContainsString('DIJADWALKAN', (string) $scheduled->publicVisibilityIssue());

        $expired = LocationPage::factory()->expired()->create();
        $this->assertStringContainsString('PERIODE', (string) $expired->publicVisibilityIssue());

        $live = LocationPage::factory()->published()->create();
        $this->assertNull($live->publicVisibilityIssue(), 'Halaman terbit tidak boleh punya keluhan.');
    }

    // ------------------------------------------------------ form tidak menipu

    public function test_the_active_toggle_never_promises_the_page_is_reachable(): void
    {
        $this->actingAsSuperAdmin();

        $html = Livewire::test(CreateLocationPage::class)->html();

        // Waktu terbit disebut sebagai syarat, bukan sekadar catatan kecil.
        $this->assertStringContainsString('Waktu terbit', $html);
        $this->assertStringContainsString('WAJIB diisi', $html);
    }

    public function test_the_form_states_the_public_status_of_a_draft_page(): void
    {
        $this->actingAsSuperAdmin();

        $page = LocationPage::factory()->create(['slug' => 'draft-saya', 'is_active' => true]);

        $html = Livewire::test(EditLocationPage::class, ['record' => $page->getRouteKey()])->html();

        $this->assertStringContainsString('Status publik', $html);
        $this->assertStringContainsString('BELUM TERBIT', $html);
        // Alamat yang dituju tetap disebut supaya admin tahu URL-nya.
        $this->assertStringContainsString('draft-saya', $html);
    }

    public function test_the_form_states_the_public_status_of_a_live_page(): void
    {
        $this->actingAsSuperAdmin();

        $page = LocationPage::factory()->published()->create(['slug' => 'sudah-terbit']);

        $html = Livewire::test(EditLocationPage::class, ['record' => $page->getRouteKey()])->html();

        $this->assertStringContainsString('TERBIT', $html);
        $this->assertStringNotContainsString('BELUM TERBIT', $html);
    }

    public function test_saving_a_draft_page_warns_that_it_is_still_unreachable(): void
    {
        $this->actingAsSuperAdmin();
        [$group] = $this->groupWithLocation();

        $page = LocationPage::factory()->create(['is_active' => true]);
        $page->groups()->attach($group);

        Livewire::test(EditLocationPage::class, ['record' => $page->getRouteKey()])
            ->fillForm(['title' => 'Judul Baru'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Halaman belum dapat dibuka pengunjung');
    }

    public function test_creating_a_draft_page_warns_that_it_is_still_unreachable(): void
    {
        $this->actingAsSuperAdmin();
        [$group] = $this->groupWithLocation();

        Livewire::test(CreateLocationPage::class)
            ->fillForm([
                'title' => 'Halaman Draft',
                'slug' => 'halaman-draft',
                'group_ids' => [$group->getKey()],
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Halaman belum dapat dibuka pengunjung');
    }

    public function test_a_published_page_is_not_warned(): void
    {
        $this->actingAsSuperAdmin();
        [$group, $location] = $this->groupWithLocation();

        $page = LocationPage::factory()->published()->create();
        $page->groups()->attach($group);
        $page->locations()->attach($location);

        Livewire::test(EditLocationPage::class, ['record' => $page->getRouteKey()])
            ->fillForm(['title' => 'Judul Baru'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotNotified('Halaman belum dapat dibuka pengunjung');
    }

    // -------------------------------------------------- tombol preview jujur

    public function test_the_preview_button_is_hidden_while_the_page_is_unreachable(): void
    {
        $this->actingAsSuperAdmin();

        $draft = LocationPage::factory()->create(['is_active' => true]);
        $live = LocationPage::factory()->published()->create();

        Livewire::test(EditLocationPage::class, ['record' => $draft->getRouteKey()])
            ->assertActionHidden('preview');

        Livewire::test(EditLocationPage::class, ['record' => $live->getRouteKey()])
            ->assertActionVisible('preview');
    }

    public function test_the_table_preview_action_follows_the_same_rule(): void
    {
        $this->actingAsSuperAdmin();

        $draft = LocationPage::factory()->create(['is_active' => true]);
        $live = LocationPage::factory()->published()->create();

        Livewire::test(ListLocationPages::class)
            ->assertTableActionHidden('preview', $draft)
            ->assertTableActionVisible('preview', $live);
    }

    // ------------------------------------------------------------- session

    public function test_publishing_a_page_keeps_the_admin_signed_in(): void
    {
        $user = $this->actingAsSuperAdmin();
        [$group, $location] = $this->groupWithLocation();

        $page = LocationPage::factory()->create(['is_active' => true]);
        $page->groups()->attach($group);
        $page->locations()->attach($location);

        Livewire::test(EditLocationPage::class, ['record' => $page->getRouteKey()])
            ->fillForm(['published_at' => now()->subMinute()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(auth()->check(), 'Admin logout setelah menerbitkan halaman.');
        $this->assertSame($user->id, auth()->id());
        $this->assertTrue($page->fresh()->isPubliclyVisible());
    }
}
