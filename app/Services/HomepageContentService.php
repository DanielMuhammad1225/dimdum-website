<?php

namespace App\Services;

use App\Models\HomepageSetting;
use App\Support\SafeUrl;

/**
 * Sumber tunggal konten homepage untuk halaman publik.
 *
 * Menghasilkan array dengan bentuk yang SAMA PERSIS dengan config/homepage.php.
 * Config tetap dipertahankan sebagai default dan fallback; database hanya
 * menimpa field yang memang sudah diisi admin.
 *
 * Tanggung jawab keamanan yang dipusatkan di sini:
 *   - Key section divalidasi terhadap allowlist config('homepage.section_views').
 *   - Key asing dibuang sebelum sampai ke controller, sehingga tidak pernah
 *     ada percobaan @include terhadap nama yang tidak dikenal.
 *   - Seluruh href dibersihkan ulang, tidak hanya saat validasi form.
 */
class HomepageContentService extends SingletonSettingsRepository
{
    /**
     * Batas wajar jumlah item berulang. Nilai yang sama dipakai di form admin.
     */
    public const MAX_USP_ITEMS = 6;

    public const MAX_STEPS = 6;

    public const MAX_HIGHLIGHTS = 4;

    /**
     * @var array<string, mixed>|null
     */
    protected ?array $resolved = null;

    /**
     * Array $homepage yang dikirim ke view.
     *
     * @return array<string, mixed>
     */
    public function homepage(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $config = config('homepage');
        $stored = $this->attributes();

        $homepage = $config;

        $homepage['hero'] = $this->hero($config['hero'], $this->arrayValue($stored, 'hero'));
        $homepage['usp'] = $this->usp($config['usp'], $this->arrayValue($stored, 'usp'));
        $homepage['products'] = $this->products($config['products'], $this->arrayValue($stored, 'products'));
        $homepage['how'] = $this->how($config['how'], $this->arrayValue($stored, 'how'));
        $homepage['budget'] = $this->budget($config['budget'], $this->arrayValue($stored, 'budget'));
        $homepage['locations'] = $this->locations($config['locations'], $this->arrayValue($stored, 'locations'));
        $homepage['cta'] = $this->cta($config['cta'], $this->arrayValue($stored, 'cta'));
        $homepage['sections'] = $this->resolveSectionKeys($stored);

        return $this->resolved = $homepage;
    }

    /**
     * Urutan section aktif, sudah divalidasi terhadap allowlist.
     *
     * @return list<string>
     */
    public function sectionKeys(): array
    {
        return $this->resolveSectionKeys($this->attributes());
    }

    /**
     * @param  array<string, mixed>|null  $stored
     * @return list<string>
     */
    protected function resolveSectionKeys(?array $stored): array
    {
        $allowed = array_keys(config('homepage.section_views', []));
        $entries = $stored['sections'] ?? null;

        if (! is_array($entries) || $entries === []) {
            // Belum ada data admin: ikuti urutan default dari config.
            return array_values(array_filter(
                config('homepage.sections', []),
                fn ($key): bool => is_string($key) && in_array($key, $allowed, true),
            ));
        }

        $keys = [];
        $seen = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $key = $entry['key'] ?? null;

            // Key asing (termasuk nama file Blade atau path) dibuang di sini.
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                continue;
            }

