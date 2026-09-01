<?php

namespace App\Services;

use App\Models\Location;
use App\Models\LocationArea;
use App\Models\LocationAreaSlugRedirect;
use App\Models\LocationGroup;
use App\Models\LocationImage;
use App\Models\LocationProvinceSlugRedirect;
use App\Models\Province;
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
 * Hierarki: Province -> LocationGroup -> LocationArea -> Location -> Image.
 * Kota/Grup TIDAK punya URL sendiri; ia hanya menjadi heading pengelompokan
 * di halaman provinsi.
 *
 * Strategi cache -- VERSI, bukan penghapusan per key:
 * Setiap key data memuat nomor versi. Satu perubahan apa pun pada provinsi,
 * grup, area, gerobak, foto, atau redirect menaikkan versi, sehingga SELURUH
 * turunan langsung basi secara bersamaan. Pendekatan ini tidak pernah
 * mengosongkan seluruh cache aplikasi, dan tidak perlu menebak key mana saja
 * yang harus dibuang ketika induknya berubah.
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
     * Batas foto yang ditampilkan di galeri area.
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
     * belum ada data, dan kejadiannya dicatat sebagai warning -- bukan
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

    // ------------------------------------------------------ daftar provinsi

    /**
     * Provinsi yang layak tampil publik: terbit, dan benar-benar memiliki
     * minimal satu Area tampil yang berisi gerobak tampil.
     *
     * Query iklan (utm_*, gclid, fbclid) tidak pernah menyentuh key ini.
     *
     * @return list<array<string, mixed>>
     */
    public function visibleProvinces(): array
    {
        return Cache::remember(
            $this->key('provinces'),
            self::TTL_SECONDS,
            fn (): array => $this->guardMissingTable(fn (): array => $this->queryVisibleProvinces(), []),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function queryVisibleProvinces(): array
    {
        $provinces = Province::query()
            ->publiclyVisible()
            ->hasVisibleAreas()
            ->ordered()
            ->get();

        // Hitung Area tampil per provinsi dalam SATU query tambahan, bukan
        // satu query per provinsi.
        $areaCounts = $this->visibleAreaCountsByProvince($provinces->pluck('id')->all());

        return $provinces->map(fn (Province $province): array => [
            'id' => $province->id,
            'name' => $province->name,
            'slug' => $province->slug,
            'url' => route('locations.province', $province->slug),
            'headline' => $province->publicHeadline(),
            'description' => $province->publicDescription(),
            'area_count' => (int) ($areaCounts[$province->id] ?? 0),
        ])->all();
    }

    /**
     * Jumlah Area tampil per provinsi, satu query untuk semuanya.
     *
     * @param  list<int>  $provinceIds
     * @return array<int, int>
     */
    protected function visibleAreaCountsByProvince(array $provinceIds): array
    {
        if ($provinceIds === []) {
            return [];
        }

        return LocationArea::query()
            ->publiclyVisible()
            ->hasVisibleLocations()
            ->join('location_groups', 'location_groups.id', '=', 'location_areas.location_group_id')
            ->whereNull('location_groups.deleted_at')
            ->where('location_groups.is_active', true)
            ->whereIn('location_groups.province_id', $provinceIds)
            ->selectRaw('location_groups.province_id as province_id, count(*) as aggregate')
            ->groupBy('location_groups.province_id')
            ->pluck('aggregate', 'province_id')
            ->map(fn ($value): int => (int) $value)
            ->all();
    }

    // ----------------------------------------------- resolusi slug provinsi

    /**
     * Cari provinsi berdasarkan slug URL.
     *
     * Hasil:
     *   ['status' => 'ok',       'province' => array]  -> render halaman
     *   ['status' => 'redirect', 'slug'     => string] -> 301 ke slug canonical
     *   ['status' => 'missing']                        -> 404
     *
     * @return array<string, mixed>
     */
    public function resolveProvince(string $slug): array
    {
        $slug = trim($slug);

        if ($slug === '') {
            return ['status' => 'missing'];
        }

        $payload = $this->provincePayload($slug);

        if ($payload !== null) {
            return ['status' => 'ok', 'province' => $payload];
        }

        $canonical = $this->canonicalProvinceSlug($slug);

        // Redirect ke diri sendiri berarti data tidak konsisten -- lebih baik
        // 404 daripada memicu loop redirect di browser.
        if ($canonical !== null && $canonical !== $slug) {
            return ['status' => 'redirect', 'slug' => $canonical];
        }

        return ['status' => 'missing'];
    }

    /**
     * Slug canonical untuk sebuah slug provinsi lama, bila provinsinya masih
     * publik.
     */
    protected function canonicalProvinceSlug(string $slug): ?string
    {
        return Cache::remember(
            $this->key('redirect.province.'.sha1($slug)),
            self::TTL_SECONDS,
            fn (): ?string => $this->guardMissingTable(function () use ($slug): ?string {
                $redirect = LocationProvinceSlugRedirect::query()
                    ->where('old_slug', $slug)
                    ->with('province')
                    ->first();

                $province = $redirect?->province;

                // Slug lama milik provinsi yang sudah tidak publik tetap 404.
                return $province?->isPubliclyVisible() ? $province->slug : null;
            }, null),
        );
    }

    // ------------------------------------------------------ payload provinsi

    /**
     * Isi halaman provinsi: Kota/Grup sebagai heading, Area sebagai kartu.
     *
     * @return array<string, mixed>|null
     */
    public function provincePayload(string $slug): ?array
    {
        return Cache::remember(
            $this->key('province.'.sha1($slug)),
            self::TTL_SECONDS,
            fn (): ?array => $this->guardMissingTable(function () use ($slug): ?array {
                $province = Province::query()
                    ->publiclyVisible()
                    ->where('slug', $slug)
                    ->first();

                if ($province === null) {
                    return null;
                }

                /*
                 | Jumlah query TETAP berapa pun banyaknya grup dan area:
                 | 1 provinsi + 1 grup + 1 area (nested eager load, sekaligus
                 | menghitung gerobak tampil).
                 */
                $groups = LocationGroup::query()
                    ->where('province_id', $province->id)
                    ->active()
                    ->hasVisibleAreas()
                    ->ordered()
                    ->with([
                        'areas' => fn ($query) => $query
                            ->publiclyVisible()
                            ->hasVisibleLocations()
                            ->ordered()
                            ->withCount([
                                'locations as visible_locations_count' => fn ($inner) => $inner->publiclyVisible(),
                            ]),
                    ])
                    ->get();

                $groupPayloads = $groups
                    ->map(fn (LocationGroup $group): array => [
                        'id' => $group->id,
                        'name' => $group->name,
                        'type' => $group->type?->value,
                        'type_label' => $group->typeLabel(),
                        'description' => $this->cleanText($group->description),
                        'areas' => $group->areas
                            ->map(fn (LocationArea $area): array => $this->areaCard($area, $province))
                            ->all(),
                    ])
                    // Grup yang seluruh areanya tersaring habis tidak dirender.
                    ->filter(fn (array $group): bool => $group['areas'] !== [])
                    ->values()
                    ->all();

                return [
                    'id' => $province->id,
                    'name' => $province->name,
                    'slug' => $province->slug,
                    'url' => route('locations.province', $province->slug),
                    'headline' => $province->publicHeadline(),
                    'description' => $province->publicDescription(),
                    'seo_title' => $this->provinceSeoTitle($province),
                    'seo_description' => $this->provinceSeoDescription($province),
                    'groups' => $groupPayloads,
                    'area_count' => array_sum(array_map(
                        fn (array $group): int => count($group['areas']),
                        $groupPayloads,
                    )),
                ];
            }, null),
        );
    }

    /**
     * Kartu satu Area pada halaman provinsi atau homepage.
     *
     * @return array<string, mixed>
     */
    protected function areaCard(LocationArea $area, Province $province): array
    {
        return [
            'id' => $area->id,
            'name' => $area->name,
            'slug' => $area->slug,
            'url' => route('locations.area', [$province->slug, $area->slug]),
            'headline' => $area->publicHeadline(),
            'description' => $area->publicDescription(),
            'province_name' => $province->name,
            'province_slug' => $province->slug,
            'location_count' => (int) ($area->visible_locations_count ?? 0),
        ];
    }

    // --------------------------------------------------- resolusi slug area

    /**
     * Cari Area berdasarkan pasangan slug provinsi + slug area.
     *
     * Slug Area unik GLOBAL, jadi Area selalu bisa ditemukan dari satu segmen
     * saja. Segmen provinsi tetap DIVERIFIKASI -- bukan sekadar hiasan:
     *
     *   - cocok                        -> render
     *   - slug lama provinsi yang sama  -> 301 ke canonical
     *   - provinsi lain / tidak dikenal -> 404 (kombinasi tidak sah)
     *
     * Slug Area lama juga dijawab 301, dan bila KEDUA segmen sekaligus usang
     * hasilnya tetap SATU lompatan ke canonical terbaru.
     *
     * Hasil:
     *   ['status' => 'ok',       'area' => array]
     *   ['status' => 'redirect', 'province' => string, 'area' => string]
     *   ['status' => 'missing']
     *
     * @return array<string, mixed>
     */
    public function resolveArea(string $provinceSlug, string $areaSlug): array
    {
        $provinceSlug = trim($provinceSlug);
        $areaSlug = trim($areaSlug);

        if ($provinceSlug === '' || $areaSlug === '') {
            return ['status' => 'missing'];
        }

        $payload = $this->areaPayload($areaSlug);

        if ($payload === null) {
            // Slug area mungkin sudah usang -- telusuri riwayatnya.
            $canonicalAreaSlug = $this->canonicalAreaSlug($areaSlug);

            if ($canonicalAreaSlug === null || $canonicalAreaSlug === $areaSlug) {
                return ['status' => 'missing'];
            }

            $payload = $this->areaPayload($canonicalAreaSlug);

            if ($payload === null) {
                return ['status' => 'missing'];
            }
        }

        $canonicalProvince = $payload['province_slug'];
        $canonicalArea = $payload['slug'];

        // Kedua segmen sudah canonical -> render langsung.
        if ($provinceSlug === $canonicalProvince && $areaSlug === $canonicalArea) {
            return ['status' => 'ok', 'area' => $payload];
        }

        /*
         | Segmen provinsi tidak cocok. HANYA slug lama milik provinsi yang
         | sama yang layak dialihkan; slug provinsi lain berarti kombinasi
         | karangan dan harus 404, supaya tidak lahir banyak URL berbeda yang
         | semuanya menampilkan halaman sama.
         */
        if ($provinceSlug !== $canonicalProvince
            && $this->canonicalProvinceSlug($provinceSlug) !== $canonicalProvince) {
            return ['status' => 'missing'];
        }

        return [
            'status' => 'redirect',
            'province' => $canonicalProvince,
            'area' => $canonicalArea,
        ];
    }

    /**
     * Slug canonical untuk sebuah slug area lama.
     */
    protected function canonicalAreaSlug(string $slug): ?string
    {
        return Cache::remember(
            $this->key('redirect.area.'.sha1($slug)),
            self::TTL_SECONDS,
            fn (): ?string => $this->guardMissingTable(function () use ($slug): ?string {
                $redirect = LocationAreaSlugRedirect::query()
                    ->where('old_slug', $slug)
                    ->with('area')
                    ->first();

                return $redirect?->area?->slug;
            }, null),
        );
    }

    // ---------------------------------------------------------- payload area

    /**
     * Seluruh isi halaman area dalam satu array siap render.
     *
     * Kunci cache memakai slug AREA saja karena slug area unik global.
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
                    ->effectivelyVisible()
                    ->where('slug', $slug)
                    ->with('group.province')
                    ->first();

                $group = $area?->group;
                $province = $group?->province;

                if ($area === null || $group === null || $province === null) {
                    return null;
                }

                /*
                 | Jumlah query TETAP berapa pun banyaknya gerobak:
                 | 1 area (+grup +provinsi lewat eager load) + 1 gerobak +
                 | 1 foto. Foto di-eager load supaya tidak ada query tambahan
                 | per gerobak.
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
                    'url' => route('locations.area', [$province->slug, $area->slug]),
                    'headline' => $area->publicHeadline(),
                    'description' => $area->publicDescription(),
                    'group_name' => $group->name,
                    'group_type_label' => $group->typeLabel(),
                    'province_name' => $province->name,
                    'province_slug' => $province->slug,
                    'province_url' => route('locations.province', $province->slug),
                    'seo_title' => $this->areaSeoTitle($area),
                    'seo_description' => $this->areaSeoDescription($area),
                    'locations' => $cards,
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
     * Galeri area: foto milik gerobak di area INI saja.
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

    // ------------------------------------------------------------- homepage

    /**
     * Area tampil untuk section lokasi di homepage.
     *
     * Yang ditautkan adalah AREA, bukan Kota/Grup: grup tidak punya halaman
     * sendiri, jadi menautkannya hanya akan menghasilkan tautan mati.
     *
     * @return list<array<string, mixed>>
     */
    public function visibleAreasForHomepage(int $limit = 12): array
    {
        return Cache::remember(
            $this->key('homepage_areas.'.$limit),
            self::TTL_SECONDS,
            fn (): array => $this->guardMissingTable(function () use ($limit): array {
                /*
                 | JOIN, bukan eager load: homepage punya anggaran query yang
                 | ketat dan `with('group.province')` akan menambah dua query
                 | lagi hanya untuk mengambil nama dan slug provinsi.
                 */
                $areas = LocationArea::query()
                    ->effectivelyVisible()
                    ->hasVisibleLocations()
                    ->join('location_groups', 'location_groups.id', '=', 'location_areas.location_group_id')
                    ->join('provinces', 'provinces.id', '=', 'location_groups.province_id')
                    ->select([
                        'location_areas.*',
                        'provinces.name as province_name',
                        'provinces.slug as province_slug',
                    ])
                    ->withCount([
                        'locations as visible_locations_count' => fn ($inner) => $inner->publiclyVisible(),
                    ])
                    ->ordered()
                    ->limit($limit)
                    ->get();

                return $areas
                    ->map(fn (LocationArea $area): array => [
                        'id' => $area->id,
                        'name' => $area->name,
                        'slug' => $area->slug,
                        'url' => route('locations.area', [$area->province_slug, $area->slug]),
                        'headline' => $area->publicHeadline(),
                        'description' => $area->publicDescription(),
                        'province_name' => $area->province_name,
                        'province_slug' => $area->province_slug,
                        'location_count' => (int) ($area->visible_locations_count ?? 0),
                    ])
                    ->values()
                    ->all();
            }, []),
        );
    }

    // -------------------------------------------------------------- sitemap

    /**
     * URL publik untuk sitemap: provinsi tampil dan area tampil di bawahnya.
     *
     * Draft, nonaktif, terjadwal, dan terhapus tidak pernah masuk karena
     * seluruhnya sudah tersaring di payload yang dipakai ulang di sini.
     *
     * @return list<array<string, string>>
     */
    public function sitemapUrls(): array
    {
        $urls = [];

        foreach ($this->visibleProvinces() as $province) {
            $urls[] = ['loc' => $province['url'], 'priority' => '0.7'];

            $payload = $this->provincePayload($province['slug']);

            foreach ($payload['groups'] ?? [] as $group) {
                foreach ($group['areas'] as $area) {
                    $urls[] = ['loc' => $area['url'], 'priority' => '0.6'];
                }
            }
        }

        return $urls;
    }

    // ------------------------------------------------------------------ SEO

    protected function areaSeoTitle(LocationArea $area): string
    {
        $title = is_string($area->seo_title) ? trim($area->seo_title) : '';

        return $title !== '' ? $title : $area->publicHeadline().' | DIMDUM';
    }

    protected function areaSeoDescription(LocationArea $area): string
    {
        $description = is_string($area->seo_description) ? trim($area->seo_description) : '';

        return $description !== '' ? $description : $area->publicDescription();
    }

    protected function provinceSeoTitle(Province $province): string
    {
        $title = is_string($province->seo_title) ? trim($province->seo_title) : '';

        return $title !== '' ? $title : 'Lokasi Gerobak DIMDUM di '.$province->name.' | DIMDUM';
    }

    protected function provinceSeoDescription(Province $province): string
    {
        $description = is_string($province->seo_description) ? trim($province->seo_description) : '';

        return $description !== '' ? $description : $province->publicDescription();
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
