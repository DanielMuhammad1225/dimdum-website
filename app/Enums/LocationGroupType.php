<?php

namespace App\Enums;

/**
 * Tipe Kota/Grup.
 *
 * Satu tingkat di bawah Provinsi bisa berarti dua hal yang berbeda:
 *
 *  - wilayah administratif sungguhan (Jakarta Selatan, Kabupaten Cianjur);
 *  - kelompok pemasaran yang melintasi batas administratif (Bandung Raya).
 *
 * Keduanya sah dan keduanya dibutuhkan, tetapi artinya harus tersimpan
 * eksplisit. Tanpa kolom tipe, satu-satunya cara membedakannya adalah menebak
 * dari namanya -- dan tebakan itu akan salah tepat pada kasus yang paling
 * penting, yaitu ketika alamat pos gerobak tidak sama dengan nama grupnya.
 */
enum LocationGroupType: string
{
    case Administrative = 'administrative';
    case MarketingGroup = 'marketing_group';

    public function label(): string
    {
        return match ($this) {
            self::Administrative => 'Kota/Kabupaten Administratif',
            self::MarketingGroup => 'Grup Wilayah/Pemasaran',
        };
    }

    /**
     * Penjelasan singkat untuk admin, supaya pilihannya tidak ditebak.
     */
    public function description(): string
    {
        return match ($this) {
            self::Administrative => 'Sama persis dengan satu kota atau kabupaten pada data administratif.',
            self::MarketingGroup => 'Kelompok bentukan sendiri yang boleh mencakup lebih dari satu kota/kabupaten.',
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

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
