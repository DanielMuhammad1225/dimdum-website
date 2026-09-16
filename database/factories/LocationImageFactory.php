<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\LocationImage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Factory HANYA untuk test.
 *
 * @extends Factory<LocationImage>
 */
class LocationImageFactory extends Factory
{
    protected $model = LocationImage::class;

    public function definition(): array
    {
        return [
            'location_id' => Location::factory(),
            'image_path' => 'locations/'.Str::ulid().'/'.Str::ulid().'.jpg',
            'alt_text' => 'Foto uji gerobak DIMDUM',
            'caption' => null,
            'sort_order' => 0,
            'is_cover' => false,
            'width' => 1200,
            'height' => 800,
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123456,
        ];
    }

    public function cover(): static
    {
        return $this->state(fn (): array => ['is_cover' => true]);
    }
}
