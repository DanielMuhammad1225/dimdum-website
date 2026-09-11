<?php

namespace Tests\Feature\Bio\Concerns;

use App\Enums\BioLocationMode;
use App\Models\BioSetting;
use App\Models\Location;
use App\Models\LocationArea;
use App\Models\LocationGroup;
use App\Models\Province;
use App\Services\BioSettingsService;

/**
 * Pembuat data uji halaman Bio.
 *
 * Hanya untuk test. Tidak ada seeder yang memakainya, dan database
 * development tidak pernah disentuh (test berjalan di SQLite in-memory).
 */
trait BuildsBio
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function bio(array $attributes = []): BioSetting
    {
        $record = app(BioSettingsService::class)->recordOrCreate();

        $record->fill([
            'is_active' => true,
            'location_mode' => BioLocationMode::AllActiveLocations->value,
            'buttons' => BioSettingsService::defaultButtons(),
            ...$attributes,
        ])->save();

        return $record->refresh();
    }

    /**
     * Rantai hierarki lengkap yang seluruhnya aktif.
     *
     * @param  array<string, mixed>  $location
     * @return array{province: Province, group: LocationGroup, area: LocationArea, location: Location}
     */
    protected function chain(
        string $province = 'Jawa Barat',
        string $group = 'Cianjur',
        string $area = 'Cianjur Kota',
        array $location = [],
    ): array {
        $provinceModel = Province::query()->firstWhere('name', $province)
            ?? Province::factory()->create(['name' => $province]);

        $groupModel = LocationGroup::query()
            ->where('province_id', $provinceModel->id)
            ->firstWhere('name', $group)
            ?? LocationGroup::factory()->for($provinceModel, 'province')->create(['name' => $group]);

        $areaModel = LocationArea::factory()->for($groupModel, 'group')->create(['name' => $area]);

        $locationModel = Location::factory()->for($areaModel, 'area')->create([
            'name' => 'Gerobak '.$area,
            ...$location,
        ]);

        return [
            'province' => $provinceModel,
            'group' => $groupModel,
            'area' => $areaModel,
            'location' => $locationModel,
        ];
    }
}
