<?php

namespace Tests\Feature\Locations;

use App\Models\Location;
use App\Models\LocationArea;
use App\Models\LocationGroup;
use App\Models\LocationImage;
use App\Models\LocationPage;
use App\Models\Province;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Master data lokasi: schema, relasi, cast, dan visibilitas.
 *
 * Sejak halaman slug dipisahkan, keempat tabel hierarki adalah MASTER DATA
 * murni. Yang diuji di sini adalah konsekuensinya: tidak ada slug, tidak ada
 * SEO, tidak ada waktu terbit, dan visibilitas ditentukan semata oleh rantai
 * `is_active`.
 */
class LocationModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_location_tables_exist(): void
    {
        foreach ([
            'provinces',
            'location_groups',
            'location_areas',
            'locations',
            'location_images',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Tabel {$table} tidak ada.");
        }
    }

    /**
     * Tabel redirect hierarki sudah tidak relevan: Provinsi dan Area tidak
     * punya URL, jadi tidak ada URL yang bisa mati saat namanya berganti.
     */
    public function test_hierarchy_slug_redirect_tables_are_gone(): void
    {
        $this->assertFalse(Schema::hasTable('location_area_slug_redirects'));
        $this->assertFalse(Schema::hasTable('location_province_slug_redirects'));
    }

    public function test_master_tables_keep_only_operational_columns(): void
    {
        $expected = [
            'provinces' => ['id', 'name', 'is_active', 'sort_order', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at'],
            'location_groups' => ['id', 'province_id', 'name', 'type', 'is_active', 'sort_order', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at'],
            'location_areas' => ['id', 'location_group_id', 'name', 'is_active', 'sort_order', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at'],
        ];

        foreach ($expected as $table => $columns) {
            $this->assertEqualsCanonicalizing(
                $columns,
                Schema::getColumnListing($table),
                "Kolom tabel {$table} tidak sesuai.",
            );
        }
    }

    /**
     * Alamat gerobak adalah fakta pos dan TETAP tinggal di master data --
     * bukan pindah ke halaman slug.
     */
    public function test_location_keeps_every_address_and_map_column(): void
    {
        foreach ([
            'location_area_id', 'name', 'full_address', 'village', 'district',
            'city_regency', 'postal_code', 'landmark', 'operational_hours_text',
            'whatsapp_number', 'latitude', 'longitude', 'google_maps_url',
            'is_active', 'sort_order', 'created_by', 'updated_by', 'deleted_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('locations', $column), "Kolom locations.{$column} hilang.");
        }
    }

    public function test_no_master_table_owns_a_public_page_column(): void
    {
        $forbidden = ['slug', 'published_at', 'seo_title', 'seo_description', 'headline', 'description'];

        foreach (['provinces', 'location_groups', 'location_areas', 'locations'] as $table) {
            foreach ($forbidden as $column) {
                $this->assertFalse(
                    Schema::hasColumn($table, $column),
                    "Kolom halaman publik {$table}.{$column} seharusnya sudah dipindahkan ke location_pages.",
                );
            }
        }
    }

    public function test_image_columns_are_present(): void
    {
        foreach ([
            'location_id', 'image_path', 'alt_text', 'caption', 'sort_order',
            'is_cover', 'width', 'height', 'mime_type', 'size_bytes',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('location_images', $column), "Kolom {$column} hilang.");
        }
    }

    public function test_an_area_cannot_be_deleted_while_it_still_has_locations(): void
    {
        $area = LocationArea::factory()->create();
        Location::factory()->for($area, 'area')->create();

        $this->expectException(QueryException::class);

        // restrictOnDelete di tingkat database, bukan hanya policy aplikasi.
        $area->forceDelete();
    }

    public function test_a_group_cannot_be_deleted_while_it_still_has_areas(): void
    {
        $group = LocationGroup::factory()->create();
        LocationArea::factory()->for($group, 'group')->create();

        $this->expectException(QueryException::class);

        $group->forceDelete();
    }

    public function test_relationships_resolve(): void
    {
        $province = Province::factory()->create();
        $group = LocationGroup::factory()->for($province, 'province')->create();
        $area = LocationArea::factory()->for($group, 'group')->create();
        $location = Location::factory()->for($area, 'area')->create();
        LocationImage::factory()->for($location, 'location')->create();

        $this->assertTrue($province->groups->contains($group));
        $this->assertTrue($province->areas->contains($area));
        $this->assertTrue($group->areas->contains($area));
        $this->assertTrue($area->locations->contains($location));
        $this->assertSame(1, $location->images()->count());

        $location->loadMissing('area.group.province');
        $this->assertTrue($location->parentGroup()->is($group));
        $this->assertTrue($location->parentProvince()->is($province));
    }

    public function test_casts_are_applied(): void
    {
        $location = Location::factory()->create([
            'is_active' => 1,
            'latitude' => '-6.8123456',
            'longitude' => '107.1234567',
        ]);

        $fresh = $location->fresh();

        $this->assertIsBool($fresh->is_active);
        $this->assertIsInt($fresh->sort_order);
        // decimal:7 -> string, supaya presisi koordinat tidak dibulatkan float.
        $this->assertSame('-6.8123456', $fresh->latitude);
        $this->assertSame('107.1234567', $fresh->longitude);
    }

    // --------------------------------------------------------- visibilitas

    public function test_visibility_follows_the_whole_active_chain(): void
    {
        $province = Province::factory()->create();
        $group = LocationGroup::factory()->for($province, 'province')->create();
        $area = LocationArea::factory()->for($group, 'group')->create();
        $location = Location::factory()->for($area, 'area')->create();

        $this->assertTrue($location->fresh()->loadMissing('area.group.province')->isEffectivelyVisible());

        foreach ([
            'provinsi' => $province,
            'grup' => $group,
            'area' => $area,
        ] as $label => $model) {
            $model->forceFill(['is_active' => false])->save();

            $this->assertFalse(
                Location::query()->whereKey($location->getKey())->first()
                    ->loadMissing('area.group.province')->isEffectivelyVisible(),
                "Gerobak tetap terlihat padahal {$label} nonaktif.",
            );

            $model->forceFill(['is_active' => true])->save();
        }
    }

    public function test_the_effectively_visible_scope_matches_the_helper(): void
    {
        $visible = Location::factory()->create();
        $inactiveSelf = Location::factory()->inactive()->create();

        $hiddenArea = LocationArea::factory()->inactive()->create();
        $underHiddenArea = Location::factory()->for($hiddenArea, 'area')->create();

        $ids = Location::query()->effectivelyVisible()->pluck('id')->all();

        $this->assertContains($visible->id, $ids);
        $this->assertNotContains($inactiveSelf->id, $ids);
        $this->assertNotContains($underHiddenArea->id, $ids);
    }

    public function test_soft_deleted_records_are_never_visible(): void
    {
        $location = Location::factory()->create();
        $location->delete();

        $this->assertTrue($location->trashed());
        $this->assertFalse($location->isActiveLocation());
        $this->assertNotContains(
            $location->id,
            Location::query()->effectivelyVisible()->pluck('id')->all(),
        );
    }

    /**
     * Rumus kelayakan kandidat halaman slug, diuji pada scope-nya langsung.
     */
    public function test_the_in_groups_scope_matches_the_candidate_formula(): void
    {
        $groupA = LocationGroup::factory()->create();
        $groupB = LocationGroup::factory()->create();

        $areaOne = LocationArea::factory()->for($groupA, 'group')->create();
        $areaTwo = LocationArea::factory()->for($groupA, 'group')->create();
        $areaOther = LocationArea::factory()->for($groupB, 'group')->create();

        $first = Location::factory()->for($areaOne, 'area')->create();
        $second = Location::factory()->for($areaTwo, 'area')->create();
        $outside = Location::factory()->for($areaOther, 'area')->create();

        $ids = Location::query()->inGroups([$groupA->getKey()])->pluck('id')->all();

        // Satu Kota/Grup menarik gerobak dari SEMUA Area di bawahnya.
        $this->assertContains($first->id, $ids);
        $this->assertContains($second->id, $ids);
        $this->assertNotContains($outside->id, $ids);

        // Tanpa Kota/Grup, kandidatnya kosong -- bukan seluruh gerobak.
        $this->assertSame([], Location::query()->inGroups([])->pluck('id')->all());
    }

    // -------------------------------------------------------------- urutan

    public function test_ordering_uses_sort_order_then_name(): void
    {
        $area = LocationArea::factory()->create();

        $second = Location::factory()->for($area, 'area')->create(['name' => 'B', 'sort_order' => 1]);
        $first = Location::factory()->for($area, 'area')->create(['name' => 'A', 'sort_order' => 0]);
        $third = Location::factory()->for($area, 'area')->create(['name' => 'A', 'sort_order' => 2]);

        $this->assertSame(
            [$first->id, $second->id, $third->id],
            Location::query()->ordered()->pluck('id')->all(),
        );
    }

    public function test_images_put_the_cover_first(): void
    {
        $location = Location::factory()->create();

        LocationImage::factory()->for($location, 'location')->create(['is_cover' => false, 'sort_order' => 2]);
        $cover = LocationImage::factory()->for($location, 'location')->create(['is_cover' => true, 'sort_order' => 1]);

        $this->assertSame($cover->id, $location->images()->first()->id);
    }

    public function test_soft_delete_and_restore_keep_the_row(): void
    {
        $area = LocationArea::factory()->create();

        $area->delete();
        $this->assertSoftDeleted('location_areas', ['id' => $area->id]);

        $area->restore();
        $this->assertDatabaseHas('location_areas', ['id' => $area->id, 'deleted_at' => null]);
    }

    public function test_a_group_label_always_names_its_province(): void
    {
        $province = Province::factory()->create(['name' => 'Jawa Barat']);
        $group = LocationGroup::factory()->for($province, 'province')->create(['name' => 'Cianjur Selatan']);

        $this->assertSame('Jawa Barat — Cianjur Selatan', $group->loadMissing('province')->qualifiedName());
    }

    public function test_a_location_knows_which_pages_display_it(): void
    {
        $location = Location::factory()->create();
        $page = LocationPage::factory()->create();

        $page->locations()->attach($location);

        $this->assertTrue($location->fresh()->pages->contains($page));
    }
}
