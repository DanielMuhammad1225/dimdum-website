<?php

namespace Tests\Feature\Locations;

use App\Enums\LocationGroupType;
use App\Models\Location;
use App\Models\LocationArea;
use App\Models\LocationGroup;
use App\Models\LocationPage;
use App\Models\Province;
use App\Services\LocationHierarchyService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Hierarki empat tingkat: Provinsi -> Kota/Grup -> Area -> Gerobak.
 *
 * Sejak halaman slug dipisahkan, hierarki tidak lagi menentukan URL. Yang
 * dijaga di sini adalah keutuhan strukturnya: setiap tingkat punya tepat satu
 * induk, tidak ada FK yang disimpan ganda, induk tidak bisa dihapus selama
 * masih punya anak, dan perpindahan parent tidak boleh merusak Halaman Slug
 * Lokasi yang memakainya.
 */
class LocationHierarchyTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------- schema

    public function test_hierarchy_tables_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('provinces'));
        $this->assertTrue(Schema::hasTable('location_groups'));

        $this->assertTrue(Schema::hasColumn('location_groups', 'province_id'));
        $this->assertTrue(Schema::hasColumn('location_areas', 'location_group_id'));
        $this->assertTrue(Schema::hasColumn('locations', 'location_area_id'));
    }

    public function test_no_redundant_parent_foreign_key_is_stored(): void
    {
        // Provinsi diturunkan lewat rantai, tidak pernah disimpan ulang.
        $this->assertFalse(Schema::hasColumn('location_areas', 'province_id'));
        $this->assertFalse(Schema::hasColumn('locations', 'province_id'));
        $this->assertFalse(Schema::hasColumn('locations', 'location_group_id'));
    }

    public function test_area_requires_a_group(): void
    {
        $this->expectException(QueryException::class);

        LocationArea::query()->create(['name' => 'Tanpa Induk']);
    }

    public function test_group_requires_a_province(): void
    {
        $this->expectException(QueryException::class);

        LocationGroup::query()->create([
            'name' => 'Tanpa Provinsi',
            'type' => LocationGroupType::Administrative,
        ]);
    }

    public function test_deleting_a_parent_is_restricted_while_children_remain(): void
    {
        $province = Province::factory()->create();
        LocationGroup::factory()->for($province, 'province')->create();

        $this->expectException(QueryException::class);

        $province->forceDelete();
    }

    public function test_group_type_is_cast_to_enum(): void
    {
        $group = LocationGroup::factory()->marketingGroup()->create();

        $this->assertInstanceOf(LocationGroupType::class, $group->fresh()->type);
        $this->assertSame(LocationGroupType::MarketingGroup, $group->fresh()->type);
    }

    public function test_soft_delete_is_available_on_every_hierarchy_level(): void
    {
        foreach (['provinces', 'location_groups', 'location_areas', 'locations'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'deleted_at'), "Tabel {$table} tidak punya soft delete.");
        }
    }

    // ----------------------------------------------------------------- relasi

    public function test_province_is_reachable_from_every_level(): void
    {
        $province = Province::factory()->create();
        $group = LocationGroup::factory()->for($province, 'province')->create();
        $area = LocationArea::factory()->for($group, 'group')->create();
        $location = Location::factory()->for($area, 'area')->create();

        $area->loadMissing('group.province');
        $location->loadMissing('area.group.province');

        $this->assertTrue($area->parentProvince()->is($province));
        $this->assertTrue($location->parentProvince()->is($province));
    }

    public function test_province_area_relation_reaches_through_groups(): void
    {
        $province = Province::factory()->create();
        $groupOne = LocationGroup::factory()->for($province, 'province')->create();
        $groupTwo = LocationGroup::factory()->for($province, 'province')->create();

        $first = LocationArea::factory()->for($groupOne, 'group')->create();
        $second = LocationArea::factory()->for($groupTwo, 'group')->create();
        $foreign = LocationArea::factory()->create();

        $ids = $province->areas()->pluck('location_areas.id')->all();

        $this->assertEqualsCanonicalizing([$first->id, $second->id], $ids);
        $this->assertNotContains($foreign->id, $ids);
    }

    public function test_group_belongs_to_province_check(): void
    {
        $province = Province::factory()->create();
        $other = Province::factory()->create();
        $group = LocationGroup::factory()->for($province, 'province')->create();

        $service = app(LocationHierarchyService::class);

        $this->assertTrue($service->groupBelongsToProvince($group->getKey(), $province->getKey()));
        $this->assertFalse($service->groupBelongsToProvince($group->getKey(), $other->getKey()));
        $this->assertFalse($service->groupBelongsToProvince(null, $province->getKey()));
        $this->assertSame($province->getKey(), $service->provinceIdForGroup($group->getKey()));
    }

    // ------------------------------------------------- perpindahan parent

    /**
     * Perpindahan yang tidak menyentuh halaman mana pun selalu boleh: hierarki
     * tidak lagi menentukan URL.
     */
    public function test_an_area_may_move_freely_when_no_page_uses_it(): void
    {
        $area = LocationArea::factory()->create();
        $target = LocationGroup::factory()->create();

        $this->assertNull(
            app(LocationHierarchyService::class)->rejectionReasonForAreaMove($area, $target->getKey()),
        );
    }

    public function test_an_area_cannot_move_out_of_the_scope_of_a_page_that_uses_it(): void
    {
        $group = LocationGroup::factory()->create();
        $area = LocationArea::factory()->for($group, 'group')->create();
        $location = Location::factory()->for($area, 'area')->create();

        $page = LocationPage::factory()->create(['title' => 'Alamat Cianjur']);
        $page->groups()->attach($group);
        $page->locations()->attach($location);

        $outsider = LocationGroup::factory()->create();

        $reason = app(LocationHierarchyService::class)
            ->rejectionReasonForAreaMove($area->fresh(), $outsider->getKey());

        $this->assertNotNull($reason, 'Perpindahan yang merusak halaman seharusnya ditolak.');
        $this->assertStringContainsString('Alamat Cianjur', $reason);
    }

    public function test_an_area_may_move_into_a_group_that_the_page_already_covers(): void
    {
        $group = LocationGroup::factory()->create();
        $sibling = LocationGroup::factory()->create();

        $area = LocationArea::factory()->for($group, 'group')->create();
        $location = Location::factory()->for($area, 'area')->create();

        $page = LocationPage::factory()->create();
        // Kedua grup ada di cakupan, jadi perpindahan tidak merusak apa pun.
        $page->groups()->attach([$group->getKey(), $sibling->getKey()]);
        $page->locations()->attach($location);

        $this->assertNull(
            app(LocationHierarchyService::class)
                ->rejectionReasonForAreaMove($area->fresh(), $sibling->getKey()),
        );
    }

    public function test_a_location_cannot_move_out_of_the_scope_of_a_page_that_uses_it(): void
    {
        $group = LocationGroup::factory()->create();
        $area = LocationArea::factory()->for($group, 'group')->create();
        $location = Location::factory()->for($area, 'area')->create();

        $page = LocationPage::factory()->create(['title' => 'Alamat Bandung']);
        $page->groups()->attach($group);
        $page->locations()->attach($location);

        $foreignArea = LocationArea::factory()->create();

        $reason = app(LocationHierarchyService::class)
            ->rejectionReasonForLocationMove($location->fresh(), $foreignArea->getKey());

        $this->assertNotNull($reason);
        $this->assertStringContainsString('Alamat Bandung', $reason);
    }

    public function test_a_location_may_move_within_the_same_group(): void
    {
        $group = LocationGroup::factory()->create();
        $area = LocationArea::factory()->for($group, 'group')->create();
        $sibling = LocationArea::factory()->for($group, 'group')->create();
        $location = Location::factory()->for($area, 'area')->create();

        $page = LocationPage::factory()->create();
        $page->groups()->attach($group);
        $page->locations()->attach($location);

        $this->assertNull(
            app(LocationHierarchyService::class)
                ->rejectionReasonForLocationMove($location->fresh(), $sibling->getKey()),
        );
    }

    public function test_a_parentless_area_is_rejected_by_the_service(): void
    {
        $this->assertNotNull(
            app(LocationHierarchyService::class)->rejectionReasonForAreaMove(new LocationArea, null),
        );
    }

    // ------------------------------------------------ cascade visibilitas

    public function test_an_inactive_ancestor_hides_every_descendant(): void
    {
        $province = Province::factory()->create();
        $group = LocationGroup::factory()->for($province, 'province')->create();
        $area = LocationArea::factory()->for($group, 'group')->create();
        Location::factory()->for($area, 'area')->create();

        $this->assertSame(1, Location::query()->effectivelyVisible()->count());

        foreach ([$province, $group, $area] as $ancestor) {
            $ancestor->forceFill(['is_active' => false])->save();

            $this->assertSame(
                0,
                Location::query()->effectivelyVisible()->count(),
                get_class($ancestor).' nonaktif seharusnya menyembunyikan gerobak di bawahnya.',
            );

            $ancestor->forceFill(['is_active' => true])->save();
        }
    }

    public function test_a_soft_deleted_group_hides_its_areas(): void
    {
        $group = LocationGroup::factory()->create();
        $area = LocationArea::factory()->for($group, 'group')->create();
        Location::factory()->for($area, 'area')->create();

        $group->delete();

        $this->assertSame(0, Location::query()->effectivelyVisible()->count());
        $this->assertSame(0, LocationArea::query()->effectivelyVisible()->count());
    }

    public function test_a_fully_active_chain_is_visible(): void
    {
        $province = Province::factory()->create();
        $group = LocationGroup::factory()->for($province, 'province')->create();
        $area = LocationArea::factory()->for($group, 'group')->create();
        Location::factory()->for($area, 'area')->create();

        $this->assertSame(1, LocationGroup::query()->effectivelyVisible()->count());
        $this->assertSame(1, LocationArea::query()->effectivelyVisible()->count());
        $this->assertSame(1, Location::query()->effectivelyVisible()->count());
    }
}
