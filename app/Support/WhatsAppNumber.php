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

    /**
     * Normalisasi KETAT: hanya nomor seluler Indonesia.
     *
     * normalize() di atas sengaja longgar (E.164 apa pun, 8-15 digit) karena
     * sudah dipakai Pengaturan Website dan tidak boleh berubah perilakunya.
     * Nomor khusus Bio butuh aturan yang lebih sempit: tombolnya dibuka orang
     * dari profil social media, jadi nomor luar negeri atau nomor rumah yang
     * lolos karena "panjangnya masuk akal" adalah kesalahan, bukan pilihan.
     *
     * Bentuk yang diterima: 62 8x ... dengan 11-14 digit total, setara
     * 08xx-xxxx-xxxx (10-13 digit) dalam penulisan lokal.
     *
     * Satu salah tulis yang lazim ikut dirapikan: '62 0812...' (awalan
     * negara DAN nol lokal sekaligus) menjadi '62812...'.
     */
    public static function normalizeIndonesian(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $value) ?? '';

        if (str_starts_with($digits, self::DEFAULT_COUNTRY_CODE.'0')) {
            $digits = self::DEFAULT_COUNTRY_CODE.substr($digits, 3);
        }

        $digits = self::normalize($digits);

        return self::isIndonesianMobile($digits) ? $digits : null;
    }

    public static function isIndonesianMobile(mixed $digits): bool
    {
        if (! is_string($digits)) {
            return false;
        }

        return (bool) preg_match('/^628[1-9][0-9]{7,10}$/', $digits);
    }

    /**
     * URL wa.me dengan pesan pembuka opsional.
     *
     * Pesan di-encode rawurlencode, jadi spasi menjadi %20 dan karakter apa
     * pun yang diketik admin tidak bisa memecah URL maupun menyisipkan
     * parameter lain. Pesan kosong berarti tanpa '?text=' sama sekali.
     *
     * Mengembalikan null bila nomornya bukan nomor seluler Indonesia yang
     * sah -- pemanggil TIDAK boleh menggantinya dengan href="#".
     */
    public static function toIndonesianChatUrl(?string $digits, ?string $message = null): ?string
    {
        if (! self::isIndonesianMobile($digits)) {
            return null;
        }

        $url = 'https://wa.me/'.$digits;
        $message = is_string($message) ? trim($message) : '';

        return $message === '' ? $url : $url.'?text='.rawurlencode($message);
    }
}
