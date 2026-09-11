<?php

namespace App\Enums;

/**
 * Tombol utama halaman /bio.
 *
 * Daftarnya TETAP. Admin hanya boleh mengubah label, menyembunyikan, dan
 * menata urutannya -- tidak bisa menambah tombol baru maupun mengisi
 * tujuannya. Tujuan Lokasi dan Menu selalu berasal dari named route di kode,
 * dan tujuan WhatsApp selalu dibentuk service dari nomor yang tervalidasi.
 * Tidak ada satu pun URL tombol yang berasal dari database.
 */
enum BioButton: string
{
    case Location = 'location';
    case Menu = 'menu';
    case WhatsApp = 'whatsapp';

    /**
     * Label bawaan bila admin belum menuliskannya sendiri.
     */
    public function defaultLabel(): string
    {
        return match ($this) {
            self::Location => 'Lokasi Gerobak',
            self::Menu => 'Lihat Menu',
            self::WhatsApp => 'Chat WhatsApp',
        };
    }

    /**
     * Nama tombol di panel admin. Sengaja berbeda dari label publik supaya
     * admin tidak kehilangan jejak tombol mana yang sedang diubah labelnya.
     */
    public function adminName(): string
    {
        return match ($this) {
            self::Location => 'Tombol Lokasi',
            self::Menu => 'Tombol Menu',
            self::WhatsApp => 'Tombol WhatsApp',
        };
    }

    /**
     * Urutan bawaan.
     *
     * @return list<self>
     */
    public static function defaultOrder(): array
    {
        return [self::Location, self::Menu, self::WhatsApp];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
