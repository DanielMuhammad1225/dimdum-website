<?php

namespace App\Services;

use App\Models\LocationPage;
use App\Models\LocationPageSlugRedirect;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pengelola slug Halaman Slug Lokasi.
 *
 * Halaman inilah satu-satunya pemilik URL publik modul lokasi, jadi hanya di
 * sini slug perlu dijaga. Setiap penggantian pada halaman yang PERNAH terbit
 * otomatis mencatat slug lama sebagai redirect 301.
 *
 * Redirect selalu menunjuk ke HALAMAN, bukan ke slug lain, sehingga berapa
 * kali pun slug berganti seluruh slug lama mengarah ke canonical terbaru dalam
 * SATU lompatan dan rantai/loop mustahil terbentuk.
 */
class LocationPageSlugService
{
    public function normalize(?string $value, ?string $fallbackTitle = null): string
    {
        $slug = Str::slug((string) $value);

        if ($slug === '' && $fallbackTitle !== null) {
            $slug = Str::slug($fallbackTitle);
        }

        return $slug;
    }

    /**
     * Apakah slug ini boleh dipakai halaman tertentu?
     *
     * Ditolak bila bentrok dengan:
     *   - slug halaman lain, TERMASUK yang sudah soft-deleted (agar restore
     *     tidak menabrak slug yang sementara "kosong");
     *   - slug lama milik halaman LAIN.
     *
     * Slug lama milik halaman itu sendiri DIIZINKAN: mengembalikan halaman ke
     * slug lamanya adalah operasi yang sah, dan baris redirect-nya dihapus
     * saat perubahan diterapkan sehingga tidak menyisakan loop.
     */
    public function isAvailable(string $slug, ?int $ignorePageId = null): bool
    {
        if ($slug === '') {
            return false;
        }

        $takenByPage = LocationPage::withTrashed()
            ->where('slug', $slug)
            ->when($ignorePageId !== null, fn ($query) => $query->whereKeyNot($ignorePageId))
            ->exists();

        if ($takenByPage) {
            return false;
        }

        return ! LocationPageSlugRedirect::query()
            ->where('old_slug', $slug)
            ->when($ignorePageId !== null, fn ($query) => $query->where('location_page_id', '!=', $ignorePageId))
            ->exists();
    }

    /**
     * Slug unik untuk halaman baru, dengan sufiks angka bila perlu.
     */
    public function uniqueSlug(string $desired, ?string $fallbackTitle = null, ?int $ignorePageId = null): string
    {
        $base = $this->normalize($desired, $fallbackTitle) ?: 'halaman';
        $slug = $base;
        $suffix = 2;

        while (! $this->isAvailable($slug, $ignorePageId)) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * Terapkan slug baru pada halaman, dalam satu transaction.
     *
     * @return bool true bila slug benar-benar berubah.
     */
    public function apply(LocationPage $page, string $newSlug, ?int $userId = null): bool
    {
        $newSlug = $this->normalize($newSlug, $page->title);

        if ($newSlug === '' || $newSlug === $page->slug) {
            return false;
        }

        if (! $this->isAvailable($newSlug, $page->getKey())) {
            throw new \RuntimeException("Slug \"{$newSlug}\" sudah dipakai halaman lain atau merupakan slug lama.");
        }

        DB::transaction(function () use ($page, $newSlug, $userId): void {
            $previousSlug = $page->slug;
            $wasPublished = $page->hasEverBeenPublished();

            /*
             | Slug baru tidak boleh sekaligus berstatus redirect. Kasusnya:
             | halaman kembali ke slug lamanya sendiri. Barisnya dibuang lebih
             | dulu supaya URL canonical tidak juga menjadi redirect.
             */
            LocationPageSlugRedirect::query()
                ->where('location_page_id', $page->getKey())
                ->where('old_slug', $newSlug)
                ->delete();

            $page->slug = $newSlug;
            $page->save();

            /*
             | Redirect hanya dicatat untuk halaman yang PERNAH terbit. Halaman
             | yang masih draft belum punya URL publik, jadi tidak ada yang
             | perlu diselamatkan.
             */
            if ($wasPublished && $previousSlug !== null && $previousSlug !== '') {
                LocationPageSlugRedirect::query()->updateOrCreate(
                    ['old_slug' => $previousSlug],
                    ['location_page_id' => $page->getKey(), 'created_by' => $userId],
                );
            }
        });

        return true;
    }
}
