<?php

namespace App\Enums;

/**
 * Sumber data halaman /bio/lokasi.
 *
 * Dipilih admin di Pengaturan Bio. URL-nya TIDAK berubah ketika mode diganti:
 * /bio/lokasi tetap satu alamat, hanya isinya yang berasal dari sumber lain.
 * Karena itu tidak ada URL lokasi bebas yang disimpan di pengaturan.
 */
enum BioLocationMode: string
{
    /** Seluruh gerobak aktif dari master hierarki. */
    case AllActiveLocations = 'all_active_locations';

    /** Halaman Slug Lokasi yang dipilih untuk tampil di Bio. */
    case LocationPages = 'location_pages';

    public function label(): string
    {
        return match ($this) {
            self::AllActiveLocations => 'Semua gerobak aktif',
            self::LocationPages => 'Halaman Slug Lokasi terpilih',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::AllActiveLocations => 'Menampilkan setiap gerobak yang aktif beserta seluruh induknya (Provinsi, Kota/Grup, Area). Tidak perlu memilih apa pun.',
            self::LocationPages => 'Menampilkan Halaman Slug Lokasi yang saklar "Tampilkan di Bio"-nya menyala. Setiap kartu menaut ke /alamat/{slug}.',
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
