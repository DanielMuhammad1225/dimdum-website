<?php

namespace Tests\Feature\Locations;

use App\Models\Location;
use App\Models\LocationArea;
use App\Models\LocationGroup;
use App\Models\LocationPage;
use App\Models\Province;
use App\Services\LocationPageScopeService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Cakupan Halaman Slug Lokasi.
 *
 * Satu rumus yang diuji dari segala arah:
 *
 *     location.area.location_group_id HARUS termasuk
 *     dalam Kota/Grup yang dipilih halaman
 *
 * Area SENGAJA bukan input. Memilih satu Kota/Grup membuka gerobak dari
 * SELURUH Area di bawahnya sekaligus.
 */
class LocationPageScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function scope(): LocationPageScopeService
    {
        return app(LocationPageScopeService::class);
    }

    // ---------------------------------------------------------------- schema

    public function test_the_page_table_owns_every_public_field(): void
    {
        foreach ([
            'title', 'slug', 'short_description', 'detailed_description',
            'period_text', 'starts_at', 'ends_at', 'button_text', 'button_url',
            'poster_path', 'poster_alt', 'poster_width', 'poster_height',
            'poster_mime_type', 'poster_size_bytes', 'seo_title', 'seo_description',
            'is_active', 'is_featured', 'published_at', 'sort_order',
            'created_by', 'updated_by', 'deleted_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('location_pages', $column), "Kolom {$column} hilang.");
        }
    }

    public function test_a_page_may_cover_several_groups_and_hold_several_locations(): void
    {
        $page = LocationPage::factory()->create();
        $groupA = LocationGroup::factory()->create();
        $groupB = LocationGroup::factory()->create();

        $page->groups()->attach([$groupA->getKey(), $groupB->getKey()]);

        $first = Location::factory()->for(LocationArea::factory()->for($groupA, 'group'), 'area')->create();
        $second = Location::factory()->for(LocationArea::factory()->for($groupB, 'group'), 'area')->create();

        $page->locations()->attach([$first->getKey(), $second->getKey()]);

        $this->assertSame(2, $page->groups()->count());
        $this->assertSame(2, $page->locations()->count());
    }

    public function test_the_pivots_reject_duplicates(): void
    {
        $page = LocationPage::factory()->create();
        $group = LocationGroup::factory()->create();

        $page->groups()->attach($group);

        $this->expectException(QueryException::class);

        // Unique constraint di level database, bukan hanya sync() aplikasi.
        $page->groups()->attach($group);
    }

    public function test_a_group_cannot_be_force_deleted_while_a_page_still_covers_it(): void
    {
        $page = LocationPage::factory()->create();
        $group = LocationGroup::factory()->create();
        $page->groups()->attach($group);

        $this->expectException(QueryException::class);

        $group->forceDelete();
    }

    public function test_deleting_a_page_permanently_removes_its_pivot_rows(): void
    {
        $page = LocationPage::factory()->create();
        $group = LocationGroup::factory()->create();
        $location = Location::factory()->for(LocationArea::factory()->for($group, 'group'), 'area')->create();

        $page->groups()->attach($group);
        $page->locations()->attach($location);

        // Pivot dilepas dulu (FK-nya restrictOnDelete di sisi master), lalu
        // barisnya dihapus permanen -- persis alur halaman Edit.
        $page->groups()->detach();
        $page->locations()->detach();
        $page->forceDelete();

        $this->assertDatabaseCount('location_page_group', 0);
        $this->assertDatabaseCount('location_page_location', 0);
    }

    // -------------------------------------------------------------- kandidat

    public function test_one_group_offers_locations_from_every_area_below_it(): void
    {
        $group = LocationGroup::factory()->create();

        $areaOne = LocationArea::factory()->for($group, 'group')->create();
        $areaTwo = LocationArea::factory()->for($group, 'group')->create();
        $areaThree = LocationArea::factory()->for($group, 'group')->create();

        $a = Location::factory()->for($areaOne, 'area')->create(['name' => 'Gerobak A']);
        $b = Location::factory()->for($areaTwo, 'area')->create(['name' => 'Gerobak B']);
        $c = Location::factory()->for($areaThree, 'area')->create(['name' => 'Gerobak C']);

        $ids = $this->scope()->candidateQuery([$group->getKey()])->pluck('locations.id')->all();

        $this->assertEqualsCanonicalizing([$a->id, $b->id, $c->id], $ids);
    }

    public function test_several_groups_produce_the_union_of_their_candidates(): void
    {
        $groupA = LocationGroup::factory()->create();
        $groupB = LocationGroup::factory()->create();
        $groupC = LocationGroup::factory()->create();

        $fromA = Location::factory()->for(LocationArea::factory()->for($groupA, 'group'), 'area')->create();
        $fromB = Location::factory()->for(LocationArea::factory()->for($groupB, 'group'), 'area')->create();
        $fromC = Location::factory()->for(LocationArea::factory()->for($groupC, 'group'), 'area')->create();

        $ids = $this->scope()
            ->candidateQuery([$groupA->getKey(), $groupB->getKey()])
            ->pluck('locations.id')
            ->all();

        $this->assertEqualsCanonicalizing([$fromA->id, $fromB->id], $ids);
        $this->assertNotContains($fromC->id, $ids);
    }

    public function test_no_group_means_no_candidate_at_all(): void
    {
        Location::factory()->count(3)->create();

        $this->assertSame([], $this->scope()->candidateQuery([])->pluck('locations.id')->all());
        $this->assertSame([], $this->scope()->candidateOptions([]));
    }

    /**
     * Area muncul HANYA sebagai judul kelompok, bukan sebagai pilihan.
     */
    public function test_candidate_options_are_grouped_by_province_group_and_area(): void
    {
        $province = Province::factory()->create(['name' => 'Jawa Barat']);
        $group = LocationGroup::factory()->for($province, 'province')->create(['name' => 'Cianjur']);
        $area = LocationArea::factory()->for($group, 'group')->create(['name' => 'Cianjur Kota']);

        $location = Location::factory()->for($area, 'area')->create([
            'name' => 'Gerobak Pasar',
            'full_address' => 'Jalan Uji Nomor 1',
        ]);

        $options = $this->scope()->candidateOptions([$group->getKey()]);

        $heading = 'Jawa Barat — Cianjur · Cianjur Kota';

        $this->assertArrayHasKey($heading, $options);
        $this->assertArrayHasKey($location->id, $options[$heading]);
        $this->assertStringContainsString('Gerobak Pasar', $options[$heading][$location->id]);
        // Alamat singkat ikut supaya gerobak bernama mirip tetap bisa dibedakan.
        $this->assertStringContainsString('Jalan Uji Nomor 1', $options[$heading][$location->id]);
    }

    public function test_an_existing_selection_stays_visible_even_when_out_of_scope(): void
    {
        $inScope = LocationGroup::factory()->create();
        $outside = Location::factory()->create(['name' => 'Pilihan Lama']);

        $options = $this->scope()->candidateOptions([$inScope->getKey()], [$outside->getKey()]);

        $labels = collect($options)->flatMap(fn (array $group): array => array_values($group))->all();

        $this->assertContains('Pilihan Lama — Alamat uji nomor '.explode('nomor ', $outside->full_address)[1], $labels);
    }

    public function test_a_group_label_always_names_its_province(): void
    {
        $province = Province::factory()->create(['name' => 'Jawa Barat']);
        $group = LocationGroup::factory()->for($province, 'province')->create(['name' => 'Cianjur Selatan']);

        $this->assertSame(
            ['Jawa Barat — Cianjur Selatan'],
            array_values($this->scope()->groupOptions()),
        );
        $this->assertArrayHasKey($group->getKey(), $this->scope()->groupOptions());
    }

    // ------------------------------------------------------ validasi server

    public function test_a_location_outside_the_scope_is_rejected(): void
    {
        $group = LocationGroup::factory()->create();
        $intruder = Location::factory()->create(['name' => 'Gerobak Asing']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Gerobak Asing');

        $this->scope()->assertLocationsWithinGroups([$intruder->getKey()], [$group->getKey()]);
    }

    public function test_locations_without_any_group_are_rejected(): void
    {
        $location = Location::factory()->create();

        $this->expectException(ValidationException::class);

        $this->scope()->assertLocationsWithinGroups([$location->getKey()], []);
    }

    public function test_sync_refuses_a_payload_that_leaves_the_scope(): void
    {
        $page = LocationPage::factory()->create();
        $group = LocationGroup::factory()->create();
        $intruder = Location::factory()->create();

        try {
            $this->scope()->sync($page, [$group->getKey()], [$intruder->getKey()]);
            $this->fail('Payload di luar cakupan seharusnya ditolak.');
        } catch (ValidationException) {
            // Tidak satu baris pun boleh tertulis sebelum penolakan.
            $this->assertDatabaseCount('location_page_group', 0);
            $this->assertDatabaseCount('location_page_location', 0);
        }
    }

    public function test_a_group_still_holding_a_selected_location_cannot_be_detached(): void
    {
        $province = Province::factory()->create(['name' => 'Jawa Barat']);
        $group = LocationGroup::factory()->for($province, 'province')->create(['name' => 'Cianjur']);
        $location = Location::factory()->for(LocationArea::factory()->for($group, 'group'), 'area')->create();

        $page = LocationPage::factory()->create();
        $page->groups()->attach($group);
        $page->locations()->attach($location);

        $blocking = $this->scope()->groupsStillInUse($page, []);

        $this->assertSame(['Jawa Barat — Cianjur'], $blocking->all());

        $this->expectException(ValidationException::class);
        $this->scope()->assertGroupsCanBeDetached($page, []);
    }

    public function test_a_group_without_selected_locations_may_be_detached(): void
    {
        $page = LocationPage::factory()->create();
        $keep = LocationGroup::factory()->create();
        $drop = LocationGroup::factory()->create();

        $page->groups()->attach([$keep->getKey(), $drop->getKey()]);

        $this->assertTrue($this->scope()->groupsStillInUse($page, [$keep->getKey()])->isEmpty());

        // Tidak melempar apa pun.
        $this->scope()->assertGroupsCanBeDetached($page, [$keep->getKey()]);
        $this->addToAssertionCount(1);
    }

    /**
     * Cakupan tidak otomatis menjadi isi.
     */
    public function test_a_new_location_never_joins_a_page_on_its_own(): void
    {
        $group = LocationGroup::factory()->create();
        $area = LocationArea::factory()->for($group, 'group')->create();
        $chosen = Location::factory()->for($area, 'area')->create();

        $page = LocationPage::factory()->published()->create();
        $this->scope()->sync($page, [$group->getKey()], [$chosen->getKey()]);

        // Gerobak baru di Kota/Grup yang SAMA, dibuat setelah halaman jadi.
        $newcomer = Location::factory()->for($area, 'area')->create(['name' => 'Gerobak Baru']);

        $this->assertSame([$chosen->id], $page->fresh()->locations()->pluck('locations.id')->all());
        $this->assertNotContains($newcomer->id, $page->fresh()->locations()->pluck('locations.id')->all());
    }
}
