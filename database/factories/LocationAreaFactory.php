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
        $name = 'Area Uji '.Str::upper(Str::random(5));

        return [
            'location_group_id' => LocationGroup::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'headline' => null,
            'description' => null,
            'seo_title' => null,
            'seo_description' => null,
            'is_active' => false,
            'published_at' => null,
            'sort_order' => 0,
        ];
    }

    /** Aktif dan sudah terbit -> tampil publik. */
    public function published(): static
    {
        return $this->state(fn (): array => [
            'is_active' => true,
            'published_at' => now()->subDay(),
        ]);
    }

    /** Aktif tetapi jadwal terbitnya masih di masa depan. */
    public function scheduled(): static
    {
        return $this->state(fn (): array => [
            'is_active' => true,
            'published_at' => now()->addWeek(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
            'published_at' => now()->subDay(),
        ]);
    }
}
