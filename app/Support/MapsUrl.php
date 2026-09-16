<?php

namespace App\Support;

/**
 * Penjaga tautan Google Maps.
 *
 * Dua sumber tautan:
 *   1. Koordinat -- dirakit server-side, selalu aman.
 *   2. Link manual dari admin -- operasional lapangan lazim memakai link
 *      berbagi, jadi harus diterima TAPI diperiksa ketat.
 *
 * Pemeriksaan dijalankan DUA KALI: saat input di admin, dan sekali lagi saat
 * render halaman publik. Baris database yang tercemar lewat jalur lain tidak
 * boleh pernah menjadi href.
 *
 * Host diperiksa dengan ALLOWLIST EKSAK, bukan str_contains('google'):
 * "google.evil.com", "googlecom.id", dan "notgoogle.com" semuanya memuat kata
 * "google" tetapi bukan milik Google.
 */
class MapsUrl
{
    /**
     * Host yang benar-benar dipakai untuk berbagi lokasi Google Maps.
     *
     * Sengaja sempit. Menambah host baru harus disertai alasan operasional
     * yang jelas, bukan karena namanya terlihat mirip Google.
     */
    protected const ALLOWED_HOSTS = [
        // Google Maps web.
        'google.com',
        'www.google.com',
        'maps.google.com',
        // Short link resmi Google Maps ("Bagikan" di aplikasi Android/iOS).
        'goo.gl',
        'maps.app.goo.gl',
    ];

    /**
     * Domain negara Google (google.co.id, google.co.uk, ...).
     *
     * Dicocokkan dengan pola ketat, bukan pencocokan sebagian, supaya
     * "google.co.id.evil.com" tetap ditolak.
     */
    protected const COUNTRY_HOST_PATTERN = '/^(?:www\.|maps\.)?google\.(?:com?\.)?[a-z]{2}$/';

    /**
     * Rakit URL Maps dari koordinat. Format resmi Google Maps URLs API.
     */
    public static function fromCoordinates(mixed $latitude, mixed $longitude): ?string
    {
        if (! self::hasValidCoordinates($latitude, $longitude)) {
            return null;
        }

        // Dicetak sebagai desimal biasa: notasi ilmiah akan merusak query.
        $lat = rtrim(rtrim(number_format((float) $latitude, 7, '.', ''), '0'), '.');
        $lng = rtrim(rtrim(number_format((float) $longitude, 7, '.', ''), '0'), '.');

        return 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($lat.','.$lng);
    }

    public static function hasValidCoordinates(mixed $latitude, mixed $longitude): bool
    {
        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return false;
        }

        $lat = (float) $latitude;
        $lng = (float) $longitude;

        // 0,0 (Null Island) hampir pasti data kosong yang tidak sengaja tersimpan.
        if ($lat === 0.0 && $lng === 0.0) {
            return false;
        }

        return $lat >= -90.0 && $lat <= 90.0 && $lng >= -180.0 && $lng <= 180.0;
    }

    /**
     * Apakah link manual ini aman dipakai sebagai href?
     */
    public static function isSafe(mixed $value): bool
    {
        return self::sanitize($value) !== null;
    }

    /**
     * Kembalikan URL bila aman, atau null.
     */
    public static function sanitize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $url = trim($value);

        if ($url === '') {
            return null;
        }

        // Karakter kontrol dipakai untuk menyelundupkan scheme:
        // "java\tscript:", "https:\n//evil". Tolak sebelum parsing.
        if (preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return null;
        }

        // Backslash dinormalisasi menjadi '/' oleh sebagian browser.
        if (str_contains($url, '\\')) {
            return null;
        }

        // Protocol-relative: "//maps.google.com" mewarisi scheme halaman.
        if (str_starts_with($url, '//')) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        // HTTPS saja. HTTP polos ditolak, termasuk ke host Google yang sah.
        if (strtolower($parts['scheme']) !== 'https') {
            return null;
        }

        // userinfo ("https://maps.google.com@evil.com") menyamarkan host asli.
        if (isset($parts['user']) || isset($parts['pass']) || str_contains($url, '@')) {
            return null;
        }

        // Port kustom tidak pernah dibutuhkan untuk link Maps publik.
        if (isset($parts['port']) && $parts['port'] !== 443) {
            return null;
        }

        return self::isAllowedHost($parts['host']) ? $url : null;
    }

    /**
     * Cocokkan host terhadap allowlist secara eksak.
     */
    public static function isAllowedHost(mixed $host): bool
    {
        if (! is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower($host);

        // Trailing dot ("google.com.") menunjuk host yang sama tetapi lolos
        // perbandingan string biasa. Rapikan sebelum dicocokkan.
        $host = rtrim($host, '.');

        if ($host === '' || str_contains($host, '..')) {
            return false;
        }

        // IDN/punycode dan karakter non-host langsung ditolak.
        if (! preg_match('/^[a-z0-9]([a-z0-9\-.]*[a-z0-9])?$/', $host)) {
            return false;
        }

        if (in_array($host, self::ALLOWED_HOSTS, true)) {
            return true;
        }

        return (bool) preg_match(self::COUNTRY_HOST_PATTERN, $host);
    }

    /**
     * URL Maps final untuk satu gerobak.
     *
     * Koordinat diutamakan karena dirakit sendiri oleh server; link manual
     * hanya dipakai bila koordinat belum diisi.
     */
    public static function resolve(mixed $latitude, mixed $longitude, mixed $manualUrl): ?string
    {
        return self::fromCoordinates($latitude, $longitude) ?? self::sanitize($manualUrl);
    }

    /** @return list<string> */
    public static function allowedHosts(): array
    {
        return self::ALLOWED_HOSTS;
    }
}