            // Key duplikat hanya dihitung sekali -- urutan tetap deterministik.
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            if (filter_var($entry['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Baris singleton untuk halaman admin (selalu segar, tanpa cache).
     */
    public function record(): ?HomepageSetting
    {
        /** @var HomepageSetting|null */
        return $this->findRecord(HomepageSetting::class, HomepageSetting::SINGLETON_KEY);
    }

    public function recordOrCreate(): HomepageSetting
    {
        return $this->record() ?? HomepageSetting::query()->create([
            'key' => HomepageSetting::SINGLETON_KEY,
            ...self::defaultsFromConfig(),
        ]);
    }

    /**
     * Nilai awal yang dibaca dari config -- dipakai seeder dan recordOrCreate.
     *
     * @return array<string, mixed>
     */
    public static function defaultsFromConfig(): array
    {
        $config = config('homepage');
        $allowed = array_keys($config['section_views'] ?? []);

        return [
            'hero' => [
                'eyebrow' => $config['hero']['eyebrow'],
                'headline' => $config['hero']['headline'],
                'description' => $config['hero']['description'],
                'primary_cta' => $config['hero']['primary_cta'],
                'secondary_cta' => $config['hero']['secondary_cta'],
                'highlights' => $config['hero']['highlights'],
                'image' => null,
            ],
            'usp' => [
                'title' => $config['usp']['title'],
                'items' => $config['usp']['items'],
            ],
            'products' => [
                'title' => $config['products']['title'],
                'description' => $config['products']['description'],
                'empty_state' => $config['products']['empty_state'],
            ],
            'how' => [
                'title' => $config['how']['title'],
                'steps' => $config['how']['steps'],
                'closing' => $config['how']['closing'],
            ],
            'budget' => [
                'title' => $config['budget']['title'],
                'copy' => $config['budget']['copy'],
                'support_copy' => $config['budget']['support_copy'],
            ],
            'locations' => [
                'title' => $config['locations']['title'],
                'description' => $config['locations']['description'],
                'coming_soon_title' => $config['locations']['coming_soon_title'],
                'coming_soon_description' => $config['locations']['coming_soon_description'],
            ],
            'cta' => [
                'headline' => $config['cta']['headline'],
                'description' => $config['cta']['description'],
                'primary_cta' => $config['cta']['primary_cta'],
                'secondary_cta' => $config['cta']['secondary_cta'],
            ],
            'sections' => array_map(
                fn (string $key): array => ['key' => $key, 'enabled' => true],
                array_values(array_filter(
                    $config['sections'] ?? [],
                    fn ($key): bool => is_string($key) && in_array($key, $allowed, true),
                )),
            ),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function attributes(): ?array
    {
        return $this->cachedAttributes(
            HomepageSetting::CACHE_KEY,
            HomepageSetting::class,
            HomepageSetting::SINGLETON_KEY,
        );
    }

    // ------------------------------------------------------------ sections

    /**
     * @param  array<string, mixed>  $default
     * @param  array<string, mixed>|null  $stored
     * @return array<string, mixed>
     */
    protected function hero(array $default, ?array $stored): array
    {
        $hero = $this->mergeSection($default, $stored, [
            'eyebrow', 'headline', 'description',
        ]);

        $hero['primary_cta'] = $this->ctaLink($stored['primary_cta'] ?? null, $default['primary_cta']);
        $hero['secondary_cta'] = $this->ctaLink($stored['secondary_cta'] ?? null, $default['secondary_cta']);

        $hero['highlights'] = $this->stringList(
            $stored['highlights'] ?? null,
            $default['highlights'],
            self::MAX_HIGHLIGHTS,
        );

        // Gambar hero: null berarti "belum ada", dan fallback lockup logo
        // di Blade yang mengambil alih.
        $hero['image'] = $this->image($stored['image'] ?? null) ?? $default['image'];

        return $hero;
    }

    /**
     * @param  array<string, mixed>  $default
     * @param  array<string, mixed>|null  $stored
     * @return array<string, mixed>
     */
    protected function usp(array $default, ?array $stored): array
    {
        $usp = $this->mergeSection($default, $stored, ['title']);

        $usp['items'] = $this->orderedItems(
            $stored['items'] ?? null,
            $default['items'],
            ['title', 'description'],
            self::MAX_USP_ITEMS,
        );

        return $usp;
    }

    /**
     * Fase ini hanya mengelola teks section. Daftar varian masih berasal dari
     * config dan akan digantikan modul relational pada fase produk.
     *
     * @param  array<string, mixed>  $default
     * @param  array<string, mixed>|null  $stored
     * @return array<string, mixed>
     */
    protected function products(array $default, ?array $stored): array
    {
        $products = $this->mergeSection($default, $stored, ['title', 'description']);

        $storedEmptyState = $stored['empty_state'] ?? null;

        $products['empty_state'] = $this->mergeSection(
            $default['empty_state'],
            is_array($storedEmptyState) ? $storedEmptyState : null,
            ['title', 'description'],
        );

        return $products;
    }

    /**
     * @param  array<string, mixed>  $default
     * @param  array<string, mixed>|null  $stored
     * @return array<string, mixed>
     */
    protected function how(array $default, ?array $stored): array
    {
        $how = $this->mergeSection($default, $stored, ['title', 'closing']);

        $how['steps'] = $this->orderedItems(
            $stored['steps'] ?? null,
            $default['steps'],
            ['title', 'description'],
            self::MAX_STEPS,
        );

        return $how;
    }

    /**
     * @param  array<string, mixed>  $default
     * @param  array<string, mixed>|null  $stored
     * @return array<string, mixed>
     */
    protected function budget(array $default, ?array $stored): array
    {
        return $this->mergeSection($default, $stored, ['title', 'copy', 'support_copy']);
    }

    /**
     * @param  array<string, mixed>  $default
     * @param  array<string, mixed>|null  $stored
     * @return array<string, mixed>
     */
    protected function locations(array $default, ?array $stored): array
    {
        return $this->mergeSection($default, $stored, [
            'title', 'description', 'coming_soon_title', 'coming_soon_description',
        ]);
    }

    /**
     * @param  array<string, mixed>  $default
     * @param  array<string, mixed>|null  $stored
     * @return array<string, mixed>
     */
    protected function cta(array $default, ?array $stored): array
    {
        $cta = $this->mergeSection($default, $stored, ['headline', 'description']);

        $cta['primary_cta'] = $this->ctaLink($stored['primary_cta'] ?? null, $default['primary_cta']);
        $cta['secondary_cta'] = $this->ctaLink($stored['secondary_cta'] ?? null, $default['secondary_cta']);

        return $cta;
    }

    // ------------------------------------------------------------- helpers

    /**
     * Label + href yang sudah dipastikan aman.
     *
     * href yang tidak lolos SafeUrl dikembalikan ke nilai config, sehingga
     * tombol tetap ada dan tidak pernah menjadi link mati atau berbahaya.
     *
     * @param  array<string, mixed>  $default
     * @return array{label: string, href: string}
     */
    protected function ctaLink(mixed $stored, array $default): array
    {
        $label = $default['label'];
        $href = $default['href'];

        if (is_array($stored)) {
            $storedLabel = $stored['label'] ?? null;

            if (is_string($storedLabel) && trim($storedLabel) !== '') {
                $label = trim($storedLabel);
            }

            $href = SafeUrl::sanitize($stored['href'] ?? null) ?? $href;
        }

        return ['label' => $label, 'href' => $href];
    }

    /**
     * Daftar teks pendek (highlight hero).
     *
     * @param  list<string>  $default
     * @return list<string>
     */
    protected function stringList(mixed $stored, array $default, int $max): array
    {
        if (! is_array($stored)) {
            return $default;
        }

        $items = [];

        foreach ($stored as $value) {
            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);

            if ($value === '') {
                continue;
            }

            $items[] = $value;

            if (count($items) >= $max) {
                break;
            }
        }

        // Daftar kosong berarti admin memang mengosongkannya.
        return $items;
    }

    /**
     * Daftar item berurutan (USP, langkah cara jajan).
     *
     * Urutan ditentukan oleh key 'order' bila ada, dengan posisi array sebagai
     * penentu kedua supaya hasilnya selalu deterministik.
     *
     * @param  list<array<string, mixed>>  $default
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    protected function orderedItems(mixed $stored, array $default, array $keys, int $max): array
    {
        if (! is_array($stored)) {
            return $default;
        }

        $items = [];
        $position = 0;

        foreach ($stored as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $item = [];
            $isComplete = true;

            foreach ($keys as $key) {
                $value = $entry[$key] ?? null;

                if (! is_string($value) || trim($value) === '') {
                    $isComplete = false;

                    break;
                }

                $item[$key] = trim($value);
            }

            if (! $isComplete) {
                continue;
            }

            $order = $entry['order'] ?? null;

            $items[] = [
                'item' => $item,
                'order' => is_numeric($order) ? (int) $order : PHP_INT_MAX,
                'position' => $position++,
            ];
        }

        usort($items, fn (array $a, array $b): int => [$a['order'], $a['position']] <=> [$b['order'], $b['position']]);

        return array_map(
            fn (array $entry): array => $entry['item'],
            array_slice($items, 0, $max),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function image(mixed $stored): ?array
    {
        if (! is_array($stored)) {
            return null;
        }

        $alt = $stored['alt'] ?? '';

        return ImageMetadata::resolve(
            $stored['path'] ?? null,
            is_string($alt) ? trim($alt) : '',
        );
    }
}
