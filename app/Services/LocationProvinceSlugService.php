<?php

namespace App\Services;

use App\Models\LocationGroup;
use App\Models\LocationProvinceSlugRedirect;
use App\Models\Province;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pengelola slug provinsi.
 *
 * Slug provinsi adalah segmen PERTAMA setiap URL landing, jadi menggantinya
 * ikut memutus seluruh URL Area di bawahnya sekaligus. Karena itu perlakuannya
 * sama ketatnya dengan slug Area: setiap penggantian pada provinsi yang PERNAH
 * terbit otomatis mencatat slug lama sebagai redirect 301.
 *
 * Redirect selalu menunjuk ke PROVINSI, bukan ke slug lain, sehingga berapa
 * kali pun slug berganti seluruh slug lama mengarah ke canonical terbaru dalam
 * satu lompatan dan rantai/loop mustahil terbentuk.
 */
class LocationProvinceSlugService
{
    public function normalize(?string $value, ?string $fallbackName = null): string
    {
        $slug = Str::slug((string) $value);

        if ($slug === '' && $fallbackName !== null) {
            $slug = Str::slug($fallbackName);
        }

        return $slug;
    }

    /**
     * Apakah slug ini boleh dipakai provinsi tertentu?
     *
     * Ditolak bila bentrok dengan:
     *   - slug provinsi lain, TERMASUK yang sudah soft-deleted (agar restore
     *     tidak menabrak slug yang sementara "kosong");
     *   - slug lama milik provinsi LAIN.
     *
     * Slug lama milik provinsi itu sendiri DIIZINKAN: mengembalikan provinsi
     * ke slug lamanya adalah operasi yang sah, dan baris redirect-nya dihapus
     * saat perubahan diterapkan sehingga tidak menyisakan loop.
     */
    public function isAvailable(string $slug, ?int $ignoreProvinceId = null): bool
    {
        if ($slug === '') {
            return false;
        }

        $takenByProvince = Province::withTrashed()
            ->where('slug', $slug)
            ->when($ignoreProvinceId !== null, fn ($query) => $query->whereKeyNot($ignoreProvinceId))
            ->exists();

        if ($takenByProvince) {
            return false;
        }

        return ! LocationProvinceSlugRedirect::query()
            ->where('old_slug', $slug)
            ->when($ignoreProvinceId !== null, fn ($query) => $query->where('province_id', '!=', $ignoreProvinceId))
            ->exists();
    }

    /**
     * Terapkan slug baru pada provinsi, dalam satu transaction.
     *
     * @return bool true bila slug benar-benar berubah.
     */
    public function apply(Province $province, string $newSlug, ?int $userId = null): bool
    {
        $newSlug = $this->normalize($newSlug, $province->name);

        if ($newSlug === '' || $newSlug === $province->slug) {
            return false;
        }

        if (! $this->isAvailable($newSlug, $province->getKey())) {
            throw new \RuntimeException("Slug \"{$newSlug}\" sudah dipakai provinsi lain atau merupakan slug lama.");
        }

        DB::transaction(function () use ($province, $newSlug, $userId): void {
            $previousSlug = $province->slug;
            $wasPublished = $province->hasEverBeenPublished();

            /*
             | Slug baru tidak boleh sekaligus berstatus redirect. Kasusnya:
             | provinsi kembali ke slug lamanya sendiri. Barisnya dibuang lebih
             | dulu supaya URL canonical tidak juga menjadi redirect.
             */
            LocationProvinceSlugRedirect::query()
                ->where('province_id', $province->getKey())
                ->where('old_slug', $newSlug)
                ->delete();

            $province->slug = $newSlug;
            $province->save();

            /*
             | Redirect hanya dicatat untuk halaman yang PERNAH terbit.
             | Provinsi yang masih draft belum punya URL publik, jadi tidak ada
             | yang perlu diselamatkan.
             */
            if ($wasPublished && $previousSlug !== null && $previousSlug !== '') {
                LocationProvinceSlugRedirect::query()->updateOrCreate(
                    ['old_slug' => $previousSlug],
                    ['province_id' => $province->getKey(), 'created_by' => $userId],
                );
            }
        });

        return true;
    }

    /**
     * Slug unik untuk Kota/Grup DI DALAM satu provinsi.
     *
     * Kota/Grup tidak punya URL publik, jadi tidak ada riwayat slug yang harus
     * diperiksa -- cukup keunikan di dalam provinsinya.
     */
    public function uniqueGroupSlug(int $provinceId, string $desired, ?int $ignoreGroupId = null): string
    {
        $base = $this->normalize($desired) ?: 'grup';
        $slug = $base;
        $suffix = 2;

        while ($this->groupSlugTaken($provinceId, $slug, $ignoreGroupId)) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    protected function groupSlugTaken(int $provinceId, string $slug, ?int $ignoreGroupId): bool
    {
        return LocationGroup::withTrashed()
            ->where('province_id', $provinceId)
            ->where('slug', $slug)
            ->when($ignoreGroupId !== null, fn ($query) => $query->whereKeyNot($ignoreGroupId))
            ->exists();
    }
}
