<?php

namespace App\Services;

use App\Models\Location;
use App\Models\LocationArea;
use App\Models\LocationAreaSlugRedirect;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pengelola slug wilayah landing.
 *
 * Halaman wilayah dipakai sebagai destination iklan, jadi URL yang sudah
 * pernah terbit tidak boleh mati. Setiap penggantian slug pada wilayah yang
 * PERNAH terbit otomatis mencatat slug lama sebagai redirect 301.
 *
 * Redirect selalu menunjuk ke AREA, bukan ke slug lain. Karena itu berapa
 * kali pun slug berganti (a -> b -> c), seluruh slug lama tetap mengarah ke
 * canonical terbaru dalam satu lompatan, dan rantai/loop tidak mungkin
 * terbentuk.
 */
class LocationAreaSlugService
{
    /**
     * Rapikan input menjadi slug.
     */
    public function normalize(?string $value, ?string $fallbackName = null): string
    {
        $slug = Str::slug((string) $value);

        if ($slug === '' && $fallbackName !== null) {
            $slug = Str::slug($fallbackName);
        }

        return $slug;
    }

    /**
     * Apakah slug ini boleh dipakai oleh area tertentu?
     *
     * Ditolak bila bentrok dengan:
     *   - slug area lain, TERMASUK yang sudah soft-deleted (agar restore
     *     tidak menabrak slug yang sementara "kosong");
     *   - slug lama milik area LAIN.
     *
     * Slug lama milik area itu sendiri DIIZINKAN: mengembalikan wilayah ke
     * slug lamanya adalah operasi yang sah, dan baris redirect-nya dihapus
     * saat perubahan diterapkan sehingga tidak menyisakan loop.
     */
    public function isAvailable(string $slug, ?int $ignoreAreaId = null): bool
    {
        if ($slug === '') {
            return false;
        }

        $takenByArea = LocationArea::withTrashed()
            ->where('slug', $slug)
            ->when($ignoreAreaId !== null, fn ($query) => $query->whereKeyNot($ignoreAreaId))
            ->exists();

        if ($takenByArea) {
            return false;
        }

        return ! LocationAreaSlugRedirect::query()
            ->where('old_slug', $slug)
            ->when($ignoreAreaId !== null, fn ($query) => $query->where('location_area_id', '!=', $ignoreAreaId))
            ->exists();
    }

    /**
     * Terapkan slug baru pada wilayah, dalam satu transaction.
     *
     * @return bool true bila slug benar-benar berubah.
     */
    public function apply(LocationArea $area, string $newSlug, ?int $userId = null): bool
    {
        $newSlug = $this->normalize($newSlug, $area->name);

        if ($newSlug === '' || $newSlug === $area->slug) {
            return false;
        }

        if (! $this->isAvailable($newSlug, $area->getKey())) {
            throw new \RuntimeException("Slug \"{$newSlug}\" sudah dipakai wilayah lain atau merupakan slug lama.");
        }

        DB::transaction(function () use ($area, $newSlug, $userId): void {
            $previousSlug = $area->slug;
            $wasPublished = $area->hasEverBeenPublished();

            /*
             | Slug baru tidak boleh berstatus redirect. Kasusnya: wilayah
             | kembali ke slug lamanya sendiri. Barisnya dibuang lebih dulu
             | supaya URL canonical tidak sekaligus menjadi redirect.
             */
            LocationAreaSlugRedirect::query()
                ->where('location_area_id', $area->getKey())
                ->where('old_slug', $newSlug)
                ->delete();

            $area->slug = $newSlug;
            $area->save();

            /*
             | Redirect hanya dicatat untuk halaman yang PERNAH terbit.
             | Wilayah yang masih draft belum punya URL publik, jadi tidak
             | ada yang perlu diselamatkan.
             */
            if ($wasPublished && $previousSlug !== null && $previousSlug !== '') {
                LocationAreaSlugRedirect::query()->updateOrCreate(
                    ['old_slug' => $previousSlug],
                    ['location_area_id' => $area->getKey(), 'created_by' => $userId],
                );
            }
        });

        return true;
    }

    /**
     * Slug unik untuk gerobak DI DALAM satu wilayah.
     */
    public function uniqueLocationSlug(int $areaId, string $desired, ?int $ignoreLocationId = null): string
    {
        $base = $this->normalize($desired) ?: 'gerobak';
        $slug = $base;
        $suffix = 2;

        while ($this->locationSlugTaken($areaId, $slug, $ignoreLocationId)) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    protected function locationSlugTaken(int $areaId, string $slug, ?int $ignoreLocationId): bool
    {
        return Location::withTrashed()
            ->where('location_area_id', $areaId)
            ->where('slug', $slug)
            ->when($ignoreLocationId !== null, fn ($query) => $query->whereKeyNot($ignoreLocationId))
            ->exists();
    }
}
