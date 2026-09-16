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
 * Sejak slug dipisahkan, provinsi tidak lagi punya status terbit -- hanya
 * `is_active` sebagai status operasional. Default-nya AKTIF supaya fixture
 * biasa langsung dapat menyumbang gerobak ke halaman slug; test yang menguji
 * cascade visibilitas menonaktifkannya secara eksplisit.
 *
 * @extends Factory<Province>
 */
class ProvinceFactory extends Factory
{
    protected $model = Province::class;

    public function definition(): array
    {
        return [
            'name' => 'Provinsi Uji '.Str::upper(Str::random(5)),
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
