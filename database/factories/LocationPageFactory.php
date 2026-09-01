<?php

namespace Database\Factories;

use App\Models\LocationPage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Factory HANYA untuk test. Tidak dipakai seeder mana pun.
 *
 * Judulnya memakai penanda "Halaman Uji" supaya baris yang lolos ke database
 * development mudah dikenali. Halaman asli dibuat pemilik project lewat admin.
 *
 * @extends Factory<LocationPage>
 */
class LocationPageFactory extends Factory
{
    protected $model = LocationPage::class;

    public function definition(): array
    {
        $title = 'Halaman Uji '.Str::upper(Str::random(5));

        return [
            'title' => $title,
            'slug' => Str::slug($title),
            'short_description' => null,
            'detailed_description' => null,
            'period_text' => null,
            'starts_at' => null,
            'ends_at' => null,
            'button_text' => null,
            'button_url' => null,
            'poster_path' => null,
            'seo_title' => null,
            'seo_description' => null,
            'is_active' => false,
            'is_featured' => false,
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

    /** Sudah terbit tetapi periodenya sudah lewat. */
    public function expired(): static
    {
        return $this->state(fn (): array => [
            'is_active' => true,
            'published_at' => now()->subMonth(),
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subDay(),
        ]);
    }

    public function featured(): static
    {
        return $this->state(fn (): array => ['is_featured' => true]);
    }
}
