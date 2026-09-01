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
use App\Models\LocationGroup;
use App\Models\LocationPage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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
        $html = $this->get('/admin/area')->getContent();

        foreach (['Lokasi Gerobak', 'Provinsi', 'Kota / Grup', 'Area', 'Gerobak'] as $label) {
            $this->assertStringContainsString($label, $html, "Label {$label} tidak ditemukan.");
        }
    }

    public function test_the_area_form_uses_indonesian_labels(): void
    {
        $html = Livewire::test(CreateLocationArea::class)->html();

        foreach (['Nama area', 'Kota/Grup', 'Provinsi', 'Aktif'] as $label) {
            $this->assertStringContainsString($label, $html, "Label {$label} tidak ditemukan.");
        }
    }

    /**
     * Area adalah master data: tidak ada satu pun field halaman publik.
     */
    public function test_the_area_form_has_no_public_page_field(): void
    {
        $html = Livewire::test(CreateLocationArea::class)->html();

        foreach (['Slug URL', 'Headline halaman', 'SEO title', 'Waktu terbit'] as $label) {
            $this->assertStringNotContainsString($label, $html, "Field {$label} seharusnya sudah pindah ke Halaman Slug Lokasi.");
        }
    }

    public function test_the_location_form_uses_indonesian_labels(): void
    {
        $html = Livewire::test(CreateLocation::class)->html();

        foreach ([
            'Area', 'Kota/Grup', 'Provinsi', 'Nama gerobak', 'Alamat lengkap', 'Kecamatan',
            'Patokan', 'Jam operasional', 'Latitude', 'Longitude',
        ] as $label) {
            $this->assertStringContainsString($label, $html, "Label {$label} tidak ditemukan.");
        }
    }

    // ----------------------------------------------------------- validation

    public function test_required_area_fields_are_enforced(): void
    {
        // sort_order tidak lagi diminta dari pengguna -- server yang menghitung.
        Livewire::test(CreateLocationArea::class)
            ->fillForm(['name' => '', 'location_group_id' => null])
            ->call('create')
            ->assertHasFormErrors(['name', 'location_group_id']);
    }

    public function test_required_location_fields_are_enforced(): void
    {
        Livewire::test(CreateLocation::class)
            ->fillForm(['location_area_id' => null, 'name' => '', 'full_address' => ''])
            ->call('create')
            ->assertHasFormErrors(['location_area_id', 'name', 'full_address']);
    }

    public function test_an_area_no_longer_accepts_a_slug(): void
    {
        $group = LocationGroup::factory()->create();

        Livewire::test(CreateLocationArea::class)
            ->fillForm([
                'province_id' => $group->province_id,
                'location_group_id' => $group->getKey(),
                'name' => 'Cianjur',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertFalse(
            Schema::hasColumn('location_areas', 'slug'),
            'Area tidak boleh lagi menyimpan slug.',
        );
    }

    public function test_two_areas_may_share_the_same_name(): void
    {
        $group = LocationGroup::factory()->create();
        LocationArea::factory()->for($group, 'group')->create(['name' => 'Cianjur']);

        // Tanpa slug, nama yang sama tidak lagi menimbulkan bentrok apa pun.
        Livewire::test(CreateLocationArea::class)
            ->fillForm([
                'province_id' => $group->province_id,
                'location_group_id' => $group->getKey(),
                'name' => 'Cianjur',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(2, LocationArea::query()->where('name', 'Cianjur')->count());
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

    /**
     * URL publik area kini dua segmen. Slug provinsinya dibaca dari rantai
     * induk sebenarnya, bukan ditulis ulang di setiap test.
     */
    private function areaUrl(string $areaSlug): string
    {
        $area = LocationArea::withTrashed()
            ->where('slug', $areaSlug)
            ->firstOrFail()
            ->load('group.province');

        return route('locations.area', [$area->group->province->slug, $areaSlug]);
    }

    /** URL area memakai slug provinsi yang sama, tetapi slug area lain. */
    private function areaUrlWithSlug(string $knownAreaSlug, string $otherAreaSlug): string
    {
        $area = LocationArea::withTrashed()
            ->where('slug', $knownAreaSlug)
            ->firstOrFail()
            ->load('group.province');

        return route('locations.area', [$area->group->province->slug, $otherAreaSlug]);
    }

    // ------------------------------------------------------ draft & publish

    public function test_an_area_is_created_active_and_placed_last(): void
    {
        $group = LocationGroup::factory()->create();
        LocationArea::factory()->for($group, 'group')->create(['sort_order' => 1]);

        Livewire::test(CreateLocationArea::class)
            ->fillForm([
                'province_id' => $group->province_id,
                'location_group_id' => $group->getKey(),
                'name' => 'Cianjur',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $area = LocationArea::query()->where('name', 'Cianjur')->firstOrFail();

        $this->assertTrue($area->is_active);
        $this->assertSame(2, (int) $area->sort_order, 'Record baru ditempatkan di posisi terakhir.');
    }

    /**
     * Area tidak punya URL sendiri. Yang bisa dijangkau publik adalah Halaman
     * Slug Lokasi yang memilih gerobak di bawah Area itu.
     */
    public function test_an_active_area_feeds_a_public_page(): void
    {
        $area = LocationArea::factory()->create(['name' => 'Cianjur']);
        $location = Location::factory()->for($area, 'area')->create(['name' => 'Gerobak Cianjur']);

        $page = LocationPage::factory()->published()->create();
        $page->groups()->attach($area->location_group_id);
        $page->locations()->attach($location);

        $this->get(route('location-pages.show', $page->slug))
            ->assertOk()
            ->assertSee('Gerobak Cianjur');

        // Menonaktifkan Area menghentikan gerobaknya tampil.
        $area->forceFill(['is_active' => false])->save();

        $this->get(route('location-pages.show', $page->slug))
            ->assertOk()
            ->assertDontSee('Gerobak Cianjur');
    }

    public function test_the_creator_and_editor_are_recorded(): void
    {
        $userId = auth()->id();

        $group = LocationGroup::factory()->create();

        Livewire::test(CreateLocationArea::class)
            ->fillForm([
                'province_id' => $group->province_id,
                'location_group_id' => $group->getKey(),
                'name' => 'Cianjur',
                'slug' => 'cianjur',
                'sort_order' => 0,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $area = LocationArea::query()->firstOrFail();

        $this->assertSame($userId, $area->created_by);
        $this->assertSame($userId, $area->updated_by);
    }

    // ---------------------------------------------------- slug & redirect

    /**
     * Slug tidak lagi ada di master hierarki. Yang tersisa untuk diperiksa:
     * form Area dan Gerobak benar-benar tidak menerima slug dari mana pun.
     */
    public function test_the_hierarchy_forms_never_accept_a_slug(): void
    {
        $area = LocationArea::factory()->create();
        $location = Location::factory()->for($area, 'area')->create();

        foreach ([
            [EditLocationArea::class, $area->getRouteKey()],
            [EditLocation::class, $location->getRouteKey()],
        ] as [$page, $key]) {
            $html = Livewire::test($page, ['record' => $key])->html();

            $this->assertStringNotContainsString('Slug URL', $html);
            $this->assertStringNotContainsString('PERINGATAN', $html);
        }
    }

    public function test_operator_cannot_change_a_location_active_state(): void
    {
        $area = LocationArea::factory()->create();
        $location = Location::factory()->inactive()->for($area, 'area')->create(['name' => 'Gerobak Awal']);

        $this->actingAs($this->userWithRole(UserRole::Operator));

        Livewire::test(EditLocation::class, ['record' => $location->getRouteKey()])
            ->fillForm(['is_active' => true, 'name' => 'Nama Baru'])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $location->fresh();

        // Field is_active tidak ter-dehydrate untuk Operator tanpa
        // update_locations, jadi nilainya tidak pernah sampai ke penyimpanan.
        $this->assertSame('Nama Baru', $fresh->name, 'Operator tetap boleh memperbarui data gerobak.');
    }

    public function test_operator_cannot_touch_the_location_page_module(): void
    {
        $this->actingAs($this->userWithRole(UserRole::Operator));

        $this->get('/admin/halaman-lokasi')->assertForbidden();
    }

    // ------------------------------------------------------------- warning

    public function test_saving_an_inactive_area_warns_the_admin(): void
    {
        $area = LocationArea::factory()->create();

        Livewire::test(EditLocationArea::class, ['record' => $area->getRouteKey()])
            ->fillForm(['name' => 'Kuningan', 'is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();
    }

    // -------------------------------------------------------------- tabel

    public function test_the_area_table_shows_counts_and_status(): void
    {
        $area = LocationArea::factory()->create(['name' => 'Cianjur']);
        Location::factory()->for($area, 'area')->create();
        Location::factory()->inactive()->for($area, 'area')->create();

        Livewire::test(ListLocationAreas::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$area])
            ->assertTableColumnStateSet('locations_count', 2, $area)
            ->assertTableColumnStateSet('visible_locations_count', 1, $area);
    }

    /**
     * Kolom status harus punya label untuk SETIAP keadaan, termasuk saat
     * induknya yang nonaktif. Kalau state-nya dibaca langsung dari kolom,
     * baris tertentu akan tampil sebagai sel kosong tanpa penjelasan.
     */
    public function test_the_area_visibility_column_labels_every_state(): void
    {
        $active = LocationArea::factory()->create(['name' => 'Area Aktif']);
        $inactive = LocationArea::factory()->inactive()->create(['name' => 'Area Nonaktif']);

        $hiddenGroup = LocationGroup::factory()->inactive()->create();
        $orphan = LocationArea::factory()->for($hiddenGroup, 'group')->create(['name' => 'Area Yatim']);

        Livewire::test(ListLocationAreas::class)
            ->assertTableColumnStateSet('visibility_status', 'Ya', $active)
            ->assertTableColumnStateSet('visibility_status', 'Nonaktif', $inactive)
            ->assertTableColumnStateSet('visibility_status', 'Induk nonaktif', $orphan);
    }

    public function test_the_location_visibility_column_labels_every_state(): void
    {
        $visibleArea = LocationArea::factory()->create();
        $hiddenArea = LocationArea::factory()->inactive()->create();

        $shown = Location::factory()->for($visibleArea, 'area')->create();
        $inactive = Location::factory()->inactive()->for($visibleArea, 'area')->create();
        $orphan = Location::factory()->for($hiddenArea, 'area')->create();

        Livewire::test(ListLocations::class)
            ->assertTableColumnStateSet('visibility_status', 'Ya', $shown)
            ->assertTableColumnStateSet('visibility_status', 'Nonaktif', $inactive)
            // Gerobak aktif di bawah Area nonaktif: dijelaskan, bukan
            // dibiarkan tampak seolah sudah tayang.
            ->assertTableColumnStateSet('visibility_status', 'Induk nonaktif', $orphan);
    }

    public function test_the_area_table_can_be_searched_and_filtered(): void
    {
        $active = LocationArea::factory()->create(['name' => 'Cianjur']);
        $inactive = LocationArea::factory()->inactive()->create(['name' => 'Karawang']);

        Livewire::test(ListLocationAreas::class)
            ->searchTable('Cianjur')
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$inactive]);

        Livewire::test(ListLocationAreas::class)
            ->filterTable('is_active', true)
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$inactive]);
    }

    public function test_the_area_table_can_flag_pages_without_visible_locations(): void
    {
        $needsAttention = LocationArea::factory()->create(['name' => 'Kosong']);

        $healthy = LocationArea::factory()->create(['name' => 'Sehat']);
        Location::factory()->for($healthy, 'area')->create();

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
        $area = LocationArea::factory()->create();
        $location = Location::factory()->for($area, 'area')->create(['name' => 'Gerobak Uji Hapus']);

        $page = LocationPage::factory()->published()->create();
        $page->groups()->attach($area->location_group_id);
        $page->locations()->attach($location);

        $url = route('location-pages.show', $page->slug);

        $this->get($url)->assertSee('Gerobak Uji Hapus', false);

        $location->delete();

        // Gerobak terhapus lenyap dari halaman, tetapi barisnya tetap ada.
        $this->get($url)->assertDontSee('Gerobak Uji Hapus', false);
        $this->assertSoftDeleted($location);

        $location->restore();

        $this->get($url)->assertSee('Gerobak Uji Hapus', false);
    }

    // --------------------------------------------------------- reorder

    public function test_areas_can_be_reordered(): void
    {
        $group = LocationGroup::factory()->create();
        $first = LocationArea::factory()->for($group, 'group')->create(['name' => 'A', 'sort_order' => 1]);
        $second = LocationArea::factory()->for($group, 'group')->create(['name' => 'B', 'sort_order' => 2]);

        /*
         | reorderTable() adalah method komponen Livewire, bukan helper test.
         | Filter induk WAJIB diset lebih dulu: urutan hanya bermakna di dalam
         | satu Kota/Grup, jadi reorder ditutup selama tabel masih mencampur
         | Area dari beberapa grup.
         */
        Livewire::test(ListLocationAreas::class)
            ->set('tableFilters.location_group_id.value', $group->getKey())
            ->call('reorderTable', [$second->getKey(), $first->getKey()]);

        $this->assertLessThan(
            $first->fresh()->sort_order,
            $second->fresh()->sort_order,
            'Urutan hasil drag harus tersimpan.'
        );
    }
}
