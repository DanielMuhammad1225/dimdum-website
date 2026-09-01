<?php

namespace Tests\Feature\Locations;

use App\Models\Location;
use App\Models\LocationArea;
use App\Models\LocationAreaSlugRedirect;
use App\Models\LocationImage;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Skema, relasi, cast, dan kebijakan visibilitas modul lokasi.
 */
class LocationModelTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------- skema

    public function test_all_location_tables_exist(): void
    {
        foreach ([
            'location_areas',
            'locations',
            'location_images',
            'location_area_slug_redirects',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Tabel {$table} tidak ada.");
        }
    }

    public function test_area_columns_are_present(): void
    {
        $this->assertTrue(Schema::hasColumns('location_areas', [
            'id', 'name', 'slug', 'headline', 'description', 'province', 'city_regency',
            'seo_title', 'seo_description', 'is_active', 'published_at', 'sort_order',
            'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at',
        ]));
    }

    public function test_location_columns_are_present(): void
    {
        $this->assertTrue(Schema::hasColumns('locations', [
            'id', 'location_area_id', 'name', 'slug', 'filter_label', 'full_address',
            'village', 'district', 'city_regency', 'province', 'postal_code', 'landmark',
            'operational_hours_text', 'whatsapp_number', 'latitude', 'longitude',
            'google_maps_url', 'is_active', 'published_at', 'sort_order',
            'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at',
        ]));
    }

    public function test_image_columns_are_present(): void
    {
        $this->assertTrue(Schema::hasColumns('location_images', [
            'id', 'location_id', 'image_path', 'alt_text', 'caption', 'sort_order',
            'is_cover', 'width', 'height', 'mime_type', 'size_bytes', 'created_by',
            'created_at', 'updated_at', 'deleted_at',
        ]));
    }

    // --------------------------------------------------------- constraint

    public function test_area_slug_is_unique_at_database_level(): void
    {
        LocationArea::factory()->create(['slug' => 'cianjur']);

        $this->expectException(QueryException::class);

        LocationArea::factory()->create(['slug' => 'cianjur']);
    }

    public function test_location_slug_is_unique_within_an_area(): void
    {
        $area = LocationArea::factory()->create();

        Location::factory()->for($area, 'area')->create(['slug' => 'pasar-baru']);

        $this->expectException(QueryException::class);

        Location::factory()->for($area, 'area')->create(['slug' => 'pasar-baru']);
    }

    public function test_the_same_location_slug_may_repeat_in_another_area(): void
    {
        $first = LocationArea::factory()->create();
        $second = LocationArea::factory()->create();

        Location::factory()->for($first, 'area')->create(['slug' => 'pasar-baru']);
        Location::factory()->for($second, 'area')->create(['slug' => 'pasar-baru']);

        $this->assertSame(2, Location::query()->where('slug', 'pasar-baru')->count());
    }

    public function test_an_area_cannot_be_deleted_while_it_still_has_locations(): void
    {
        $area = LocationArea::factory()->create();
        Location::factory()->for($area, 'area')->create();

        $this->expectException(QueryException::class);

        // restrictOnDelete: database menolak, bukan hanya policy aplikasi.
        LocationArea::query()->whereKey($area->getKey())->forceDelete();
    }

    public function test_old_slug_is_unique_across_all_areas(): void
    {
        $first = LocationArea::factory()->create();
        $second = LocationArea::factory()->create();

        LocationAreaSlugRedirect::query()->create(['location_area_id' => $first->id, 'old_slug' => 'lama']);

        $this->expectException(QueryException::class);

        LocationAreaSlugRedirect::query()->create(['location_area_id' => $second->id, 'old_slug' => 'lama']);
    }

    // ------------------------------------------------------------- relasi

    public function test_relationships_resolve(): void
    {
        $area = LocationArea::factory()->create();
        $location = Location::factory()->for($area, 'area')->create();
        $image = LocationImage::factory()->for($location)->create();

        $this->assertTrue($area->locations()->whereKey($location->getKey())->exists());
        $this->assertTrue($area->images()->whereKey($image->getKey())->exists());
        $this->assertSame($area->getKey(), $location->area->getKey());
        $this->assertSame($location->getKey(), $image->location->getKey());
    }

    // -------------------------------------------------------------- casts

    public function test_casts_are_applied(): void
    {
        $area = LocationArea::factory()->published()->create();
        $location = Location::factory()->for($area, 'area')->published()->create([
            'latitude' => -6.8123456,
            'longitude' => 107.1234567,
        ]);
        $image = LocationImage::factory()->for($location)->cover()->create();

        $this->assertIsBool($area->is_active);
        $this->assertInstanceOf(Carbon::class, $area->published_at);
        $this->assertIsInt($area->sort_order);

        // decimal:7 -> string, presisi tidak hilang karena pembulatan float.
        $this->assertSame('-6.8123456', (string) $location->latitude);
        $this->assertSame('107.1234567', (string) $location->longitude);

        $this->assertIsBool($image->is_cover);
        $this->assertIsInt($image->width);
        $this->assertIsInt($image->size_bytes);
    }

    // --------------------------------------------------------- visibility

    public function test_area_visibility_rules(): void
    {
        $published = LocationArea::factory()->published()->create();
        $draft = LocationArea::factory()->create();
        $inactive = LocationArea::factory()->inactive()->create();
        $scheduled = LocationArea::factory()->scheduled()->create();

        $this->assertTrue($published->isPubliclyVisible());
        $this->assertFalse($draft->isPubliclyVisible(), 'published_at null = draft.');
        $this->assertFalse($inactive->isPubliclyVisible(), 'Nonaktif tidak boleh tampil.');
        $this->assertFalse($scheduled->isPubliclyVisible(), 'Terbit di masa depan belum boleh tampil.');

        $visible = LocationArea::query()->publiclyVisible()->pluck('id')->all();

        $this->assertSame([$published->id], $visible);
    }

    public function test_soft_deleted_area_is_not_visible(): void
    {
        $area = LocationArea::factory()->published()->create();
        $area->delete();

        $this->assertTrue($area->trashed());
        $this->assertFalse($area->isPubliclyVisible());
        $this->assertSame(0, LocationArea::query()->publiclyVisible()->count());
    }

    /**
     * Aturan inti: gerobak aktif di bawah wilayah nonaktif TIDAK boleh bocor.
     */
    public function test_location_under_a_hidden_area_is_not_effectively_visible(): void
    {
        $hiddenArea = LocationArea::factory()->inactive()->create();
        $location = Location::factory()->for($hiddenArea, 'area')->published()->create();

        $this->assertTrue($location->isPubliclyVisible(), 'Gerobaknya sendiri memang aktif dan terbit.');
        $this->assertFalse($location->isEffectivelyVisible(), 'Tetapi wilayahnya tidak tampil.');

        $this->assertSame(0, Location::query()->effectivelyVisible()->count());
    }

    public function test_location_visibility_rules(): void
    {
        $area = LocationArea::factory()->published()->create();

        $visible = Location::factory()->for($area, 'area')->published()->create();
        Location::factory()->for($area, 'area')->create();
        Location::factory()->for($area, 'area')->inactive()->create();
        Location::factory()->for($area, 'area')->scheduled()->create();

        $ids = Location::query()->effectivelyVisible()->pluck('id')->all();

        $this->assertSame([$visible->id], $ids);
    }

    public function test_area_scope_requires_a_visible_location(): void
    {
        $withVisible = LocationArea::factory()->published()->create();
        Location::factory()->for($withVisible, 'area')->published()->create();

        $withDraftOnly = LocationArea::factory()->published()->create();
        Location::factory()->for($withDraftOnly, 'area')->create();

        $empty = LocationArea::factory()->published()->create();

        $ids = LocationArea::query()->publiclyVisible()->hasVisibleLocations()->pluck('id')->all();

        $this->assertSame([$withVisible->id], $ids);
        $this->assertNotContains($withDraftOnly->id, $ids);
        $this->assertNotContains($empty->id, $ids);
    }

    // ----------------------------------------------------------- ordering

    public function test_ordering_uses_sort_order_then_name(): void
    {
        $c = LocationArea::factory()->create(['name' => 'Cianjur', 'sort_order' => 5]);
        $a = LocationArea::factory()->create(['name' => 'Aceh', 'sort_order' => 1]);
        $b = LocationArea::factory()->create(['name' => 'Bandung', 'sort_order' => 1]);

        $this->assertSame(
            [$a->id, $b->id, $c->id],
            LocationArea::query()->ordered()->pluck('id')->all(),
        );
    }

    public function test_images_put_the_cover_first(): void
    {
        $location = Location::factory()->create();

        LocationImage::factory()->for($location)->create(['sort_order' => 1]);
        $cover = LocationImage::factory()->for($location)->cover()->create(['sort_order' => 9]);

        $this->assertSame($cover->id, $location->images()->ordered()->first()->id);
    }

    // -------------------------------------------------------- soft delete

    public function test_soft_delete_and_restore_keep_the_row(): void
    {
        $location = Location::factory()->create();

        $location->delete();

        $this->assertSoftDeleted($location);
        $this->assertSame(0, Location::query()->count());
        $this->assertSame(1, Location::withTrashed()->count());

        $location->restore();

        $this->assertSame(1, Location::query()->count());
    }

    // ------------------------------------------------------------ helpers

    public function test_headline_and_description_fall_back_safely(): void
    {
        $area = LocationArea::factory()->create(['name' => 'Cianjur', 'headline' => null, 'description' => null]);

        $this->assertSame('Lokasi Gerobak DIMDUM di Cianjur', $area->publicHeadline());
        $this->assertSame('Temukan gerobak DIMDUM yang tersedia di wilayah Cianjur.', $area->publicDescription());

        $area->headline = '   ';
        $this->assertSame('Lokasi Gerobak DIMDUM di Cianjur', $area->publicHeadline(), 'Spasi saja tetap dianggap kosong.');

        $area->headline = 'Headline khusus';
        $this->assertSame('Headline khusus', $area->publicHeadline());
    }

    public function test_filter_group_falls_back_to_district(): void
    {
        $location = Location::factory()->create(['filter_label' => null, 'district' => 'Cipanas']);
        $this->assertSame('Cipanas', $location->filterGroup());

        $location->filter_label = 'Pusat Kota';
        $this->assertSame('Pusat Kota', $location->filterGroup());

        $location->filter_label = null;
        $location->district = null;
        $this->assertNull($location->filterGroup());
    }
}
