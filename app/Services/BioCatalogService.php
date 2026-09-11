<?php

namespace App\Services;

use App\Enums\BioButton;
use App\Enums\BioLocationMode;
use App\Enums\ProductCategory;
use App\Models\BioSetting;
use App\Models\Location;
use App\Models\LocationGroup;
use App\Models\LocationPage;
use App\Models\Product;
use App\Models\SocialLink;
use App\Support\SafeUrl;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Satu-satunya sumber data halaman Bio: /bio, /bio/lokasi, /bio/produk.
 *
 * Controller memanggil service ini; Blade hanya menerima array yang sudah
 * jadi. Tidak ada query di Blade dan tidak ada model Eloquent di cache.
 *
 * STRATEGI CACHE -- versi gabungan, bukan kait baru di model lain.
 *
 * Payload Bio bergantung pada tiga sumber, dan dua di antaranya SUDAH punya
 * versi cache yang dinaikkan setiap kali datanya berubah:
 *
 *   bio.cache_version               Pengaturan Bio, isi/status/urutan social
 *                                   media. Milik service ini.
 *   products.cache_version          Seluruh perubahan produk, termasuk
 *                                   show_on_bio dan reorder.
 *   location_pages.cache_version    Halaman slug (termasuk show_on_bio),
 *                                   dan SELURUH hierarki -- Provinsi, Kota/
 *                                   Grup, Area, Gerobak, beserta reorder-nya.
 *
 * Kunci setiap payload memuat versi dari sumber yang ia pakai, sehingga
 * perubahan di sumber mana pun membuat kuncinya berganti dengan sendirinya.
 * Tidak satu pun model produk atau hierarki perlu tahu bahwa halaman Bio ada.
 * Cache aplikasi tidak pernah dikosongkan seluruhnya.
 */
class BioCatalogService
{
    public const VERSION_KEY = 'bio.cache_version';

    public const TTL_SECONDS = 3600;

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

    // ----------------------------------------------------------- pengaturan

