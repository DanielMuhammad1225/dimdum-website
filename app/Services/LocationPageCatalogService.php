<?php

namespace App\Services;

use App\Models\Location;
use App\Models\LocationImage;
use App\Models\LocationPage;
use App\Models\LocationPageSlugRedirect;
use App\Support\MapsUrl;
use App\Support\SafeUrl;
use App\Support\WhatsAppNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Satu-satunya sumber data Halaman Slug Lokasi untuk halaman publik.
 *
 * Controller memanggil service ini; Blade hanya menerima array yang sudah
 * jadi. Tidak ada query di Blade dan tidak ada model Eloquent hidup yang
 * disimpan di cache.
 *
 * Strategi cache -- VERSI, bukan penghapusan per key:
 * Setiap key memuat nomor versi. Satu perubahan apa pun pada halaman, slug,
 * publikasi, cakupan Kota/Grup, daftar gerobak terpilih, atau pada master
 * hierarki yang faktanya ikut tercetak di halaman, menaikkan versi sehingga
 * SELURUH turunan basi bersamaan. Cache aplikasi tidak pernah dikosongkan
 * seluruhnya, dan tidak perlu menebak key mana yang harus dibuang.
 *
 * Versinya SENGAJA terpisah dari cache lain: key-nya milik modul halaman
 * slug saja.
 */
class LocationPageCatalogService
{
    public const VERSION_KEY = 'location_pages.cache_version';

    /**
     * TTL data. Bukan penentu kesegaran -- versi yang menentukan -- tetapi
     * memastikan entri versi lama tidak menumpuk selamanya.
     */
    public const TTL_SECONDS = 3600;

    /**
     * Naikkan versi cache halaman slug.
     *
     * Cache::add + increment dipilih supaya operasinya atomik pada driver
     * yang mendukung (Redis/Memcached), bukan baca-lalu-tulis yang bisa
     * saling menimpa. Seluruh cache aplikasi TIDAK pernah dikosongkan.
     */
    public static function flushCache(): void
    {
        if (Cache::add(self::VERSION_KEY, 1)) {
            return;
        }

        Cache::increment(self::VERSION_KEY);
    }

