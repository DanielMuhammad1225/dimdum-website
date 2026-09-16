<?php

namespace Database\Factories;

use App\Models\LocationArea;
use App\Models\LocationGroup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Factory HANYA untuk test.
 *
 * Nama area sengaja memakai penanda "Area Uji" supaya baris yang lolos
 * ke database development mudah dikenali. Tidak ada seeder yang memanggil
 * factory ini: data lokasi asli dimasukkan pemilik project lewat admin.
 *
 * @extends Factory<LocationArea>
 */
class LocationAreaFactory extends Factory
{
    protected $model = LocationArea::class;

    public function definition(): array
    {
        return [
            'location_group_id' => LocationGroup::factory(),
            'name' => 'Area Uji '.Str::upper(Str::random(5)),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }
}
