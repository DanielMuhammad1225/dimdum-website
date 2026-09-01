<?php

namespace Tests\Feature\Locations;

use App\Enums\UserRole;
use App\Filament\Resources\LocationAreas\Pages\CreateLocationArea;
use App\Filament\Resources\LocationAreas\Pages\EditLocationArea;
use App\Filament\Resources\LocationAreas\Pages\ListLocationAreas;
use App\Filament\Resources\LocationGroups\Pages\CreateLocationGroup;
use App\Filament\Resources\LocationGroups\Pages\ListLocationGroups;
use App\Filament\Resources\Locations\Pages\CreateLocation;
use App\Filament\Resources\Locations\Pages\ListLocations;
use App\Filament\Resources\Provinces\Pages\CreateProvince;
use App\Filament\Resources\Provinces\Pages\ListProvinces;
use App\Models\Location;
use App\Models\LocationArea;
use App\Models\LocationGroup;
use App\Models\LocationPage;
use App\Models\Province;
use App\Models\User;
use App\Services\LocationOrderingService;
use App\Services\LocationPageCatalogService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Sistem urutan: penempatan otomatis, drag-and-drop, dan scope per induk.
 *
 * Dua hal yang dijaga di sini:
 *
 *  1. Nomor urut TIDAK PERNAH datang dari request. Dihitung server, sehingga
 *     request yang dimanipulasi tidak bisa menyisipkan posisi pilihannya.
 *
 *  2. Urutan selalu terkurung di dalam SATU induk. Tidak pernah ada urutan
 *     global yang mencampur anak dari induk berbeda.
 */
class LocationOrderingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function actingAsRole(UserRole $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role->value);

        $this->actingAs($user);

        return $user;
    }

    // --------------------------------------------- penempatan saat create

    public function test_the_first_record_of_a_scope_is_placed_at_position_one(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        Livewire::test(CreateProvince::class)
            ->fillForm(['name' => 'Provinsi Satu', 'slug' => 'provinsi-satu'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, (int) Province::query()->firstOrFail()->sort_order);
    }

    public function test_each_new_record_is_appended_to_the_end(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        foreach (['satu', 'dua', 'tiga'] as $slug) {
            Livewire::test(CreateProvince::class)
                ->fillForm(['name' => 'Provinsi '.$slug, 'slug' => 'provinsi-'.$slug])
                ->call('create')
                ->assertHasNoFormErrors();
        }

        $this->assertSame(
            [1, 2, 3],
            Province::query()->orderBy('id')->pluck('sort_order')->map(fn ($v) => (int) $v)->all(),
        );
    }

    public function test_group_order_is_separate_for_each_province(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $first = Province::factory()->create();
        $second = Province::factory()->create();

        foreach ([$first, $first, $second] as $index => $province) {
            Livewire::test(CreateLocationGroup::class)
                ->fillForm([
                    'province_id' => $province->getKey(),
                    'name' => 'Grup '.$index,
                    'slug' => 'grup-'.$index,
                    'type' => 'administrative',
                ])
                ->call('create')
                ->assertHasNoFormErrors();
        }

        $this->assertSame(
            [1, 2],
            LocationGroup::query()->where('province_id', $first->id)
                ->orderBy('id')->pluck('sort_order')->map(fn ($v) => (int) $v)->all(),
        );

        // Provinsi kedua memulai hitungannya sendiri dari 1.
        $this->assertSame(
            [1],
            LocationGroup::query()->where('province_id', $second->id)
                ->orderBy('id')->pluck('sort_order')->map(fn ($v) => (int) $v)->all(),
        );
    }

    public function test_area_order_is_separate_for_each_group(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $first = LocationGroup::factory()->create();
        $second = LocationGroup::factory()->create();

        foreach ([$first, $first, $second] as $index => $group) {
            Livewire::test(CreateLocationArea::class)
                ->fillForm([
                    'province_id' => $group->province_id,
                    'location_group_id' => $group->getKey(),
                    'name' => 'Area '.$index,
                    'slug' => 'area-'.$index,
                ])
                ->call('create')
                ->assertHasNoFormErrors();
        }

        $this->assertSame([1, 2], $this->positions(LocationArea::class, 'location_group_id', $first->id));
        $this->assertSame([1], $this->positions(LocationArea::class, 'location_group_id', $second->id));
    }

    public function test_location_order_is_separate_for_each_area(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $first = LocationArea::factory()->create();
        $second = LocationArea::factory()->create();

        foreach ([$first, $first, $second] as $index => $area) {
            Livewire::test(CreateLocation::class)
                ->fillForm([
                    'location_area_id' => $area->getKey(),
                    'name' => 'Gerobak '.$index,
                    'full_address' => 'Alamat uji '.$index,
                    'images' => [],
                ])
                ->call('create')
                ->assertHasNoFormErrors();
        }

        $this->assertSame([1, 2], $this->positions(Location::class, 'location_area_id', $first->id));
        $this->assertSame([1], $this->positions(Location::class, 'location_area_id', $second->id));
    }

    public function test_a_sort_order_sent_in_the_request_is_never_trusted(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        Province::factory()->create(['sort_order' => 1]);

        // Nilai 999 dikirim seolah-olah dari form yang dimanipulasi.
        Livewire::test(CreateProvince::class)
            ->fillForm(['name' => 'Provinsi Curang', 'sort_order' => 999])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = Province::query()->where('name', 'Provinsi Curang')->firstOrFail();

        $this->assertSame(2, (int) $created->sort_order, 'Posisi harus dihitung server, bukan diambil dari request.');
    }

    public function test_the_ordering_field_is_gone_from_every_create_form(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $group = LocationGroup::factory()->create();
        $area = LocationArea::factory()->create();

        $forms = [
            Livewire::test(CreateProvince::class)->html(),
            Livewire::test(CreateLocationGroup::class)->html(),
            Livewire::test(CreateLocationArea::class)->html(),
            Livewire::test(CreateLocation::class)->html(),
        ];

        foreach ($forms as $index => $html) {
            $this->assertStringNotContainsString(
                'data.sort_order',
                $html,
                "Form ke-{$index} masih meminta nomor urut secara manual."
            );
        }

        // Kolomnya tetap ada di database.
        $this->assertNotNull($group->sort_order);
        $this->assertNotNull($area->sort_order);
    }

    // ------------------------------------------------------------ reorder

    public function test_dragging_rows_saves_the_new_order_and_normalises_it(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $group = LocationGroup::factory()->create();
        $areas = collect([1, 2, 3])->map(fn (int $i) => LocationArea::factory()
            ->for($group, 'group')
            ->create(['sort_order' => $i * 10, 'name' => 'Area '.$i]));

        Livewire::test(ListLocationAreas::class)
            ->set('tableFilters.location_group_id.value', $group->getKey())
            ->call('reorderTable', [
                (string) $areas[2]->getKey(),
                (string) $areas[0]->getKey(),
                (string) $areas[1]->getKey(),
            ]);

        $this->assertSame(1, (int) $areas[2]->fresh()->sort_order);
        $this->assertSame(2, (int) $areas[0]->fresh()->sort_order);
        $this->assertSame(3, (int) $areas[1]->fresh()->sort_order);

        // Berurutan, tanpa nol, negatif, atau duplikat.
        $all = $this->positions(LocationArea::class, 'location_group_id', $group->id);
        $this->assertSame([1, 2, 3], $all);
    }

    public function test_reordering_invalidates_the_public_cache(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $group = LocationGroup::factory()->create();
        $a = LocationArea::factory()->for($group, 'group')->create(['sort_order' => 1]);
        $b = LocationArea::factory()->for($group, 'group')->create(['sort_order' => 2]);

        $before = LocationPageCatalogService::cacheVersion();

        Livewire::test(ListLocationAreas::class)
            ->set('tableFilters.location_group_id.value', $group->getKey())
            ->call('reorderTable', [(string) $b->getKey(), (string) $a->getKey()]);

        $this->assertGreaterThan(
            $before,
            LocationPageCatalogService::cacheVersion(),
            'Versi cache harus naik supaya halaman publik memakai urutan baru.'
        );
    }

    public function test_the_public_page_follows_the_new_order(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $province = Province::factory()->create();
        $group = LocationGroup::factory()->for($province, 'province')->create();

        $first = LocationArea::factory()->for($group, 'group')
            ->create(['name' => 'Area Awal', 'sort_order' => 1]);
        $second = LocationArea::factory()->for($group, 'group')
            ->create(['name' => 'Area Kedua', 'sort_order' => 2]);

        $awal = Location::factory()->for($first, 'area')->create(['name' => 'Gerobak Awal']);
        $kedua = Location::factory()->for($second, 'area')->create(['name' => 'Gerobak Kedua']);

        // Halaman publik menampilkan kedua gerobak, dikelompokkan per Area.
        $page = LocationPage::factory()->published()->create();
        $page->groups()->attach($group);
        $page->locations()->attach([$awal->getKey(), $kedua->getKey()]);

        $url = route('location-pages.show', $page->slug);

        $content = $this->get($url)->getContent();
        $this->assertLessThan(strpos($content, 'Gerobak Kedua'), strpos($content, 'Gerobak Awal'));

        Livewire::test(ListLocationAreas::class)
            ->set('tableFilters.location_group_id.value', $group->getKey())
            ->call('reorderTable', [(string) $second->getKey(), (string) $first->getKey()]);

        $content = $this->get($url)->getContent();
        $this->assertLessThan(
            strpos($content, 'Gerobak Awal'),
            strpos($content, 'Gerobak Kedua'),
            'Halaman publik harus mengikuti urutan Area yang baru.'
        );
    }

    public function test_ids_belonging_to_another_parent_are_never_reordered(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $mine = LocationGroup::factory()->create();
        $other = LocationGroup::factory()->create();

        $a = LocationArea::factory()->for($mine, 'group')->create(['sort_order' => 1]);
        $b = LocationArea::factory()->for($mine, 'group')->create(['sort_order' => 2]);
        $intruder = LocationArea::factory()->for($other, 'group')->create(['sort_order' => 7]);

        Livewire::test(ListLocationAreas::class)
            ->set('tableFilters.location_group_id.value', $mine->getKey())
            // ID milik grup lain disisipkan ke payload.
            ->call('reorderTable', [
                (string) $intruder->getKey(),
                (string) $b->getKey(),
                (string) $a->getKey(),
            ]);

        $this->assertSame(
            7,
            (int) $intruder->fresh()->sort_order,
            'Record dari induk lain tidak boleh tersentuh reorder.'
        );

        // Grup sendiri tetap tertata benar meski payload disusupi.
        $this->assertSame([1, 2], $this->positions(LocationArea::class, 'location_group_id', $mine->id));
    }

    public function test_reorder_is_closed_until_a_single_parent_is_selected(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $group = LocationGroup::factory()->create();
        $a = LocationArea::factory()->for($group, 'group')->create(['sort_order' => 1]);
        $b = LocationArea::factory()->for($group, 'group')->create(['sort_order' => 2]);

        // Tanpa filter induk: seluruh Area lintas grup tercampur.
        Livewire::test(ListLocationAreas::class)
            ->call('reorderTable', [(string) $b->getKey(), (string) $a->getKey()]);

        $this->assertSame(1, (int) $a->fresh()->sort_order, 'Reorder tanpa induk terpilih harus diabaikan.');
        $this->assertSame(2, (int) $b->fresh()->sort_order);
    }

    public function test_an_operator_cannot_reorder_the_hierarchy(): void
    {
        $this->actingAsRole(UserRole::Operator);

        $province = Province::factory()->create(['sort_order' => 1]);
        $second = Province::factory()->create(['sort_order' => 2]);

        Livewire::test(ListProvinces::class)
            ->call('reorderTable', [(string) $second->getKey(), (string) $province->getKey()]);

        $this->assertSame(1, (int) $province->fresh()->sort_order, 'Operator tidak boleh menata provinsi.');

        $group = LocationGroup::factory()->create(['sort_order' => 1]);
        $otherGroup = LocationGroup::factory()->create([
            'province_id' => $group->province_id,
            'sort_order' => 2,
        ]);

        Livewire::test(ListLocationGroups::class)
            ->set('tableFilters.province_id.value', $group->province_id)
            ->call('reorderTable', [(string) $otherGroup->getKey(), (string) $group->getKey()]);

        $this->assertSame(1, (int) $group->fresh()->sort_order, 'Operator tidak boleh menata Kota/Grup.');
    }

    public function test_an_operator_may_reorder_carts_inside_one_area(): void
    {
        $this->actingAsRole(UserRole::Operator);

        $area = LocationArea::factory()->create();
        $a = Location::factory()->for($area, 'area')->create(['sort_order' => 1]);
        $b = Location::factory()->for($area, 'area')->create(['sort_order' => 2]);

        Livewire::test(ListLocations::class)
            ->set('tableFilters.location_area_id.value', $area->getKey())
            ->call('reorderTable', [(string) $b->getKey(), (string) $a->getKey()]);

        $this->assertSame(1, (int) $b->fresh()->sort_order, 'Operator boleh menata gerobak di dalam satu Area.');
        $this->assertSame(2, (int) $a->fresh()->sort_order);
    }

    public function test_an_inactive_account_cannot_reorder(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $user->assignRole(UserRole::SuperAdmin->value);
        $this->actingAs($user);

        $group = LocationGroup::factory()->create();
        $a = LocationArea::factory()->for($group, 'group')->create(['sort_order' => 1]);
        $b = LocationArea::factory()->for($group, 'group')->create(['sort_order' => 2]);

        // Akun nonaktif ditolak Gate::before sebelum komponen sempat mount,
        // jadi jalur reorder tidak pernah terbuka sama sekali.
        $this->get('/admin/area')->assertForbidden();

        $this->assertSame(1, (int) $a->fresh()->sort_order, 'Akun nonaktif tidak boleh menata urutan.');
        $this->assertSame(2, (int) $b->fresh()->sort_order);
    }

    // ----------------------------------------------------- pindah induk

    public function test_moving_to_another_parent_appends_to_the_end_and_normalises_both_scopes(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $province = Province::factory()->create();
        $origin = LocationGroup::factory()->for($province, 'province')->create();
        $target = LocationGroup::factory()->for($province, 'province')->create();

        $stayA = LocationArea::factory()->for($origin, 'group')->create(['sort_order' => 1]);
        $mover = LocationArea::factory()->for($origin, 'group')->create(['sort_order' => 2]);
        $stayB = LocationArea::factory()->for($origin, 'group')->create(['sort_order' => 3]);

        LocationArea::factory()->for($target, 'group')->create(['sort_order' => 1]);

        app(LocationOrderingService::class)->moveToScope(
            tap($mover, fn (LocationArea $a) => $a->location_group_id = $target->getKey()),
            ['location_group_id' => $origin->getKey()],
            ['location_group_id' => $target->getKey()],
        );

        // Ditempatkan di akhir grup tujuan.
        $this->assertSame(2, (int) $mover->fresh()->sort_order);

        // Scope lama rapat kembali: 1, 2 (bukan 1, 3).
        $this->assertSame([1, 2], $this->positions(LocationArea::class, 'location_group_id', $origin->id));
        $this->assertSame(1, (int) $stayA->fresh()->sort_order);
        $this->assertSame(2, (int) $stayB->fresh()->sort_order);

        // Scope baru juga berurutan.
        $this->assertSame([1, 2], $this->positions(LocationArea::class, 'location_group_id', $target->id));
    }

    public function test_keeping_the_same_parent_keeps_the_position(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $group = LocationGroup::factory()->create();
        LocationArea::factory()->for($group, 'group')->create(['sort_order' => 1]);
        $area = LocationArea::factory()->for($group, 'group')->create(['sort_order' => 2, 'name' => 'Tetap']);

        Livewire::test(EditLocationArea::class, ['record' => $area->getRouteKey()])
            ->fillForm(['name' => 'Nama Baru'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(2, (int) $area->fresh()->sort_order, 'Induk tidak berubah, posisi harus tetap.');
    }

    /**
     * Aturan perpindahan parent kini soal HALAMAN, bukan URL.
     *
     * Memindahkan Area keluar dari cakupan halaman yang memakai gerobaknya
     * akan mengurangi isi landing page diam-diam, jadi ditolak -- termasuk
     * lewat jalur form yang juga mengatur urutan.
     */
    public function test_an_area_used_by_a_page_cannot_move_out_of_its_scope(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $origin = LocationGroup::factory()->create();
        $area = LocationArea::factory()->for($origin, 'group')->create();
        $location = Location::factory()->for($area, 'area')->create();

        $page = LocationPage::factory()->create();
        $page->groups()->attach($origin);
        $page->locations()->attach($location);

        $elsewhere = LocationGroup::factory()->create();

        Livewire::test(EditLocationArea::class, ['record' => $area->getRouteKey()])
            ->fillForm([
                'province_id' => $elsewhere->province_id,
                'location_group_id' => $elsewhere->getKey(),
            ])
            ->call('save')
            ->assertHasFormErrors(['location_group_id']);

        $this->assertSame(
            (int) $origin->getKey(),
            (int) $area->fresh()->location_group_id,
            'Area yang dipakai halaman tidak boleh keluar dari cakupannya.'
        );
    }

    // ------------------------------------------------------------ helper

    /**
     * @param  class-string  $modelClass
     * @return list<int>
     */
    protected function positions(string $modelClass, string $column, int $parentId): array
    {
        return $modelClass::query()
            ->where($column, $parentId)
            ->orderBy('sort_order')
            ->pluck('sort_order')
            ->map(fn ($v): int => (int) $v)
            ->all();
    }
}
