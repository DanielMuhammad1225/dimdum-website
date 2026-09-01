<?php

namespace Tests\Feature\Locations;

use App\Enums\LocationGroupType;
use App\Models\Location;
use App\Models\LocationArea;
use App\Models\LocationGroup;
use App\Models\Province;
use App\Services\LocationHierarchyService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Hierarki empat tingkat: Province -> LocationGroup -> LocationArea -> Location.
 *
 * Fokusnya bukan tampilan, melainkan aturan yang HARUS ditegakkan database dan
 * server: induk wajib ada, kombinasi parent-child tidak boleh ngawur, dan
 * perpindahan yang mematikan URL iklan harus ditolak.
 */
class LocationHierarchyTest extends TestCase
{
    use RefreshDatabase;

    // --------------------------------------------------------------- skema

    public function test_hierarchy_tables_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('provinces', [
            'id', 'name', 'slug', 'description', 'seo_title', 'seo_description',
            'is_active', 'published_at', 'sort_order',
            'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at',
        ]));

        $this->assertTrue(Schema::hasColumns('location_groups', [
            'id', 'province_id', 'name', 'slug', 'type', 'description',
            'is_active', 'sort_order',
            'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at',
        ]));

        // Kota/Grup tidak punya halaman publik, jadi tidak butuh jadwal terbit.
        $this->assertFalse(Schema::hasColumn('location_groups', 'published_at'));

        $this->assertTrue(Schema::hasColumns('location_province_slug_redirects', [
            'id', 'province_id', 'old_slug', 'created_by', 'created_at', 'updated_at',
        ]));
    }

    public function test_province_slug_is_globally_unique(): void
    {
        Province::factory()->create(['slug' => 'jawa-barat']);

        $this->expectException(QueryException::class);

        Province::factory()->create(['slug' => 'jawa-barat']);
    }

    public function test_group_slug_is_unique_only_within_its_province(): void
    {
        $first = Province::factory()->create();
        $second = Province::factory()->create();

        LocationGroup::factory()->for($first, 'province')->create(['slug' => 'kota-a']);

        // Slug yang sama di provinsi LAIN sah.
        $other = LocationGroup::factory()->for($second, 'province')->create(['slug' => 'kota-a']);
        $this->assertSame('kota-a', $other->slug);

        $this->expectException(QueryException::class);

        LocationGroup::factory()->for($first, 'province')->create(['slug' => 'kota-a']);
    }

    public function test_area_requires_a_group(): void
    {
        $this->expectException(QueryException::class);

        LocationArea::factory()->create(['location_group_id' => null]);
    }

    public function test_group_requires_a_province(): void
    {
        $this->expectException(QueryException::class);

        LocationGroup::factory()->create(['province_id' => null]);
    }

    public function test_deleting_a_parent_is_restricted_while_children_remain(): void
    {
        $group = LocationGroup::factory()->create();
        LocationArea::factory()->for($group, 'group')->create();

        // Soft delete tetap boleh -- yang dijaga database adalah hard delete.
        $this->expectException(QueryException::class);

        LocationGroup::withoutEvents(fn () => LocationGroup::query()
            ->whereKey($group->getKey())
            ->forceDelete());
    }

    public function test_group_type_is_cast_to_enum(): void
    {
        $group = LocationGroup::factory()->marketingGroup()->create();

        $this->assertInstanceOf(LocationGroupType::class, $group->refresh()->type);
        $this->assertSame(LocationGroupType::MarketingGroup, $group->type);
        $this->assertSame('Grup Wilayah/Pemasaran', $group->typeLabel());
    }

    public function test_soft_delete_is_available_on_every_hierarchy_level(): void
    {
        foreach (['provinces', 'location_groups', 'location_areas', 'locations'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'deleted_at'), "{$table} tidak punya soft delete.");
        }
    }

    // ----------------------------------------------------------- relationship

    public function test_province_is_reachable_from_every_level(): void
    {
        $province = Province::factory()->create(['name' => 'Jawa Barat']);
        $group = LocationGroup::factory()->for($province, 'province')->create();
        $area = LocationArea::factory()->for($group, 'group')->create();
        $location = Location::factory()->for($area, 'area')->create();

        $area->load('group.province');
        $location->load('area.group.province');

        $this->assertSame('Jawa Barat', $area->parentProvince()?->name);
        $this->assertSame('Jawa Barat', $location->parentProvince()?->name);
        $this->assertSame($group->id, $location->parentGroup()?->id);
    }

    public function test_no_redundant_province_foreign_key_is_stored(): void
    {
        // Provinsi HARUS diturunkan, tidak disimpan ulang di Area maupun gerobak.
        $this->assertFalse(Schema::hasColumn('location_areas', 'province_id'));
        $this->assertFalse(Schema::hasColumn('locations', 'province_id'));
        $this->assertFalse(Schema::hasColumn('locations', 'location_group_id'));
    }

    public function test_province_area_relation_reaches_through_groups(): void
    {
        $province = Province::factory()->create();
        $group = LocationGroup::factory()->for($province, 'province')->create();
        LocationArea::factory()->count(3)->for($group, 'group')->create();

        // Area di provinsi lain tidak boleh ikut terhitung.
        LocationArea::factory()->create();

        $this->assertSame(3, $province->areas()->count());
    }

    // ------------------------------------------- validasi parent-child server

    public function test_group_belongs_to_province_check(): void
    {
        $hierarchy = app(LocationHierarchyService::class);

        $province = Province::factory()->create();
        $otherProvince = Province::factory()->create();
        $group = LocationGroup::factory()->for($province, 'province')->create();

        $this->assertTrue($hierarchy->groupBelongsToProvince($group->id, $province->id));
        $this->assertFalse($hierarchy->groupBelongsToProvince($group->id, $otherProvince->id));
        $this->assertFalse($hierarchy->groupBelongsToProvince(null, $province->id));
        $this->assertFalse($hierarchy->groupBelongsToProvince($group->id, null));
        $this->assertFalse($hierarchy->groupBelongsToProvince(999999, $province->id));
    }

    public function test_published_area_cannot_move_to_another_province(): void
    {
        $hierarchy = app(LocationHierarchyService::class);

        $origin = LocationGroup::factory()->create();
        $area = LocationArea::factory()->for($origin, 'group')->published()->create();

        $elsewhere = LocationGroup::factory()->create();

        $reason = $hierarchy->rejectionReasonForAreaMove($area, $elsewhere->id);

        $this->assertNotNull($reason, 'Perpindahan lintas provinsi seharusnya ditolak.');
        $this->assertStringContainsString('provinsi lain', $reason);
    }

    public function test_draft_area_may_move_across_provinces(): void
    {
        $hierarchy = app(LocationHierarchyService::class);

        $area = LocationArea::factory()->create(); // draft: published_at null
        $elsewhere = LocationGroup::factory()->create();

        $this->assertNull($hierarchy->rejectionReasonForAreaMove($area, $elsewhere->id));
    }

    public function test_published_area_may_move_between_groups_of_the_same_province(): void
    {
        $hierarchy = app(LocationHierarchyService::class);

        $province = Province::factory()->published()->create();
        $origin = LocationGroup::factory()->for($province, 'province')->create();
        $target = LocationGroup::factory()->for($province, 'province')->create();

        $area = LocationArea::factory()->for($origin, 'group')->published()->create();

        $urlBefore = route('locations.area', [$province->slug, $area->slug]);

        $this->assertNull(
            $hierarchy->rejectionReasonForAreaMove($area, $target->id),
            'Pindah antargrup di provinsi yang sama tidak mengubah URL, jadi harus diizinkan.'
        );

        $area->update(['location_group_id' => $target->id]);
        $area->refresh()->load('group.province');

        $urlAfter = route('locations.area', [$area->parentProvince()->slug, $area->slug]);

        $this->assertSame($urlBefore, $urlAfter, 'URL tidak boleh berubah.');
    }

    public function test_area_without_a_group_is_rejected_by_the_service(): void
    {
        $hierarchy = app(LocationHierarchyService::class);

        $this->assertNotNull($hierarchy->rejectionReasonForAreaMove(new LocationArea, null));
    }

    // --------------------------------------------------- cascade visibilitas

    public function test_inactive_province_hides_every_descendant(): void
    {
        $province = Province::factory()->inactive()->create();
        $group = LocationGroup::factory()->for($province, 'province')->create();
        $area = LocationArea::factory()->for($group, 'group')->published()->create();
        $location = Location::factory()->for($area, 'area')->published()->create();

        $this->assertTrue($area->isPubliclyVisible(), 'Areanya sendiri terbit.');
        $this->assertFalse($area->isEffectivelyVisible(), 'Tetapi provinsinya nonaktif.');
        $this->assertFalse($location->isEffectivelyVisible());

        $this->assertSame(0, LocationArea::query()->effectivelyVisible()->count());
        $this->assertSame(0, Location::query()->effectivelyVisible()->count());
    }

    public function test_draft_province_hides_every_descendant(): void
    {
        $province = Province::factory()->create(); // published_at null
        $group = LocationGroup::factory()->for($province, 'province')->create();
        $area = LocationArea::factory()->for($group, 'group')->published()->create();
        Location::factory()->for($area, 'area')->published()->create();

        $this->assertSame(0, LocationArea::query()->effectivelyVisible()->count());
        $this->assertSame(0, Location::query()->effectivelyVisible()->count());
    }

    public function test_scheduled_province_hides_every_descendant_until_its_time(): void
    {
        $province = Province::factory()->scheduled()->create();
        $group = LocationGroup::factory()->for($province, 'province')->create();
        $area = LocationArea::factory()->for($group, 'group')->published()->create();

        $this->assertSame(0, LocationArea::query()->effectivelyVisible()->count());
        $this->assertFalse($area->isEffectivelyVisible());
    }

    public function test_inactive_group_hides_its_areas_and_locations(): void
    {
        $group = LocationGroup::factory()->inactive()->create();
        $area = LocationArea::factory()->for($group, 'group')->published()->create();
        $location = Location::factory()->for($area, 'area')->published()->create();

        $this->assertTrue($area->isPubliclyVisible());
        $this->assertFalse($area->isEffectivelyVisible(), 'Grupnya nonaktif.');
        $this->assertFalse($location->isEffectivelyVisible());

        $this->assertSame(0, LocationArea::query()->effectivelyVisible()->count());
    }

    public function test_soft_deleted_group_hides_its_areas(): void
    {
        $group = LocationGroup::factory()->create();
        $area = LocationArea::factory()->for($group, 'group')->published()->create();
        Location::factory()->for($area, 'area')->published()->create();

        $this->assertSame(1, LocationArea::query()->effectivelyVisible()->count());

        $group->delete();

        $this->assertSame(0, LocationArea::query()->effectivelyVisible()->count());
        $this->assertSame(0, Location::query()->effectivelyVisible()->count());
    }

    public function test_a_fully_published_chain_is_visible(): void
    {
        $area = LocationArea::factory()->published()->create();
        $location = Location::factory()->for($area, 'area')->published()->create();

        $this->assertTrue($area->refresh()->load('group.province')->isEffectivelyVisible());
        $this->assertTrue($location->refresh()->load('area.group.province')->isEffectivelyVisible());

        $this->assertSame(1, LocationArea::query()->effectivelyVisible()->count());
        $this->assertSame(1, Location::query()->effectivelyVisible()->count());
    }

    public function test_province_only_counts_as_visible_when_it_has_a_visible_location(): void
    {
        // Provinsi terbit, grup aktif, tetapi areanya masih draft.
        $province = Province::factory()->published()->create();
        $group = LocationGroup::factory()->for($province, 'province')->create();
        LocationArea::factory()->for($group, 'group')->create();

        $this->assertSame(0, Province::query()->publiclyVisible()->hasVisibleAreas()->count());

        // Area terbit tetapi belum punya gerobak tampil -> tetap belum layak.
        $area = LocationArea::factory()->for($group, 'group')->published()->create();
        $this->assertSame(0, Province::query()->publiclyVisible()->hasVisibleAreas()->count());

        Location::factory()->for($area, 'area')->published()->create();
        $this->assertSame(1, Province::query()->publiclyVisible()->hasVisibleAreas()->count());
    }
}
