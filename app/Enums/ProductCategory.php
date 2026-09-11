<?php

namespace App\Enums;

/**
 * Kategori produk DIMDUM.
 *
 * Kategori menjawab "ini bagian dari daftar yang mana", dan itulah yang
 * dipakai untuk mengelompokkan halaman /produk. Ia SENGAJA terpisah dari
 * ProductType, yang menjawab "dijual dalam bentuk apa": satu Menu bisa dijual
 * satuan maupun paket, dan Frozen bisa berupa satuan maupun paket juga.
 * Menggabungkan keduanya menjadi satu kolom akan memaksa salah satu fakta itu
 * hilang.
 */
enum ProductCategory: string
{
    case Menu = 'menu';
    case Varian = 'varian';
    case Frozen = 'frozen';

    public function label(): string
    {
        return match ($this) {
            self::Menu => 'Menu',
            self::Varian => 'Varian',
            self::Frozen => 'Frozen',
        };
    }

    /**
     * Judul kelompok pada halaman publik /produk.
     *
     * Lebih panjang daripada label panel: pengunjung tidak tahu istilah
     * internal, jadi kelompoknya diberi nama yang bisa dibaca apa adanya.
     */
    public function publicHeading(): string
    {
        return match ($this) {
            self::Menu => 'Menu Utama',
            self::Varian => 'Varian Dimsum',
            self::Frozen => 'Frozen Food',
        };
    }

    /**
     * Urutan tampil kelompok pada halaman publik. Kecil tampil lebih dulu.
     */
    public function displayOrder(): int
    {
        return match ($this) {
            self::Menu => 10,
            self::Varian => 20,
            self::Frozen => 30,
        };
    }

    /**
     * Seluruh kategori, urut menurut displayOrder().
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        $categories = self::cases();

        usort($categories, fn (self $a, self $b): int => $a->displayOrder() <=> $b->displayOrder());

        return $categories;
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
