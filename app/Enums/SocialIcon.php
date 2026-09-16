<?php

namespace App\Enums;

/**
 * Allowlist ikon social media.
 *
 * Admin hanya MEMILIH salah satu nilai di sini. Markup ikonnya hidup di kode
 * (resources/views/components/social-icon.blade.php), sehingga tidak pernah
 * ada HTML maupun SVG mentah yang diterima dari admin.
 *
 * Platform tanpa preset memakai Link -- ikon generik yang tetap rapi.
 */
enum SocialIcon: string
{
    case Instagram = 'instagram';
    case TikTok = 'tiktok';
    case Facebook = 'facebook';
    case YouTube = 'youtube';
    case X = 'x';
    case WhatsApp = 'whatsapp';
    case Marketplace = 'marketplace';
    case FoodDelivery = 'food_delivery';
    case Website = 'website';
    case Link = 'link';

    public function label(): string
    {
        return match ($this) {
            self::Instagram => 'Instagram',
            self::TikTok => 'TikTok',
            self::Facebook => 'Facebook',
            self::YouTube => 'YouTube',
            self::X => 'X (Twitter)',
            self::WhatsApp => 'WhatsApp',
            self::Marketplace => 'Marketplace / Toko Online',
            self::FoodDelivery => 'Pesan Antar Makanan',
            self::Website => 'Website',
            self::Link => 'Tautan umum (ikon generik)',
        };
    }

    /**
     * Nilai tidak dikenal -- misalnya ditulis langsung ke database -- jatuh
     * ke ikon generik, bukan ke error maupun ke markup kosong.
     */
    public static function resolve(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Link) : self::Link;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
