<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\LocationArea;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Factory HANYA untuk test. Tidak dipakai seeder mana pun.
 *
 * Alamatnya jelas-jelas contoh uji, bukan alamat nyata dan bukan salinan
 * alamat brand lain.
 *
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    protected $model = Location::class;

    public function definition(): array
    {
        $name = 'Gerobak Uji '.Str::upper(Str::random(5));

        return [
            'location_area_id' => LocationArea::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'full_address' => 'Alamat uji nomor '.random_int(1, 99),
            'village' => null,
            'district' => null,
            'city_regency' => null,
            'postal_code' => null,
            'landmark' => null,
            'operational_hours_text' => null,
            'whatsapp_number' => null,
            'latitude' => null,
            'longitude' => null,
            'google_maps_url' => null,
            'is_active' => false,
            'published_at' => null,
            'sort_order' => 0,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'is_active' => true,
            'published_at' => now()->subDay(),
        ]);
    }

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
