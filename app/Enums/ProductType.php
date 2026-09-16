<?php

namespace App\Enums;

/**
 * Tipe penjualan produk DIMDUM.
 *
 * Menjawab "dijual dalam bentuk apa", bukan "masuk daftar yang mana" -- yang
 * terakhir itu tugas ProductCategory. Keduanya berdiri sendiri: sebuah produk
 * kategori Frozen bisa bertipe Satuan maupun Paket.
 */
enum ProductType: string
{
    case Satuan = 'satuan';
    case Mix = 'mix';
    case Paket = 'paket';
    case FrozenFood = 'frozen_food';

    public function label(): string
    {
        return match ($this) {
            self::Satuan => 'Satuan',
            self::Mix => 'Mix',
            self::Paket => 'Paket',
            self::FrozenFood => 'Frozen Food',
        };
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