    public static function cacheVersion(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 0);
    }

    protected function key(string $suffix): string
    {
        return 'location_pages.v'.self::cacheVersion().'.'.$suffix;
    }

    /**
     * Jalankan query dengan fallback yang SANGAT sempit.
     *
     * Hanya satu kondisi yang ditoleransi: tabelnya belum dibuat (migration
     * belum dijalankan). Error database lain DILEMPAR ULANG -- menelannya akan
     * membuat production tampak sehat padahal databasenya bermasalah.
     *
     * @template TValue
     *
     * @param  callable(): TValue  $query
     * @param  TValue  $fallback
     * @return TValue
     */
    protected function guardMissingTable(callable $query, mixed $fallback): mixed
    {
        try {
            return $query();
        } catch (QueryException $exception) {
            if (! $this->isMissingTable($exception)) {
                throw $exception;
            }

            Log::warning('Tabel halaman lokasi belum tersedia, halaman publik menampilkan daftar kosong.', [
                'message' => $exception->getMessage(),
            ]);

            return $fallback;
        }
    }

    protected function isMissingTable(QueryException $exception): bool
    {
        // SQLSTATE 42S02 = base table or view not found (MySQL).
        if (($exception->errorInfo[0] ?? null) === '42S02') {
            return true;
        }

        // SQLite hanya menyebutkannya di pesan.
        return str_contains($exception->getMessage(), 'no such table');
    }

    // ------------------------------------------------------------ resolusi

    /**
     * Tentukan nasib sebuah slug.
     *
     * @return array{status: 'ok'|'redirect'|'missing', slug: string|null}
     */
    public function resolve(string $slug): array
    {
        $payload = $this->pagePayload($slug);

        if ($payload !== null) {
            return ['status' => 'ok', 'slug' => $slug];
        }

        $canonical = $this->canonicalSlug($slug);

        if ($canonical !== null && $canonical !== $slug) {
            return ['status' => 'redirect', 'slug' => $canonical];
        }

        return ['status' => 'missing', 'slug' => null];
    }

    /**
     * Slug canonical untuk sebuah slug lama.
     *
     * Menunjuk langsung ke halaman, jadi hasilnya selalu satu lompatan.
     * Halaman yang tidak layak tampil TIDAK menghasilkan redirect: URL lama
     * yang mengarah ke draft harus 404, bukan mengalihkan ke halaman mati.
     */
    protected function canonicalSlug(string $slug): ?string
    {
        return Cache::remember(
            $this->key('canonical.'.$slug),
            self::TTL_SECONDS,
            fn (): ?string => $this->guardMissingTable(function () use ($slug): ?string {
                $page = LocationPageSlugRedirect::query()
                    ->where('old_slug', $slug)
                    ->with('page')
                    ->first()?->page;

                if (! $page instanceof LocationPage || ! $page->isPubliclyVisible()) {
                    return null;
                }

                return $page->slug;
            }, null),
        );
    }

    // -------------------------------------------------------------- payload

    /**
     * Data lengkap satu halaman publik, atau null bila tidak layak tampil.
     *
     * @return array<string, mixed>|null
     */
    public function pagePayload(string $slug): ?array
    {
        return Cache::remember(
            $this->key('page.'.$slug),
            self::TTL_SECONDS,
            fn (): ?array => $this->guardMissingTable(fn (): ?array => $this->buildPagePayload($slug), null),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function buildPagePayload(string $slug): ?array
    {
        $page = LocationPage::query()
            ->publiclyVisible()
            ->where('slug', $slug)
            ->first();

        if (! $page instanceof LocationPage) {
            return null;
        }

        $groupIds = $page->groups()->pluck('location_groups.id')->all();

        /*
         | Hanya gerobak yang:
         |   - dipilih EKSPLISIT pada halaman ini;
         |   - tidak terhapus dan aktif, dengan seluruh leluhur aktif;
         |   - masih berada di bawah salah satu Kota/Grup halaman.
         |
         | Gerobak yang kemudian dibuat di Kota/Grup yang sama TIDAK ikut
         | tampil: isinya ditentukan admin, bukan oleh keanggotaan grup.
         */
        $locations = $page->locations()
            ->effectivelyVisible()
            ->when(
                $groupIds !== [],
                fn (Builder $query) => $query->whereHas(
                    'area',
                    fn (Builder $area) => $area->whereIn($area->qualifyColumn('location_group_id'), $groupIds),
                ),
                fn (Builder $query) => $query->whereRaw('1 = 0'),
            )
            ->with(['area.group.province', 'images'])
            /*
             | Urutan mengikuti master: Provinsi -> Kota/Grup -> Area ->
             | Gerobak. Dikerjakan SQL lewat join, bukan sortBy() di koleksi:
             | Collection::sortBy() memperlakukan array closure sebagai
             | comparator dua argumen, bukan pengekstrak kunci, sehingga
             | urutannya diam-diam salah.
             */
            ->join('location_areas', 'location_areas.id', '=', 'locations.location_area_id')
            ->join('location_groups', 'location_groups.id', '=', 'location_areas.location_group_id')
            ->join('provinces', 'provinces.id', '=', 'location_groups.province_id')
            ->select('locations.*')
            ->orderBy('provinces.sort_order')
            ->orderBy('provinces.name')
            ->orderBy('location_groups.sort_order')
            ->orderBy('location_groups.name')
            ->orderBy('location_areas.sort_order')
            ->orderBy('location_areas.name')
            ->orderBy('locations.sort_order')
            ->orderBy('locations.name')
            ->orderBy('locations.id')
            ->get();

        $cards = $locations->map(fn (Location $location): array => $this->locationCard($location))->all();

        return [
            'id' => $page->id,
            'title' => $page->title,
            'slug' => $page->slug,
            'seo_title' => $page->publicTitle(),
            'seo_description' => $page->publicDescription(),
            'short_description' => $this->cleanText($page->short_description),
            'detailed_description' => $this->cleanText($page->detailed_description),
            'period_text' => $this->cleanText($page->period_text),
            'starts_at' => $page->starts_at?->toDateString(),
            'ends_at' => $page->ends_at?->toDateString(),
            'poster' => $this->posterPayload($page),
            // CTA hanya tampil bila teks DAN URL-nya sama-sama sah.
            'cta' => $this->ctaPayload($page),
            'url' => route('location-pages.show', $page->slug),
            'locations' => $cards,
            // Konteks pengelompokan, untuk heading di halaman publik.
            'sections' => $this->sections($cards),
            'updated_at' => $page->updated_at?->toAtomString(),
        ];
    }

    /**
     * Kelompokkan kartu gerobak menjadi bagian Kota/Grup -> Area.
     *
     * @param  list<array<string, mixed>>  $cards
     * @return list<array<string, mixed>>
     */
    protected function sections(array $cards): array
    {
        $sections = [];

        foreach ($cards as $card) {
            $groupKey = $card['group_name'].'|'.$card['province_name'];
            $areaKey = $card['area_name'];

            $sections[$groupKey]['group_name'] ??= $card['group_name'];
            $sections[$groupKey]['province_name'] ??= $card['province_name'];
            $sections[$groupKey]['areas'][$areaKey]['area_name'] ??= $areaKey;
            $sections[$groupKey]['areas'][$areaKey]['locations'][] = $card;
        }

        return array_values(array_map(
            fn (array $group): array => [
                'group_name' => $group['group_name'],
                'province_name' => $group['province_name'],
                'areas' => array_values($group['areas']),
            ],
            $sections,
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function posterPayload(LocationPage $page): ?array
    {
        $path = is_string($page->poster_path) ? ltrim(trim($page->poster_path), '/') : '';

        // Path aneh tidak pernah dijadikan src.
        if ($path === '' || str_contains($path, '..') || ! str_starts_with($path, 'location-pages/')) {
            return null;
        }

        if (! Storage::disk('public')->exists($path)) {
            return null;
        }

        return [
            'src' => 'storage/'.$path,
            'alt' => $this->cleanText($page->poster_alt) ?? $page->title,
            'width' => $page->poster_width > 0 ? $page->poster_width : null,
            'height' => $page->poster_height > 0 ? $page->poster_height : null,
            // MIME hasil deteksi isi berkas, bukan tebakan dari ekstensi.
            'type' => $this->cleanText($page->poster_mime_type),
        ];
    }

    /**
     * @return array{text: string, url: string}|null
     */
    protected function ctaPayload(LocationPage $page): ?array
    {
        $text = $this->cleanText($page->button_text);
        $url = SafeUrl::sanitizeExternal($page->button_url);

        if ($text === null || $url === null) {
            return null;
        }

        return ['text' => $text, 'url' => $url];
    }

    /**
     * @return array<string, mixed>
     */
    protected function locationCard(Location $location): array
    {
        $whatsapp = WhatsAppNumber::normalize($location->whatsapp_number);
        $area = $location->area;
        $group = $location->parentGroup();
        $province = $location->parentProvince();

        return [
            'id' => $location->id,
            'name' => $location->name,
            'area_name' => $area?->name ?? '',
            'group_name' => $group?->name ?? '',
            'province_name' => $province?->name ?? '',
            'full_address' => $this->cleanText($location->full_address),
            'landmark' => $this->cleanText($location->landmark),
            'operational_hours_text' => $this->cleanText($location->operational_hours_text),
            'village' => $this->cleanText($location->village),
            'district' => $this->cleanText($location->district),
            'city_regency' => $this->cleanText($location->city_regency),
            'postal_code' => $this->cleanText($location->postal_code),
            // Sudah dipastikan aman; koordinat diutamakan atas link manual.
            'maps_url' => $location->safeMapsUrl(),
            'whatsapp_url' => WhatsAppNumber::toUrl($whatsapp),
            'latitude' => MapsUrl::hasValidCoordinates($location->latitude, $location->longitude)
                ? (float) $location->latitude
                : null,
            'longitude' => MapsUrl::hasValidCoordinates($location->latitude, $location->longitude)
                ? (float) $location->longitude
                : null,
            // imagePayload() mengembalikan null untuk foto yang berkasnya
            // tidak ada atau path-nya mencurigakan; entri itu dibuang di sini.
            'images' => $location->images
                ->map(fn (LocationImage $image): ?array => $this->imagePayload($image))
                ->filter()
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function imagePayload(LocationImage $image): ?array
    {
        $path = is_string($image->image_path) ? ltrim(trim($image->image_path), '/') : '';

        // Path aneh tidak pernah dijadikan src.
        if ($path === '' || str_contains($path, '..') || ! str_starts_with($path, 'locations/')) {
            return null;
        }

        if ($image->width < 1 || $image->height < 1) {
            return null;
        }

        /*
         | Berkasnya harus benar-benar ada. Baris database bisa saja tertinggal
         | setelah file dibersihkan di luar aplikasi; tanpa pemeriksaan ini
         | halaman akan memuat gambar 404 dan -- lebih buruk -- menyebutkannya
         | di structured data sebagai foto yang sah.
         */
        if (! Storage::disk('public')->exists($path)) {
            return null;
        }

        return [
            'src' => 'storage/'.$path,
            'alt' => (string) $image->alt_text,
            'caption' => $this->cleanText($image->caption),
            'width' => $image->width,
            'height' => $image->height,
            'is_cover' => (bool) $image->is_cover,
        ];
    }

    // ------------------------------------------------------------- homepage

    /**
     * Halaman slug untuk section lokasi di homepage.
     *
     * Hanya halaman yang tampil DAN benar-benar punya gerobak layak tampil.
     * Halaman sorotan didahulukan.
     *
     * @return list<array<string, mixed>>
     */
    public function visiblePagesForHomepage(int $limit = 12): array
    {
        return Cache::remember(
            $this->key('homepage.'.$limit),
            self::TTL_SECONDS,
            fn (): array => $this->guardMissingTable(function () use ($limit): array {
                $pages = LocationPage::query()
                    ->publiclyVisible()
                    ->hasVisibleLocations()
                    ->withCount(['locations as visible_locations_count' => fn (Builder $query) => $query
                        ->effectivelyVisible()])
                    ->orderByDesc('is_featured')
                    ->ordered()
                    ->limit($limit)
                    ->get();

                return $pages->map(fn (LocationPage $page): array => [
                    'id' => $page->id,
                    'title' => $page->title,
                    'slug' => $page->slug,
                    'short_description' => $this->cleanText($page->short_description),
                    'period_text' => $this->cleanText($page->period_text),
                    'is_featured' => (bool) $page->is_featured,
                    'location_count' => (int) $page->visible_locations_count,
                    'poster' => $this->posterPayload($page),
                    'url' => route('location-pages.show', $page->slug),
                ])->all();
            }, []),
        );
    }

    // -------------------------------------------------------------- sitemap

    /**
     * URL halaman slug yang layak masuk sitemap.
     *
     * Master hierarki tidak pernah muncul: ia tidak punya URL publik.
     *
     * @return list<array<string, string>>
     */
    public function sitemapUrls(): array
    {
        return Cache::remember(
            $this->key('sitemap'),
            self::TTL_SECONDS,
            fn (): array => $this->guardMissingTable(function (): array {
                return LocationPage::query()
                    ->publiclyVisible()
                    ->hasVisibleLocations()
                    ->ordered()
                    ->get()
                    ->map(fn (LocationPage $page): array => [
                        'loc' => route('location-pages.show', $page->slug),
                        'lastmod' => $page->updated_at?->toAtomString() ?? '',
                        'priority' => $page->is_featured ? '0.9' : '0.7',
                    ])
                    ->all();
            }, []),
        );
    }

    // --------------------------------------------------------------- helpers

    protected function cleanText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = trim($value);

        return $text === '' ? null : $text;
    }
}
