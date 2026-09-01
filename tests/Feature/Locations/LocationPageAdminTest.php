<?php

namespace Tests\Feature\Locations;

use App\Enums\PanelPermission;
use App\Enums\UserRole;
use App\Filament\Resources\LocationPages\Pages\CreateLocationPage;
use App\Filament\Resources\LocationPages\Pages\EditLocationPage;
use App\Filament\Resources\LocationPages\Pages\ListLocationPages;
use App\Models\Location;
use App\Models\LocationArea;
use App\Models\LocationGroup;
use App\Models\LocationPage;
use App\Models\LocationPageSlugRedirect;
use App\Models\Province;
use App\Models\User;
use App\Services\LocationPageCatalogService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Admin modul Halaman Slug Lokasi.
 *
 * Yang dijaga: authorization berjalan di server (bukan sekadar menu yang
 * disembunyikan), payload di luar cakupan ditolak, urutan tidak pernah
 * diminta dari pengguna, dan slug hanya boleh diubah pemegang izinnya.
 */
class LocationPageAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function userWithRole(UserRole $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role->value);

        return $user->fresh();
    }

    protected function actingAsRole(UserRole $role): User
    {
        $user = $this->userWithRole($role);
        $this->actingAs($user);

        return $user;
    }

    /** @return array{0: LocationGroup, 1: Location} */
    protected function groupWithLocation(string $locationName = 'Gerobak Uji'): array
    {
        $group = LocationGroup::factory()->create();
        $area = LocationArea::factory()->for($group, 'group')->create();
        $location = Location::factory()->for($area, 'area')->create(['name' => $locationName]);

        return [$group, $location];
    }

    // ------------------------------------------------------- authorization

    public function test_super_admin_and_admin_can_reach_the_module(): void
    {
        foreach ([UserRole::SuperAdmin, UserRole::Admin] as $role) {
            $this->actingAsRole($role);

            $this->get('/admin/halaman-lokasi')->assertOk();
            $this->get('/admin/halaman-lokasi/create')->assertOk();
        }
    }

    public function test_operator_is_refused_even_on_a_direct_url(): void
    {
        $page = LocationPage::factory()->create();

        $this->actingAsRole(UserRole::Operator);

        // Menyembunyikan menu saja tidak pernah dianggap proteksi.
        $this->get('/admin/halaman-lokasi')->assertForbidden();
        $this->get('/admin/halaman-lokasi/create')->assertForbidden();
        $this->get("/admin/halaman-lokasi/{$page->getKey()}/edit")->assertForbidden();
    }

    public function test_a_user_without_any_role_is_refused(): void
    {
        $this->actingAs(User::factory()->create(['is_active' => true]));

        $this->get('/admin/halaman-lokasi')->assertForbidden();
    }

    public function test_an_inactive_account_is_refused(): void
    {
        $user = $this->userWithRole(UserRole::SuperAdmin);
        $user->forceFill(['is_active' => false])->save();

        $this->actingAs($user->fresh());

        $this->get('/admin/halaman-lokasi')->assertForbidden();
    }

    public function test_operator_holds_no_location_page_permission(): void
    {
        $operator = $this->userWithRole(UserRole::Operator);

        foreach (PanelPermission::locationPageCases() as $permission) {
            $this->assertFalse(
                $operator->can($permission->value),
                "Operator tidak boleh punya {$permission->value}.",
            );
        }
    }

    public function test_admin_holds_every_location_page_permission(): void
    {
        $admin = $this->userWithRole(UserRole::Admin);

        foreach (PanelPermission::locationPageCases() as $permission) {
            $this->assertTrue(
                $admin->can($permission->value),
                "Admin seharusnya punya {$permission->value}.",
            );
        }
    }

    // ---------------------------------------------------------------- form

    public function test_the_form_uses_indonesian_labels(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $html = Livewire::test(CreateLocationPage::class)->html();

        foreach ([
            'Judul halaman', 'Slug URL', 'Deskripsi singkat', 'Deskripsi detail',
            'Kota/Grup', 'Gerobak', 'Poster halaman', 'Waktu terbit', 'Teks tombol',
        ] as $label) {
            $this->assertStringContainsString($label, $html, "Label {$label} tidak ditemukan.");
        }
    }

    public function test_the_form_never_asks_for_a_sort_order(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $html = Livewire::test(CreateLocationPage::class)->html();

        $this->assertStringNotContainsString('Urutan', $html);
        $this->assertStringNotContainsString('wire:model="data.sort_order"', $html);
    }

    public function test_required_fields_are_enforced(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        Livewire::test(CreateLocationPage::class)
            ->fillForm(['title' => '', 'slug' => '', 'group_ids' => []])
            ->call('create')
            ->assertHasFormErrors(['title', 'slug', 'group_ids']);
    }

    public function test_a_page_is_created_with_its_scope_and_content(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);
        [$group, $location] = $this->groupWithLocation();

        Livewire::test(CreateLocationPage::class)
            ->fillForm([
                'title' => 'Alamat Cianjur',
                'slug' => 'alamat-cianjur',
                'group_ids' => [$group->getKey()],
                'location_ids' => [$location->getKey()],
                'is_active' => true,
                'published_at' => now()->subMinute(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $page = LocationPage::query()->where('slug', 'alamat-cianjur')->firstOrFail();

        $this->assertSame([$group->getKey()], $page->groups()->pluck('location_groups.id')->all());
        $this->assertSame([$location->getKey()], $page->locations()->pluck('locations.id')->all());
        $this->assertTrue($page->isPubliclyVisible());
    }

    public function test_a_new_page_is_placed_last(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);
        [$group] = $this->groupWithLocation();

        LocationPage::factory()->create(['sort_order' => 1]);

        Livewire::test(CreateLocationPage::class)
            ->fillForm([
                'title' => 'Halaman Kedua',
                'slug' => 'halaman-kedua',
                'group_ids' => [$group->getKey()],
                // Nilai dari request tidak pernah dipercaya.
                'sort_order' => 999,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(
            2,
            (int) LocationPage::query()->where('slug', 'halaman-kedua')->value('sort_order'),
            'Urutan harus dihitung server, bukan diambil dari request.',
        );
    }

    // ------------------------------------------------------- cakupan & isi

    public function test_a_location_outside_the_scope_is_rejected_by_the_form(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        [$group] = $this->groupWithLocation();
        $intruder = Location::factory()->create(['name' => 'Gerobak Asing']);

        Livewire::test(CreateLocationPage::class)
            ->fillForm([
                'title' => 'Alamat Curang',
                'slug' => 'alamat-curang',
                'group_ids' => [$group->getKey()],
                // ID dari Kota/Grup lain disisipkan ke payload.
                'location_ids' => [$intruder->getKey()],
            ])
            ->call('create')
            ->assertHasFormErrors(['location_ids']);

        $this->assertDatabaseCount('location_pages', 0);
    }

    public function test_candidates_only_come_from_the_selected_groups(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $province = Province::factory()->create(['name' => 'Jawa Barat']);
        $group = LocationGroup::factory()->for($province, 'province')->create(['name' => 'Cianjur']);
        $area = LocationArea::factory()->for($group, 'group')->create(['name' => 'Cianjur Kota']);
        Location::factory()->for($area, 'area')->create(['name' => 'Gerobak Terdaftar']);

        Location::factory()->create(['name' => 'Gerobak Di Luar']);

        $html = Livewire::test(CreateLocationPage::class)
            ->fillForm(['group_ids' => [$group->getKey()]])
            ->html();

        $this->assertStringContainsString('Gerobak Terdaftar', $html);
        $this->assertStringNotContainsString('Gerobak Di Luar', $html);
        // Area muncul sebagai judul kelompok, bukan sebagai pilihan.
        $this->assertStringContainsString('Jawa Barat — Cianjur · Cianjur Kota', $html);
    }

    public function test_no_candidate_is_offered_before_a_group_is_chosen(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        Location::factory()->create(['name' => 'Gerobak Rahasia']);

        $html = Livewire::test(CreateLocationPage::class)->html();

        $this->assertStringNotContainsString('Gerobak Rahasia', $html);
    }

    public function test_a_page_can_cover_several_groups_at_once(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        [$groupA, $first] = $this->groupWithLocation('Gerobak A');
        [$groupB, $second] = $this->groupWithLocation('Gerobak B');

        Livewire::test(CreateLocationPage::class)
            ->fillForm([
                'title' => 'Alamat Gabungan',
                'slug' => 'alamat-gabungan',
                'group_ids' => [$groupA->getKey(), $groupB->getKey()],
                'location_ids' => [$first->getKey(), $second->getKey()],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $page = LocationPage::query()->where('slug', 'alamat-gabungan')->firstOrFail();

        $this->assertEqualsCanonicalizing(
            [$groupA->getKey(), $groupB->getKey()],
            $page->groups()->pluck('location_groups.id')->all(),
        );
    }

    public function test_detaching_a_group_that_still_holds_locations_is_refused(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        [$group, $location] = $this->groupWithLocation();
        $page = LocationPage::factory()->create();
        $page->groups()->attach($group);
        $page->locations()->attach($location);

        Livewire::test(EditLocationPage::class, ['record' => $page->getRouteKey()])
            ->fillForm(['group_ids' => []])
            ->call('save')
            ->assertHasFormErrors(['group_ids']);

        // Tidak ada detach diam-diam: isi halaman tetap utuh.
        $this->assertSame(1, $page->fresh()->groups()->count());
        $this->assertSame(1, $page->fresh()->locations()->count());
    }

    // ---------------------------------------------------------------- slug

    public function test_changing_a_published_slug_records_a_redirect(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        [$group, $location] = $this->groupWithLocation();
        $page = LocationPage::factory()->published()->create(['slug' => 'alamat-lama']);
        $page->groups()->attach($group);
        $page->locations()->attach($location);

        Livewire::test(EditLocationPage::class, ['record' => $page->getRouteKey()])
            ->fillForm(['slug' => 'alamat-baru'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('alamat-baru', $page->fresh()->slug);
        $this->assertTrue(
            LocationPageSlugRedirect::query()->where('old_slug', 'alamat-lama')->exists(),
        );
    }

    public function test_a_slug_already_taken_is_rejected(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);
        [$group] = $this->groupWithLocation();

        LocationPage::factory()->create(['slug' => 'sudah-dipakai']);

        Livewire::test(CreateLocationPage::class)
            ->fillForm([
                'title' => 'Halaman Lain',
                'slug' => 'sudah-dipakai',
                'group_ids' => [$group->getKey()],
            ])
            ->call('create')
            ->assertHasFormErrors(['slug']);
    }

    public function test_an_invalid_slug_format_is_rejected(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);
        [$group] = $this->groupWithLocation();

        Livewire::test(CreateLocationPage::class)
            ->fillForm([
                'title' => 'Halaman',
                'slug' => 'Slug Salah!',
                'group_ids' => [$group->getKey()],
            ])
            ->call('create')
            ->assertHasFormErrors(['slug']);
    }

    public function test_the_slug_field_warns_once_the_page_has_been_published(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $published = LocationPage::factory()->published()->create();
        $draft = LocationPage::factory()->create();

        $this->assertStringContainsString(
            'PERINGATAN',
            Livewire::test(EditLocationPage::class, ['record' => $published->getRouteKey()])->html(),
        );

        $this->assertStringNotContainsString(
            'PERINGATAN',
            Livewire::test(EditLocationPage::class, ['record' => $draft->getRouteKey()])->html(),
        );
    }

    // ----------------------------------------------------------------- CTA

    public function test_an_unsafe_cta_url_is_rejected(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);
        [$group] = $this->groupWithLocation();

        foreach ([
            'javascript:alert(1)',
            'data:text/html;base64,PHN2Zz4=',
            'http://contoh.test/promo',
            'https://user:pass@contoh.test/promo',
        ] as $unsafe) {
            Livewire::test(CreateLocationPage::class)
                ->fillForm([
                    'title' => 'Halaman CTA',
                    'slug' => 'halaman-cta',
                    'group_ids' => [$group->getKey()],
                    'button_text' => 'Pesan',
                    'button_url' => $unsafe,
                ])
                ->call('create')
                ->assertHasFormErrors(['button_url']);
        }
    }

    public function test_a_safe_https_cta_url_is_accepted(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);
        [$group] = $this->groupWithLocation();

        Livewire::test(CreateLocationPage::class)
            ->fillForm([
                'title' => 'Halaman CTA',
                'slug' => 'halaman-cta',
                'group_ids' => [$group->getKey()],
                'button_text' => 'Pesan',
                'button_url' => 'https://contoh.test/promo',
            ])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    public function test_an_end_date_before_the_start_date_is_rejected(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);
        [$group] = $this->groupWithLocation();

        Livewire::test(CreateLocationPage::class)
            ->fillForm([
                'title' => 'Halaman Periode',
                'slug' => 'halaman-periode',
                'group_ids' => [$group->getKey()],
                'starts_at' => now()->addWeek(),
                'ends_at' => now()->subWeek(),
            ])
            ->call('create')
            ->assertHasFormErrors(['ends_at']);
    }

    // -------------------------------------------------------------- tabel

    public function test_the_table_lists_pages_with_their_counts(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        [$group, $location] = $this->groupWithLocation();
        $inactive = Location::factory()->inactive()
            ->for(LocationArea::factory()->for($group, 'group'), 'area')->create();

        $page = LocationPage::factory()->published()->create();
        $page->groups()->attach($group);
        $page->locations()->attach([$location->getKey(), $inactive->getKey()]);

        Livewire::test(ListLocationPages::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$page])
            ->assertTableColumnStateSet('groups_count', 1, $page)
            ->assertTableColumnStateSet('visible_locations_count', 1, $page);
    }

    public function test_the_publication_column_labels_every_status(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $draft = LocationPage::factory()->create();
        $scheduled = LocationPage::factory()->scheduled()->create();
        $inactive = LocationPage::factory()->inactive()->create();
        $expired = LocationPage::factory()->expired()->create();
        $published = LocationPage::factory()->published()->create();

        Livewire::test(ListLocationPages::class)
            ->assertTableColumnStateSet('publication_status', 'Draft', $draft)
            ->assertTableColumnStateSet('publication_status', 'Terjadwal', $scheduled)
            ->assertTableColumnStateSet('publication_status', 'Nonaktif', $inactive)
            ->assertTableColumnStateSet('publication_status', 'Di luar periode', $expired)
            ->assertTableColumnStateSet('publication_status', 'Terbit', $published);
    }

    // ------------------------------------------------------------ reorder

    public function test_pages_can_be_reordered_and_the_cache_is_invalidated(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $first = LocationPage::factory()->create(['sort_order' => 1]);
        $second = LocationPage::factory()->create(['sort_order' => 2]);

        $before = LocationPageCatalogService::cacheVersion();

        Livewire::test(ListLocationPages::class)
            ->call('reorderTable', [(string) $second->getKey(), (string) $first->getKey()]);

        $this->assertSame(1, (int) $second->fresh()->sort_order);
        $this->assertSame(2, (int) $first->fresh()->sort_order);

        $this->assertGreaterThan(
            $before,
            LocationPageCatalogService::cacheVersion(),
            'Versi cache harus naik supaya homepage memakai urutan baru.',
        );
    }

    public function test_reordering_is_closed_while_a_search_is_active(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        LocationPage::factory()->create(['title' => 'Alfa', 'sort_order' => 1]);
        $second = LocationPage::factory()->create(['title' => 'Beta', 'sort_order' => 2]);

        $component = Livewire::test(ListLocationPages::class)->searchTable('Beta');

        $this->assertFalse(
            $component->instance()->getTable()->isReorderable(),
            'Reorder harus tertutup saat pencarian menyaring baris.',
        );

        // Server ikut menolak, bukan sekadar tombolnya hilang.
        $component->call('reorderTable', [(string) $second->getKey()]);

        $this->assertSame(2, (int) $second->fresh()->sort_order);
    }
}
