<?php

namespace App\Services;

use App\Models\SiteSetting;
use App\Support\SafeUrl;
use App\Support\WhatsAppNumber;

/**
 * Sumber tunggal data brand untuk halaman publik.
 *
 * Menghasilkan array dengan bentuk yang SAMA PERSIS dengan config/dimdum.php,
 * sehingga layout dan komponen Blade tidak perlu diubah sama sekali.
 * Nilai database menimpa config; field yang belum diisi tetap memakai config.
 */
class SiteSettingsService extends SingletonSettingsRepository
{
    /**
     * @var array<string, mixed>|null
     */
    protected ?array $resolved = null;

    /**
     * Array $brand yang dikirim ke view.
     *
     * @return array<string, mixed>
     */
    public function brand(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $config = config('dimdum');
        $stored = $this->attributes();

        $brand = $config;

        $brand['name'] = $this->stringValue($stored, 'brand_name') ?? $config['name'];
        $brand['tagline'] = $this->stringValue($stored, 'tagline') ?? $config['tagline'];
        $brand['positioning'] = $this->stringValue($stored, 'positioning') ?? $config['positioning'];

        $brand['contact'] = [
            ...$config['contact'],
            'whatsapp' => WhatsAppNumber::normalize($this->stringValue($stored, 'whatsapp_number')),
        ];

        $brand['social'] = $this->socialLinks($stored, $config);

        $brand['seo'] = [
            ...$config['seo'],
            'title' => $this->stringValue($stored, 'default_meta_title') ?? $config['seo']['title'],
            'description' => $this->stringValue($stored, 'default_meta_description') ?? $config['seo']['description'],
        ];

        $brand['assets'] = [
            ...$config['assets'],
            'og_image' => $this->ogImage($stored, $brand['name']) ?? $config['assets']['og_image'],
        ];

        return $this->resolved = $brand;
    }

    /**
     * Metadata SEO siap pakai untuk layout publik.
     *
     * @return array<string, mixed>
     */
    public function seo(): array
    {
        $brand = $this->brand();

        return [
            'title' => $brand['seo']['title'],
            'description' => $brand['seo']['description'],
            'canonical' => route('home'),
            'locale' => $brand['seo']['locale'],
            'image' => $brand['assets']['og_image'],
        ];
    }

    /**
     * Baris singleton untuk halaman admin (selalu segar, tanpa cache).
     */
    public function record(): ?SiteSetting
    {
        /** @var SiteSetting|null */
        return $this->findRecord(SiteSetting::class, SiteSetting::SINGLETON_KEY);
    }

    /**
     * Baris singleton, dibuat dari config bila belum ada.
     */
    public function recordOrCreate(): SiteSetting
    {
        return $this->record() ?? SiteSetting::query()->create([
            'key' => SiteSetting::SINGLETON_KEY,
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
        $config = config('dimdum');

        return [
            'brand_name' => $config['name'],
            'tagline' => $config['tagline'],
            'positioning' => $config['positioning'],
            'whatsapp_number' => WhatsAppNumber::normalize($config['contact']['whatsapp'] ?? null),
            'instagram_url' => null,
            'tiktok_url' => null,
            'facebook_url' => null,
            'default_meta_title' => $config['seo']['title'],
            'default_meta_description' => $config['seo']['description'],
            'default_og_image_path' => null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function attributes(): ?array
    {
        return $this->cachedAttributes(
            SiteSetting::CACHE_KEY,
            SiteSetting::class,
            SiteSetting::SINGLETON_KEY,
        );
    }

    /**
     * Social media hanya dirender bila URL-nya benar-benar ada DAN aman.
     *
     * Pemeriksaan diulang di sini -- bukan hanya saat validasi form -- supaya
     * nilai berbahaya yang masuk lewat jalur lain tetap tidak pernah tercetak
     * sebagai href.
     *
     * @param  array<string, mixed>|null  $stored
     * @param  array<string, mixed>  $config
     * @return list<array{label: string, url: string}>
     */
    protected function socialLinks(?array $stored, array $config): array
    {
        if ($stored === null) {
            return $config['social'];
        }

        $channels = [
            'instagram_url' => 'Instagram',
            'tiktok_url' => 'TikTok',
            'facebook_url' => 'Facebook',
        ];

        $links = [];

        foreach ($channels as $column => $label) {
            $url = SafeUrl::sanitizeExternal($this->stringValue($stored, $column));

            if ($url === null) {
                continue;
            }

            $links[] = ['label' => $label, 'url' => $url];
        }

        return $links;
    }

    /**
     * @param  array<string, mixed>|null  $stored
     * @return array<string, mixed>|null
     */
    protected function ogImage(?array $stored, string $brandName): ?array
    {
        $path = $this->stringValue($stored, 'default_og_image_path');

        if ($path === null) {
            return null;
        }

        return ImageMetadata::resolve($path, 'Logo '.$brandName);
    }
}
