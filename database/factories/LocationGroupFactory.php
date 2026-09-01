<?php

namespace Database\Factories;

use App\Enums\LocationGroupType;
use App\Models\LocationGroup;
use App\Models\Province;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Factory HANYA untuk test. Tidak dipakai seeder mana pun.
 *
 * @extends Factory<LocationGroup>
 */
class LocationGroupFactory extends Factory
{
    protected $model = LocationGroup::class;

    public function definition(): array
    {
        $name = 'Grup Uji '.Str::upper(Str::random(5));

        return [
            /*
             | Provinsi induk dibuat SUDAH TERBIT supaya fixture default
             | benar-benar dapat tampil publik; kalau tidak, hampir setiap
             | test harus menerbitkan rantai induknya sendiri lebih dulu.
             | Test yang justru menguji cascade visibilitas membuat induk
             | draft/nonaktif secara eksplisit.
             */
            'province_id' => Province::factory()->published(),
            'name' => $name,
            'slug' => Str::slug($name),
            'type' => LocationGroupType::Administrative,
            'description' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function marketingGroup(): static
    {
        return $this->state(fn (): array => [
            'type' => LocationGroupType::MarketingGroup,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }
}
