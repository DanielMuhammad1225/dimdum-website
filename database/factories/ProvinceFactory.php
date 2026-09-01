<?php

namespace Database\Factories;

use App\Models\Province;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Factory HANYA untuk test.
 *
 * Nama provinsi sengaja memakai penanda "Provinsi Uji" supaya baris yang
 * lolos ke database development mudah dikenali. Tidak ada seeder yang
 * memanggil factory ini: data lokasi asli dimasukkan pemilik project lewat
 * admin.
 *
 * @extends Factory<Province>
 */
class ProvinceFactory extends Factory
{
    protected $model = Province::class;

    public function definition(): array
    {
        $name = 'Provinsi Uji '.Str::upper(Str::random(5));

        return [
            'name' => $name,
            'slug' => Str::slug($name),
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
