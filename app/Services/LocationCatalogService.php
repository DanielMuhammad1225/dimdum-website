<?php

namespace App\Services;

use App\Models\Location;
use App\Models\LocationArea;
use App\Models\LocationAreaSlugRedirect;
use App\Models\LocationImage;
use App\Support\MapsUrl;
use App\Support\WhatsAppNumber;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Satu-satunya sumber data lokasi untuk halaman publik.
 *
 * Controller memanggil service ini; Blade hanya menerima array yang sudah
 * jadi. Tidak ada query di Blade dan tidak ada model Eloquent hidup yang
 * disimpan di cache.
 *
 * Strategi cache -- VERSI, bukan penghapusan per key:
 * Setiap key data memuat nomor versi. Satu perubahan apa pun pada wilayah,
 * gerobak, foto, atau redirect menaikkan versi, sehingga SELURUH turunan
 * (index, halaman tiap wilayah, dan daftar di homepage) langsung basi secara
 * bersamaan. Pendekatan ini tidak pernah mengosongkan seluruh cache
 * aplikasi, dan tidak perlu menebak key mana saja yang harus dibuang ketika
 * induknya berubah.
 * Entri versi lama kedaluwarsa sendiri lewat TTL.
 */
class LocationCatalogService
{
    public const VERSION_KEY = 'locations.cache_version';

    /**
     * TTL data. Bukan penentu kesegaran -- versi yang menentukan -- tetapi
     * memastikan entri versi lama tidak menumpuk selamanya.
     */
    public const TTL_SECONDS = 3600;

    /**
     * Batas foto yang ditampilkan di galeri wilayah.
     */
    public const MAX_AREA_GALLERY_IMAGES = 12;

