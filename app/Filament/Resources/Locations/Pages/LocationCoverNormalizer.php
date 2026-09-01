<?php

namespace App\Filament\Resources\Locations\Pages;

use App\Models\Location;
use App\Models\LocationImage;

/**
 * Menjaga aturan "maksimal satu foto utama per gerobak".
 *
 * Form membiarkan admin mencentang beberapa foto sekaligus; aturan finalnya
 * ditegakkan di sini setelah penyimpanan, bukan dengan JavaScript yang bisa
 * dilewati.
 */
class LocationCoverNormalizer
{
    public static function normalize(Location $location): void
    {
        $images = $location->images()->ordered()->get();

        if ($images->isEmpty()) {
            return;
        }

        // Cover pertama menurut urutan; bila tidak ada yang dicentang, foto
        // pertama otomatis menjadi cover.
        $cover = $images->firstWhere('is_cover', true) ?? $images->first();

        $images->each(function (LocationImage $image) use ($cover): void {
            $shouldBeCover = $image->getKey() === $cover->getKey();

            if ($image->is_cover === $shouldBeCover) {
                return;
            }

            $image->is_cover = $shouldBeCover;
            $image->save();
        });
    }
}
