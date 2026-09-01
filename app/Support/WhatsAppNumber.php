<?php

namespace App\Support;

/**
 * Normalisasi nomor WhatsApp Indonesia ke bentuk penyimpanan tunggal:
 * digit saja, berawalan kode negara, tanpa '+', spasi, atau tanda baca.
 *
 * Contoh: '+62 812-3456-7890', '0812 3456 7890', '62-812-3456-7890'
 *      -> '6281234567890'
 *
 * Nomor tidak pernah dikarang: input kosong menghasilkan null.
 */
class WhatsAppNumber
{
    protected const DEFAULT_COUNTRY_CODE = '62';

    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $value) ?? '';

        if ($digits === '') {
            return null;
        }

        // '0812...' adalah format lokal Indonesia -> ganti '0' dengan '62'.
        if (str_starts_with($digits, '0')) {
            $digits = self::DEFAULT_COUNTRY_CODE.ltrim($digits, '0');
        }

        // '8123...' ditulis tanpa awalan sama sekali.
        if (str_starts_with($digits, '8')) {
            $digits = self::DEFAULT_COUNTRY_CODE.$digits;
        }

        return self::isPlausible($digits) ? $digits : null;
    }

    /**
     * Panjang nomor internasional yang masuk akal (ITU-T E.164: 8-15 digit).
     * Ini pemeriksaan bentuk, bukan verifikasi bahwa nomornya benar-benar ada.
     */
    public static function isPlausible(mixed $digits): bool
    {
        if (! is_string($digits)) {
            return false;
        }

        return (bool) preg_match('/^[1-9][0-9]{7,14}$/', $digits);
    }

    /**
     * URL resmi WhatsApp untuk nomor yang sudah dinormalisasi.
     */
    public static function toUrl(?string $digits): ?string
    {
        if (! self::isPlausible($digits)) {
            return null;
        }

        return 'https://wa.me/'.$digits;
    }
}
