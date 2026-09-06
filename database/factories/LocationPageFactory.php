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
            /*
             | Aktif secara bawaan: sejak jadwal terbit dibuang, halaman aktif
             | langsung dapat dibuka, dan itulah keadaan normal sebuah halaman.
             | Test yang menguji halaman tersembunyi menyatakannya eksplisit.
             */
            'is_active' => true,
            'is_featured' => false,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /** Periodenya baru dimulai nanti. */
    public function upcoming(): static
    {
        return $this->state(fn (): array => [
            'is_active' => true,
            'starts_at' => now()->addWeek(),
        ]);
    }

    /** Periodenya sudah lewat. */
    public function expired(): static
    {
        return $this->state(fn (): array => [
            'is_active' => true,
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subDay(),
        ]);
    }

    /** Dipilih untuk tampil di daftar lokasi homepage. */
    public function onHomepage(): static
    {
        return $this->state(fn (): array => ['is_featured' => true]);
    }

    /** Section produk dinyalakan. Pilihan produknya dipasang test sendiri. */
    public function showingProducts(): static
    {
        return $this->state(fn (): array => ['show_products' => true]);
    }
}