    /**
     * Pengaturan Bio yang sudah dinormalisasi, plus social media aktif.
     *
     * Judul dan deskripsi bernilai null bila admin belum mengisinya; fallback
     * ke nama dan tagline brand dikerjakan controller, bukan di sini --
     * supaya perubahan Pengaturan Website tidak terkunci di dalam cache Bio.
     *
     * @return array{
     *     active: bool,
     *     title: string|null,
     *     description: string|null,
     *     location_mode: string,
     *     buttons: list<array{key: string, label: string}>,
     *     whatsapp_url: string|null,
     *     social: list<array{name: string, url: string, icon: string}>
     * }
     */
    public function settings(): array
    {
        return Cache::remember(
            'bio.v'.self::cacheVersion().'.settings',
            self::TTL_SECONDS,
            fn (): array => $this->guardMissingTable(fn (): array => $this->buildSettings(), self::inactive()),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected static function inactive(): array
    {
        return [
            'active' => false,
            'title' => null,
            'description' => null,
            'location_mode' => BioLocationMode::AllActiveLocations->value,
            'buttons' => [],
            'whatsapp_url' => null,
            'social' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildSettings(): array
    {
        $record = BioSetting::query()->where('key', BioSetting::SINGLETON_KEY)->first();

        if (! $record instanceof BioSetting) {
            return self::inactive();
        }

        $whatsappUrl = BioSettingsService::whatsappUrl($record->whatsapp_number, $record->whatsapp_message);

        $buttons = [];

        foreach (BioSettingsService::normalizeButtons($record->buttons) as $button) {
            if (! $button['visible']) {
                continue;
            }

            // Tombol WhatsApp tanpa nomor sah TIDAK dirender sama sekali --
            // bukan dirender dengan href="#".
            if ($button['key'] === BioButton::WhatsApp->value && $whatsappUrl === null) {
                continue;
            }

            $buttons[] = ['key' => $button['key'], 'label' => $button['label']];
        }

        return [
            'active' => (bool) $record->is_active,
            'title' => $this->cleanText($record->title),
            'description' => $this->cleanText($record->description),
            'location_mode' => BioSettingsService::resolveLocationMode($record->location_mode)->value,
            'buttons' => $buttons,
            'whatsapp_url' => $whatsappUrl,
            'social' => $this->socialLinks(),
        ];
    }

    /**
     * Social media aktif sesuai urutan admin.
     *
     * URL diperiksa ULANG di sini: baris yang lolos ke database lewat jalur
     * lain dengan URL berbahaya tidak pernah dicetak sebagai href.
     *
     * @return list<array{name: string, url: string, icon: string}>
     */
    protected function socialLinks(): array
    {
        $links = [];

        foreach (SocialLink::query()->active()->ordered()->get() as $link) {
            $url = SafeUrl::sanitizeWeb($link->url);
            $name = $this->cleanText($link->name);

            if ($url === null || $name === null) {
                continue;
            }

            $links[] = [
                'name' => $name,
                'url' => $url,
                'icon' => $link->iconCase()->value,
            ];
        }

        return $links;
    }

    /**
     * Tombol siap tampil, dengan tujuan yang ditentukan KODE.
     *
     * Dipanggil per request, di luar cache: route() menghasilkan URL absolut
     * mengikuti host request, dan menyimpannya di cache akan membekukan host
     * dari request pertama -- pola yang dulu membuat panel tampak logout.
     *
     * @param  array<string, mixed>  $settings
     * @return list<array{key: string, label: string, url: string, external: bool}>
     */
    public function buttons(array $settings): array
    {
        $buttons = [];

        foreach ($settings['buttons'] ?? [] as $button) {
            $url = match ($button['key'] ?? null) {
                BioButton::Location->value => route('bio.locations'),
                BioButton::Menu->value => route('bio.products'),
                BioButton::WhatsApp->value => $settings['whatsapp_url'] ?? null,
                default => null,
            };

            if (! is_string($url) || $url === '') {
                continue;
            }

            $buttons[] = [
                'key' => $button['key'],
                'label' => $button['label'],
                'url' => $url,
                'external' => $button['key'] === BioButton::WhatsApp->value,
            ];
        }

        return $buttons;
    }

    // --------------------------------------------------------------- lokasi

    /**
     * Isi /bio/lokasi menurut mode yang dipilih admin.
     *
     * @return array{
     *     mode: string,
     *     items: list<array<string, mixed>>,
     *     provinces: list<array{id: int, name: string}>,
     *     groups: list<array{id: int, name: string, province_id: int, province_name: string}>
     * }
     */
    public function locations(string $mode): array
    {
        $mode = BioSettingsService::resolveLocationMode($mode);

        return Cache::remember(
            'bio.v'.self::cacheVersion().'.l'.LocationPageCatalogService::cacheVersion().'.locations.'.$mode->value,
            self::TTL_SECONDS,
            fn (): array => $this->guardMissingTable(
                fn (): array => $mode === BioLocationMode::LocationPages
                    ? $this->buildLocationPages()
                    : $this->buildAllActiveLocations(),
                ['mode' => $mode->value, 'items' => [], 'provinces' => [], 'groups' => []],
            ),
        );
    }

    /**
     * Mode all_active_locations: seluruh gerobak yang layak tampil.
     *
     * Kelayakannya memakai Location::effectivelyVisible() -- definisi yang
     * sama dengan halaman slug -- jadi gerobak hanya muncul bila dirinya DAN
     * seluruh induknya aktif serta tidak terhapus.
     *
     * SATU query. Nama Area, Kota/Grup, dan Provinsi diambil lewat join yang
     * memang sudah dibutuhkan untuk mengurutkan menurut master, bukan lewat
     * eager loading tiga relasi.
     *
     * @return array<string, mixed>
     */
    protected function buildAllActiveLocations(): array
    {
        $rows = Location::query()
            ->effectivelyVisible()
            ->join('location_areas', 'location_areas.id', '=', 'locations.location_area_id')
            ->join('location_groups', 'location_groups.id', '=', 'location_areas.location_group_id')
            ->join('provinces', 'provinces.id', '=', 'location_groups.province_id')
            ->select([
                'locations.*',
                'location_areas.name as bio_area_name',
                'location_groups.id as bio_group_id',
                'location_groups.name as bio_group_name',
                'provinces.id as bio_province_id',
                'provinces.name as bio_province_name',
            ])
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

        $items = [];
        $provinces = [];
        $groups = [];

        foreach ($rows as $row) {
            $provinceId = (int) $row->getAttribute('bio_province_id');
            $groupId = (int) $row->getAttribute('bio_group_id');
            $provinceName = (string) $row->getAttribute('bio_province_name');
            $groupName = (string) $row->getAttribute('bio_group_name');

            $provinces[$provinceId] ??= ['id' => $provinceId, 'name' => $provinceName];
            $groups[$groupId] ??= [
                'id' => $groupId,
                'name' => $groupName,
                'province_id' => $provinceId,
                'province_name' => $provinceName,
            ];

            $items[] = [
                'id' => $row->id,
                'name' => $row->name,
                'area_name' => (string) $row->getAttribute('bio_area_name'),
                'group_id' => $groupId,
                'group_name' => $groupName,
                'province_id' => $provinceId,
                'province_name' => $provinceName,
                'full_address' => $this->cleanText($row->full_address),
                'landmark' => $this->cleanText($row->landmark),
                'operational_hours_text' => $this->cleanText($row->operational_hours_text),
                // Sudah dipastikan aman; koordinat diutamakan atas link manual.
                'maps_url' => $row->safeMapsUrl(),
            ];
        }

        return [
            'mode' => BioLocationMode::AllActiveLocations->value,
            'items' => $items,
            'provinces' => array_values($provinces),
            'groups' => array_values($groups),
        ];
    }

    /**
     * Mode location_pages: Halaman Slug Lokasi yang dipilih untuk Bio.
     *
     * Empat syarat, persis seperti yang diminta: aktif, tidak terhapus
     * (global scope), masih dalam periode bila diisi, dan show_on_bio.
     *
     * Wilayah untuk filter diambil dari cakupan Kota/Grup halaman. Karena
     * satu halaman bisa mencakup beberapa Kota/Grup -- bahkan beberapa
     * provinsi -- ia menyimpan DAFTAR id, bukan satu id. Halaman tetap satu
     * baris: cocok untuk beberapa filter, tetapi tidak pernah tampil ganda.
     *
     * Tiga query tetap: halaman, Kota/Grup, Provinsi.
     *
     * @return array<string, mixed>
     */
    protected function buildLocationPages(): array
    {
        $pages = LocationPage::query()
            ->publiclyVisible()
            ->onBio()
            ->with(['groups' => fn ($query) => $query
                ->effectivelyVisible()
                ->with('province')
                ->orderBy('location_groups.sort_order')
                ->orderBy('location_groups.name')])
            ->ordered()
            ->get();

        $items = [];
        $provinces = [];
        $groups = [];

        foreach ($pages as $page) {
            $provinceIds = [];
            $groupIds = [];
            $regions = [];

            /** @var LocationGroup $group */
            foreach ($page->groups as $group) {
                $province = $group->province;

                if ($province === null) {
                    continue;
                }

                $groupIds[] = (int) $group->id;
                $provinceIds[(int) $province->id] = (int) $province->id;
                $regions[] = $group->name.', '.$province->name;

                $provinces[(int) $province->id] ??= ['id' => (int) $province->id, 'name' => $province->name];
                $groups[(int) $group->id] ??= [
                    'id' => (int) $group->id,
                    'name' => $group->name,
                    'province_id' => (int) $province->id,
                    'province_name' => $province->name,
                ];
            }

            $items[] = [
                'id' => $page->id,
                'title' => $page->title,
                'slug' => $page->slug,
                'short_description' => $this->cleanText($page->short_description),
                'period_text' => $this->cleanText($page->period_text),
                'province_ids' => array_values($provinceIds),
                'group_ids' => $groupIds,
                'regions' => $regions,
            ];
        }

        // Opsi filter diurutkan menurut nama supaya stabil walau halaman
        // berpindah urutan.
        usort($provinces, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        usort($groups, fn (array $a, array $b): int => [$a['province_name'], $a['name']] <=> [$b['province_name'], $b['name']]);

        return [
            'mode' => BioLocationMode::LocationPages->value,
            'items' => $items,
            'provinces' => array_values($provinces),
            'groups' => array_values($groups),
        ];
    }

    /**
     * Terapkan filter Provinsi dan Kota/Grup pada payload yang sudah ada.
     *
     * Murni pemrosesan array -- tidak ada query tambahan per filter, dan
     * cache tidak pecah menjadi satu entri per kombinasi filter.
     *
     * Id yang tidak dikenal DIABAIKAN, bukan menghasilkan halaman kosong:
     * query string bisa diketik siapa saja. Kota/Grup yang bukan milik
     * provinsi terpilih juga diabaikan, karena opsi itu memang tidak pernah
     * ditawarkan dalam kombinasi tersebut.
     *
     * @param  array<string, mixed>  $payload
     * @return array{
     *     items: list<array<string, mixed>>,
     *     province_id: int|null,
     *     group_id: int|null,
     *     group_options: list<array<string, mixed>>
     * }
     */
    public function filterLocations(array $payload, mixed $provinceId, mixed $groupId): array
    {
        $provinceIds = array_column($payload['provinces'] ?? [], 'id');
        $groupsById = array_column($payload['groups'] ?? [], null, 'id');

        $provinceId = self::intOrNull($provinceId);
        $groupId = self::intOrNull($groupId);

        if ($provinceId !== null && ! in_array($provinceId, $provinceIds, true)) {
            $provinceId = null;
        }

        if ($groupId !== null && ! isset($groupsById[$groupId])) {
            $groupId = null;
        }

        if ($provinceId !== null && $groupId !== null && $groupsById[$groupId]['province_id'] !== $provinceId) {
            $groupId = null;
        }

        $isPages = ($payload['mode'] ?? null) === BioLocationMode::LocationPages->value;

        $items = array_values(array_filter(
            $payload['items'] ?? [],
            function (array $item) use ($provinceId, $groupId, $isPages): bool {
                if ($provinceId !== null) {
                    $matches = $isPages
                        ? in_array($provinceId, $item['province_ids'], true)
                        : $item['province_id'] === $provinceId;

                    if (! $matches) {
                        return false;
                    }
                }

                if ($groupId !== null) {
                    return $isPages
                        ? in_array($groupId, $item['group_ids'], true)
                        : $item['group_id'] === $groupId;
                }

                return true;
            },
        ));

        // Pilihan Kota/Grup mengikuti provinsi terpilih, supaya kombinasi
        // yang mustahil tidak pernah ditawarkan.
        $groupOptions = array_values(array_filter(
            $payload['groups'] ?? [],
            fn (array $group): bool => $provinceId === null || $group['province_id'] === $provinceId,
        ));

        return [
            'items' => $items,
            'province_id' => $provinceId,
            'group_id' => $groupId,
            'group_options' => $groupOptions,
        ];
    }

    /**
     * Kelompokkan gerobak menjadi Provinsi -> Kota/Grup untuk judul bagian.
     * Area tetap hanya label pada kartu.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array{province_name: string, groups: list<array{group_name: string, items: list<array<string, mixed>>}>}>
     */
    public static function groupByRegion(array $items): array
    {
        $sections = [];

        foreach ($items as $item) {
            $provinceKey = $item['province_id'];
            $groupKey = $item['group_id'];

            $sections[$provinceKey]['province_name'] ??= $item['province_name'];
            $sections[$provinceKey]['groups'][$groupKey]['group_name'] ??= $item['group_name'];
            $sections[$provinceKey]['groups'][$groupKey]['items'][] = $item;
        }

        return array_values(array_map(
            fn (array $section): array => [
                'province_name' => $section['province_name'],
                'groups' => array_values($section['groups']),
            ],
            $sections,
        ));
    }

    // --------------------------------------------------------------- produk

    /**
     * Isi /bio/produk: produk aktif, tidak terhapus, dan show_on_bio.
     *
     * Dikelompokkan menurut ProductCategory -- nama kelompok dan urutannya
     * berasal dari enum, bukan ditulis di Blade.
     *
     * SENGAJA terpisah dari dialog menu halaman slug lokasi: keduanya membaca
     * tabel produk yang sama, tetapi aturan pemilihannya berbeda
     * (show_on_bio di sini, pivot halaman di sana).
     *
     * @return list<array{key: string, heading: string, items: list<array<string, mixed>>}>
     */
    public function products(): array
    {
        return Cache::remember(
            'bio.v'.self::cacheVersion().'.p'.ProductCatalogService::cacheVersion().'.products',
            self::TTL_SECONDS,
            fn (): array => $this->guardMissingTable(fn (): array => $this->buildProducts(), []),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function buildProducts(): array
    {
        $groups = [];

        foreach (Product::query()->active()->onBio()->ordered()->get() as $product) {
            $category = $product->category;

            // Kategori di luar enum hanya bisa berasal dari tulisan langsung
            // ke database, dan tidak pernah dikelompokkan.
            if (! $category instanceof ProductCategory) {
                continue;
            }

            $groups[$category->value][] = [
                'id' => $product->id,
                'name' => $product->name,
                'price' => $product->priceLabel(),
                // Null bila foto kosong ATAU berkasnya hilang -- sehingga
                // <img src=""> tidak mungkin terbentuk dan emoji mengambil alih.
                'image' => ImageMetadata::resolve($product->image_path, $product->imageAltText()),
                'emoji' => $product->fallbackEmoji(),
                'type' => $product->typeLabel(),
            ];
        }

        $sections = [];

        foreach (ProductCategory::ordered() as $category) {
            if (empty($groups[$category->value])) {
                continue;
            }

            $sections[] = [
                'key' => $category->value,
                'heading' => $category->publicHeading(),
                'items' => $groups[$category->value],
            ];
        }

        return $sections;
    }

    // -------------------------------------------------------------- helpers

    /**
     * Jalankan query dengan fallback yang SANGAT sempit: hanya tabel yang
     * belum dibuat. Error database lain dilempar ulang.
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
            $missing = ($exception->errorInfo[0] ?? null) === '42S02'
                || str_contains($exception->getMessage(), 'no such table');

            if (! $missing) {
                throw $exception;
            }

            Log::warning('Tabel halaman Bio belum tersedia, halaman Bio dianggap kosong.', [
                'message' => $exception->getMessage(),
            ]);

            return $fallback;
        }
    }

    protected function cleanText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    protected static function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]{0,18}$/', $value)) {
            return (int) $value;
        }

        return null;
    }
}
