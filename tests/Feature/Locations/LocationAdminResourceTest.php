<?php

namespace Tests\Feature\Locations;

use App\Enums\UserRole;
use App\Filament\Resources\LocationAreas\Pages\CreateLocationArea;
use App\Filament\Resources\LocationAreas\Pages\EditLocationArea;
use App\Filament\Resources\LocationAreas\Pages\ListLocationAreas;
use App\Filament\Resources\Locations\Pages\CreateLocation;
use App\Filament\Resources\Locations\Pages\EditLocation;
use App\Filament\Resources\Locations\Pages\ListLocations;
use App\Models\Location;
use App\Models\LocationArea;
use App\Models\LocationAreaSlugRedirect;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LocationAdminResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->actingAs($this->userWithRole(UserRole::SuperAdmin));
    }

    protected function userWithRole(UserRole $role, bool $active = true): User
    {
        $user = User::factory()->create(['is_active' => $active]);
        $user->assignRole($role->value);

        return $user->fresh();
    }

    // ------------------------------------------------------------ rendering

    public function test_both_list_pages_render(): void
    {
        Livewire::test(ListLocationAreas::class)->assertSuccessful();
        Livewire::test(ListLocations::class)->assertSuccessful();
    }

    public function test_both_create_pages_render(): void
    {
        Livewire::test(CreateLocationArea::class)->assertSuccessful();
        Livewire::test(CreateLocation::class)->assertSuccessful();
    }

    public function test_the_area_navigation_group_is_indonesian(): void
    {
        $html = $this->get('/admin/wilayah-landing')->getContent();

        foreach (['Lokasi Gerobak', 'Wilayah Landing', 'Gerobak'] as $label) {
            $this->assertStringContainsString($label, $html, "Label {$label} tidak ditemukan.");
        }
    }

    public function test_the_area_form_uses_indonesian_labels(): void
    {
        $html = Livewire::test(CreateLocationArea::class)->html();

        foreach ([
            'Nama wilayah', 'Slug URL', 'Headline halaman', 'Deskripsi wilayah',
            'Kota/Kabupaten', 'Provinsi', 'Waktu terbit', 'Urutan',
        ] as $label) {
            $this->assertStringContainsString($label, $html, "Label {$label} tidak ditemukan.");
        }
    }

    public function test_the_location_form_uses_indonesian_labels(): void
    {
        $html = Livewire::test(CreateLocation::class)->html();

        foreach ([
            'Wilayah landing', 'Nama gerobak', 'Alamat lengkap', 'Kecamatan',
            'Patokan', 'Jam operasional', 'Latitude', 'Longitude',
        ] as $label) {
            $this->assertStringContainsString($label, $html, "Label {$label} tidak ditemukan.");
        }
    }

    // ----------------------------------------------------------- validation

    public function test_required_area_fields_are_enforced(): void
    {
        Livewire::test(CreateLocationArea::class)
            ->fillForm(['name' => '', 'slug' => '', 'sort_order' => null])
            ->call('create')
            ->assertHasFormErrors(['name', 'slug', 'sort_order']);
    }

    public function test_required_location_fields_are_enforced(): void
    {
        Livewire::test(CreateLocation::class)
            ->fillForm(['location_area_id' => null, 'name' => '', 'full_address' => ''])
            ->call('create')
            ->assertHasFormErrors(['location_area_id', 'name', 'full_address']);
    }

    public function test_an_invalid_slug_format_is_rejected(): void
    {
        Livewire::test(CreateLocationArea::class)
            ->fillForm(['name' => 'Cianjur', 'slug' => 'Cianjur Kota!', 'sort_order' => 0])
            ->call('create')
            ->assertHasFormErrors(['slug']);
    }

    public function test_a_duplicate_slug_is_rejected(): void
    {
        LocationArea::factory()->create(['slug' => 'cianjur']);

        Livewire::test(CreateLocationArea::class)
            ->fillForm(['name' => 'Cianjur Lain', 'slug' => 'cianjur', 'sort_order' => 0])
            ->call('create')
            ->assertHasFormErrors(['slug']);
    }

    public function test_an_unsafe_maps_url_is_rejected(): void
    {
        $area = LocationArea::factory()->create();

        foreach ([
            'javascript:alert(1)',
            'http://www.google.com/maps',
            'https://google.com.evil.example.com/maps',
        ] as $url) {
            Livewire::test(CreateLocation::class)
                ->fillForm([
                    'location_area_id' => $area->id,
                    'name' => 'Gerobak Uji',
                    'full_address' => 'Alamat uji',
                    'google_maps_url' => $url,
                    'sort_order' => 0,
                ])
                ->call('create')
                ->assertHasFormErrors(['google_maps_url']);
        }

        $this->assertSame(0, Location::query()->count());
    }

    public function test_an_invalid_whatsapp_number_is_rejected(): void
    {
        $area = LocationArea::factory()->create();

        Livewire::test(CreateLocation::class)
            ->fillForm([
                'location_area_id' => $area->id,
                'name' => 'Gerobak Uji',
                'full_address' => 'Alamat uji',
                'whatsapp_number' => 'bukan-nomor',
                'sort_order' => 0,
            ])
            ->call('create')
            ->assertHasFormErrors(['whatsapp_number']);
    }

    // ------------------------------------------------------ draft & publish

    public function test_an_area_is_created_as_a_draft_by_default(): void
    {
        Livewire::test(CreateLocationArea::class)
            ->fillForm(['name' => 'Cianjur', 'slug' => 'cianjur', 'sort_order' => 0])
            ->call('create')
            ->assertHasNoFormErrors();

        $area = LocationArea::query()->firstOrFail();

        $this->assertSame('cianjur', $area->slug);
        $this->assertNull($area->published_at, 'Tanpa waktu terbit = draft.');
        $this->assertFalse($area->isPubliclyVisible());

        // Draft tidak boleh bocor ke publik.
        $this->get('/lokasi/cianjur')->assertNotFound();
    }

    public function test_publishing_an_area_makes_it_reachable(): void
    {
        $area = LocationArea::factory()->create(['slug' => 'cianjur', 'name' => 'Cianjur']);
        Location::factory()->for($area, 'area')->published()->create();

        Livewire::test(EditLocationArea::class, ['record' => $area->getRouteKey()])
            ->fillForm(['is_active' => true, 'published_at' => now()->subMinute()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->get('/lokasi/cianjur')->assertOk();
    }

    public function test_the_creator_and_editor_are_recorded(): void
    {
        $userId = auth()->id();

        Livewire::test(CreateLocationArea::class)
            ->fillForm(['name' => 'Cianjur', 'slug' => 'cianjur', 'sort_order' => 0])
            ->call('create')
            ->assertHasNoFormErrors();

        $area = LocationArea::query()->firstOrFail();

        $this->assertSame($userId, $area->created_by);
        $this->assertSame($userId, $area->updated_by);
    }

    // ---------------------------------------------------- slug & redirect

    public function test_changing_a_published_slug_records_a_redirect(): void
    {
        $area = LocationArea::factory()->published()->create(['slug' => 'cianjur', 'name' => 'Cianjur']);
        Location::factory()->for($area, 'area')->published()->create();

        Livewire::test(EditLocationArea::class, ['record' => $area->getRouteKey()])
            ->fillForm(['slug' => 'cianjur-kota'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('cianjur-kota', $area->fresh()->slug);

        $this->assertTrue(
            LocationAreaSlugRedirect::query()->where('old_slug', 'cianjur')->exists(),
            'Slug lama harus tercatat sebagai redirect.'
        );

        $this->get('/lokasi/cianjur')->assertStatus(301);
        $this->get('/lokasi/cianjur-kota')->assertOk();
    }

    public function test_the_slug_field_warns_when_the_page_has_been_published(): void
    {
        $area = LocationArea::factory()->published()->create(['slug' => 'cianjur']);

        $html = Livewire::test(EditLocationArea::class, ['record' => $area->getRouteKey()])->html();

        $this->assertStringContainsString('PERINGATAN', $html);
        $this->assertStringContainsString('sudah pernah terbit', $html);
    }

    public function test_a_draft_slug_field_shows_no_warning(): void
    {
        $area = LocationArea::factory()->create(['slug' => 'cianjur']);

        $html = Livewire::test(EditLocationArea::class, ['record' => $area->getRouteKey()])->html();

        $this->assertStringNotContainsString('PERINGATAN', $html);
    }

    public function test_operator_cannot_submit_a_slug_change(): void
    {
        $area = LocationArea::factory()->published()->create(['slug' => 'cianjur']);
        $location = Location::factory()->for($area, 'area')->published()->create(['slug' => 'gerobak-a']);

        $this->actingAs($this->userWithRole(UserRole::Operator));

        Livewire::test(EditLocation::class, ['record' => $location->getRouteKey()])
            ->fillForm(['slug' => 'slug-baru-dari-operator', 'name' => 'Nama Baru'])
            ->call('save')
            ->assertHasNoFormErrors();

        // Field slug tidak ter-dehydrate untuk Operator, jadi nilainya tidak
        // pernah sampai ke penyimpanan.
        $this->assertSame('gerobak-a', $location->fresh()->slug);
        $this->assertSame('Nama Baru', $location->fresh()->name);
    }

    public function test_operator_cannot_publish_a_location(): void
    {
        $area = LocationArea::factory()->published()->create();
        $location = Location::factory()->for($area, 'area')->create(['name' => 'Gerobak Draft']);

        $this->actingAs($this->userWithRole(UserRole::Operator));

        Livewire::test(EditLocation::class, ['record' => $location->getRouteKey()])
            ->fillForm(['is_active' => true, 'published_at' => now()->subDay(), 'name' => 'Gerobak Draft'])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $location->fresh();

        $this->assertFalse($fresh->is_active, 'Operator tidak boleh mengaktifkan gerobak.');
        $this->assertNull($fresh->published_at, 'Operator tidak boleh menerbitkan gerobak.');
    }

    // ------------------------------------------------------------- warning

    public function test_saving_a_published_area_without_visible_locations_warns(): void
    {
        $area = LocationArea::factory()->published()->create(['slug' => 'kuningan']);

        Livewire::test(EditLocationArea::class, ['record' => $area->getRouteKey()])
            ->fillForm(['name' => 'Kuningan'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        // Halaman tetap hidup, tapi isinya empty state.
        $this->get('/lokasi/kuningan')
            ->assertOk()
            ->assertSee('Titik lokasi sedang diperbarui', false);
    }

    // -------------------------------------------------------------- tabel

    public function test_the_area_table_shows_counts_and_status(): void
    {
        $area = LocationArea::factory()->published()->create(['name' => 'Cianjur']);
        Location::factory()->for($area, 'area')->published()->create();
        Location::factory()->for($area, 'area')->create();

        Livewire::test(ListLocationAreas::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$area])
            ->assertTableColumnStateSet('locations_count', 2, $area)
            ->assertTableColumnStateSet('visible_locations_count', 1, $area);
    }

    /**
     * Kolom publikasi harus menampilkan label untuk SEMUA status, termasuk
     * draft. Kalau state-nya dibaca langsung dari kolom published_at, baris
     * draft bernilai null dan Filament menampilkan sel kosong.
     */
    public function test_the_publication_column_labels_every_status(): void
    {
        $draft = LocationArea::factory()->create(['name' => 'Area Draft']);
        $scheduled = LocationArea::factory()->scheduled()->create(['name' => 'Area Terjadwal']);
        $inactive = LocationArea::factory()->inactive()->create(['name' => 'Area Nonaktif']);
        $published = LocationArea::factory()->published()->create(['name' => 'Area Terbit']);

        Livewire::test(ListLocationAreas::class)
            ->assertTableColumnStateSet('publication_status', 'Draft', $draft)
            ->assertTableColumnStateSet('publication_status', 'Terjadwal', $scheduled)
            ->assertTableColumnStateSet('publication_status', 'Nonaktif', $inactive)
            ->assertTableColumnStateSet('publication_status', 'Terbit', $published);
    }

    public function test_the_location_publication_column_labels_every_status(): void
    {
        $visibleArea = LocationArea::factory()->published()->create();
        $hiddenArea = LocationArea::factory()->create();

        $draft = Location::factory()->for($visibleArea, 'area')->create();
        $shown = Location::factory()->for($visibleArea, 'area')->published()->create();
        $orphan = Location::factory()->for($hiddenArea, 'area')->published()->create();

        Livewire::test(ListLocations::class)
            ->assertTableColumnStateSet('publication_status', 'Draft', $draft)
            ->assertTableColumnStateSet('publication_status', 'Tampil', $shown)
            // Gerobak terbit di bawah wilayah yang belum terbit: dijelaskan,
            // bukan dibiarkan tampak seolah sudah tayang.
            ->assertTableColumnStateSet('publication_status', 'Wilayah belum terbit', $orphan);
    }

    public function test_the_area_table_can_be_searched_and_filtered(): void
    {
        $published = LocationArea::factory()->published()->create(['name' => 'Cianjur']);
        $draft = LocationArea::factory()->create(['name' => 'Karawang']);

        Livewire::test(ListLocationAreas::class)
            ->searchTable('Cianjur')
            ->assertCanSeeTableRecords([$published])
            ->assertCanNotSeeTableRecords([$draft]);

        Livewire::test(ListLocationAreas::class)
            ->filterTable('published')
            ->assertCanSeeTableRecords([$published])
            ->assertCanNotSeeTableRecords([$draft]);
    }

    public function test_the_area_table_can_flag_pages_without_visible_locations(): void
    {
        $needsAttention = LocationArea::factory()->published()->create(['name' => 'Kosong']);

        $healthy = LocationArea::factory()->published()->create(['name' => 'Sehat']);
        Location::factory()->for($healthy, 'area')->published()->create();

        Livewire::test(ListLocationAreas::class)
            ->filterTable('needs_attention')
            ->assertCanSeeTableRecords([$needsAttention])
            ->assertCanNotSeeTableRecords([$healthy]);
    }

    public function test_the_location_table_can_be_filtered_by_area(): void
    {
        $first = LocationArea::factory()->create(['name' => 'Cianjur']);
        $second = LocationArea::factory()->create(['name' => 'Karawang']);

        $a = Location::factory()->for($first, 'area')->create();
        $b = Location::factory()->for($second, 'area')->create();

        Livewire::test(ListLocations::class)
            ->filterTable('location_area_id', $first->id)
            ->assertCanSeeTableRecords([$a])
            ->assertCanNotSeeTableRecords([$b]);
    }

    public function test_the_location_table_can_flag_missing_maps_data(): void
    {
        $area = LocationArea::factory()->create();

        $missing = Location::factory()->for($area, 'area')->create();
        $withCoordinates = Location::factory()->for($area, 'area')->create([
            'latitude' => -6.81,
            'longitude' => 107.12,
        ]);

        Livewire::test(ListLocations::class)
            ->filterTable('missing_maps')
            ->assertCanSeeTableRecords([$missing])
            ->assertCanNotSeeTableRecords([$withCoordinates]);
    }

    // ------------------------------------------------------- soft delete

    public function test_a_location_can_be_soft_deleted_and_restored(): void
    {
        $area = LocationArea::factory()->published()->create(['slug' => 'cianjur']);
        $location = Location::factory()->for($area, 'area')->published()->create(['name' => 'Gerobak Uji Hapus']);

        $this->get('/lokasi/cianjur')->assertSee('Gerobak Uji Hapus', false);

        $location->delete();

        $this->get('/lokasi/cianjur')->assertDontSee('Gerobak Uji Hapus', false);
        $this->assertSoftDeleted($location);

        $location->restore();

        $this->get('/lokasi/cianjur')->assertSee('Gerobak Uji Hapus', false);
    }

    // --------------------------------------------------------- reorder

    public function test_areas_can_be_reordered(): void
    {
        $first = LocationArea::factory()->create(['name' => 'A', 'sort_order' => 0]);
        $second = LocationArea::factory()->create(['name' => 'B', 'sort_order' => 1]);

        // reorderTable() adalah method komponen Livewire, bukan helper test.
        Livewire::test(ListLocationAreas::class)
            ->call('reorderTable', [$second->getKey(), $first->getKey()]);

        $this->assertLessThan(
            $first->fresh()->sort_order,
            $second->fresh()->sort_order,
            'Urutan hasil drag harus tersimpan.'
        );
    }
}