    /**
     * Naikkan versi cache lokasi.
     *
     * Cache::add + increment dipilih supaya operasinya atomik pada driver
     * yang mendukung (Redis/Memcached), bukan baca-lalu-tulis yang bisa
     * saling menimpa.
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
        return 'locations.v'.self::cacheVersion().'.'.$suffix;
    }

    /**
     * Jalankan query katalog dengan fallback yang SANGAT sempit.
     *
     * Hanya satu kondisi yang ditoleransi: tabel modul lokasi belum dibuat
     * (migration belum dijalankan). Halaman publik lalu berperilaku seolah
     * belum ada wilayah, dan kejadiannya dicatat sebagai warning -- bukan
     * ditelan diam-diam.
     *
     * Error database lain (koneksi putus, kredensial salah, deadlock)
     * DILEMPAR ULANG. Menelannya akan membuat production tampak sehat
     * padahal databasenya bermasalah.
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

            Log::warning('Tabel modul lokasi belum tersedia, halaman publik menampilkan daftar kosong.', [
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

    // ------------------------------------------------------------- daftar

    /**
     * Wilayah yang layak tampil publik: aktif, sudah terbit, dan benar-benar
     * memiliki minimal satu gerobak yang tampil.
     *
     * Query iklan (utm_*, gclid, fbclid) tidak pernah menyentuh key ini.
     *
     * @return list<array<string, mixed>>
     */
    public function visibleAreas(): array
    {
        return Cache::remember($this->key('areas'), self::TTL_SECONDS, function (): array {
            return $this->guardMissingTable(fn (): array => $this->queryVisibleAreas(), []);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function queryVisibleAreas(): array
    {
        $areas = LocationArea::query()
            ->publiclyVisible()
            ->hasVisibleLocations()
            ->ordered()
            ->withCount([
                'locations as visible_locations_count' => fn ($query) => $query->publiclyVisible(),
            ])
            ->get();

        return $areas->map(fn (LocationArea $area): array => [
            'id' => $area->id,
            'name' => $area->name,
            'slug' => $area->slug,
            'url' => route('locations.area', $area->slug),
            'headline' => $area->publicHeadline(),
            'description' => $area->publicDescription(),
            'city_regency' => $area->city_regency,
            'province' => $area->province,
            'location_count' => (int) $area->visible_locations_count,
        ])->all();
    }

    // ------------------------------------------------- resolusi slug wilayah

    /**
     * Cari wilayah berdasarkan slug URL.
     *
     * Hasil:
     *   ['status' => 'ok',       'area' => array]     -> render halaman
     *   ['status' => 'redirect', 'slug'  => string]   -> 301 ke slug canonical
     *   ['status' => 'missing']                       -> 404
     *
     * @return array<string, mixed>
     */
    public function resolveArea(string $slug): array
    {
        $slug = trim($slug);

        if ($slug === '') {
            return ['status' => 'missing'];
        }

        $payload = $this->areaPayload($slug);

        if ($payload !== null) {
            return ['status' => 'ok', 'area' => $payload];
        }

        $canonical = $this->canonicalSlugForOldSlug($slug);

        // Redirect ke diri sendiri berarti data tidak konsisten -- lebih baik
        // 404 daripada memicu loop redirect di browser.
        if ($canonical !== null && $canonical !== $slug) {
            return ['status' => 'redirect', 'slug' => $canonical];
        }

        return ['status' => 'missing'];
    }

    /**
     * Slug canonical untuk sebuah slug lama, bila wilayahnya masih publik.
     */
    protected function canonicalSlugForOldSlug(string $slug): ?string
    {
        return Cache::remember(
            $this->key('redirect.'.sha1($slug)),
            self::TTL_SECONDS,
            fn (): ?string => $this->guardMissingTable(function () use ($slug): ?string {
                $redirect = LocationAreaSlugRedirect::query()
                    ->where('old_slug', $slug)
                    ->with('area')
                    ->first();

                $area = $redirect?->area;

                // Slug lama milik wilayah yang sudah tidak publik tetap 404.
                return $area?->isPubliclyVisible() ? $area->slug : null;
            }, null),
        );
    }

    // ------------------------------------------------------ payload wilayah

    /**
     * Seluruh isi halaman wilayah dalam satu array siap render.
     *
     * @return array<string, mixed>|null
     */
    public function areaPayload(string $slug): ?array
    {
        return Cache::remember(
            $this->key('area.'.sha1($slug)),
            self::TTL_SECONDS,
            fn (): ?array => $this->guardMissingTable(function () use ($slug): ?array {
                $area = LocationArea::query()
                    ->publiclyVisible()
                    ->where('slug', $slug)
                    ->first();

                if ($area === null) {
                    return null;
                }

                /*
                 | Jumlah query TETAP berapa pun banyaknya gerobak:
                 | 1 wilayah + 1 gerobak + 1 foto. Foto di-eager load supaya
                 | tidak ada query tambahan per gerobak.
                 */
                $locations = Location::query()
                    ->where('location_area_id', $area->id)
                    ->publiclyVisible()
                    ->ordered()
                    ->with(['images' => fn ($query) => $query->ordered()])
                    ->get();

                $cards = $locations
                    ->map(fn (Location $location): array => $this->locationCard($location))
                    ->all();

                return [
                    'id' => $area->id,
                    'name' => $area->name,
                    'slug' => $area->slug,
                    'url' => route('locations.area', $area->slug),
                    'headline' => $area->publicHeadline(),
                    'description' => $area->publicDescription(),
                    'city_regency' => $area->city_regency,
                    'province' => $area->province,
                    'seo_title' => $this->seoTitle($area),
                    'seo_description' => $this->seoDescription($area),
                    'locations' => $cards,
                    'filters' => $this->filterGroups($cards),
                    'gallery' => $this->areaGallery($locations),
                ];
            }, null),
        );
    }

    /**
     * Kartu satu gerobak. Field kosong dibuang supaya Blade tidak pernah
     * mencetak label tanpa isi.
     *
     * @return array<string, mixed>
     */
    protected function locationCard(Location $location): array
    {
        $whatsapp = WhatsAppNumber::normalize($location->whatsapp_number);

        return [
            'id' => $location->id,
            'name' => $location->name,
            'slug' => $location->slug,
            'filter_group' => $location->filterGroup(),
            'full_address' => $this->cleanText($location->full_address),
            'landmark' => $this->cleanText($location->landmark),
            'operational_hours_text' => $this->cleanText($location->operational_hours_text),
            'district' => $this->cleanText($location->district),
            'city_regency' => $this->cleanText($location->city_regency),
            'province' => $this->cleanText($location->province),
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
         |
         | Pemeriksaan berjalan di dalam payload yang di-cache, jadi hanya
         | sekali per versi cache, bukan setiap request.
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

    /**
     * Galeri wilayah: foto milik gerobak di wilayah INI saja.
     *
     * Cover setiap gerobak didahulukan supaya galeri mewakili sebanyak
     * mungkin titik, bukan menumpuk foto dari satu gerobak.
     *
     * @param  Collection<int, Location>  $locations
     * @return list<array<string, mixed>>
     */
    protected function areaGallery($locations): array
    {
        $covers = [];
        $rest = [];

        foreach ($locations as $location) {
            $first = true;

            foreach ($location->images as $image) {
                $payload = $this->imagePayload($image);

                if ($payload === null) {
                    continue;
                }

                $payload['location_name'] = $location->name;

                if ($first) {
                    $covers[] = $payload;
                    $first = false;

                    continue;
                }

                $rest[] = $payload;
            }
        }

        return array_slice([...$covers, ...$rest], 0, self::MAX_AREA_GALLERY_IMAGES);
    }

    /**
     * Kelompok filter yang benar-benar ada pada data.
     *
     * @param  list<array<string, mixed>>  $cards
     * @return list<string>
     */
    protected function filterGroups(array $cards): array
    {
        $groups = [];

        foreach ($cards as $card) {
            $group = $card['filter_group'] ?? null;

            if (is_string($group) && $group !== '') {
                $groups[$group] = true;
            }
        }

        $groups = array_keys($groups);
        sort($groups, SORT_NATURAL | SORT_FLAG_CASE);

        // Satu kelompok tidak perlu difilter -- UI-nya justru membingungkan.
        return count($groups) > 1 ? $groups : [];
    }

    // ---------------------------------------------------------------- SEO

    protected function seoTitle(LocationArea $area): string
    {
        $title = is_string($area->seo_title) ? trim($area->seo_title) : '';

        return $title !== '' ? $title : $area->publicHeadline().' | DIMDUM';
    }

    protected function seoDescription(LocationArea $area): string
    {
        $description = is_string($area->seo_description) ? trim($area->seo_description) : '';

        return $description !== '' ? $description : $area->publicDescription();
    }

    protected function cleanText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
