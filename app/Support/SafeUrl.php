<?php

namespace App\Support;

/**
 * Penjaga tunggal untuk seluruh URL/link yang berasal dari admin panel.
 *
 * Dipakai di DUA lapis:
 *   1. Validasi form Filament (App\Rules\SafeLink), supaya admin mendapat
 *      pesan error yang jelas.
 *   2. Saat render publik (service konten), supaya baris database yang
 *      terlanjur berisi nilai berbahaya -- misalnya diubah langsung lewat
 *      SQL -- tetap tidak pernah sampai ke atribut href.
 */
class SafeUrl
{
    /**
     * Scheme yang secara eksplisit ditolak, termasuk variasi penulisannya.
     */
    protected const DANGEROUS_SCHEMES = [
        'javascript',
        'data',
        'vbscript',
        'file',
        'blob',
        'about',
        'filesystem',
        'view-source',
    ];

    /**
     * Apakah nilai ini aman dipakai sebagai href internal ATAU eksternal.
     */
    public static function isSafe(mixed $value): bool
    {
        return static::isSafeInternal($value) || static::isSafeExternal($value);
    }

    /**
     * Link internal: '/', '/path', '#anchor', '/path#anchor', '/path?a=b'.
     */
    public static function isSafeInternal(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $value = trim($value);

        if ($value === '') {
            return false;
        }

        // '#' sendirian adalah link mati -- ditolak, bukan sekadar tidak aman.
        if ($value === '#') {
            return false;
        }

        if (static::hasControlCharacters($value)) {
            return false;
        }

        // Protocol-relative ('//evil.com') diperlakukan sebagai eksternal
        // terselubung dan selalu ditolak.
        if (str_starts_with($value, '//')) {
            return false;
        }

        if (str_starts_with($value, '#')) {
            return (bool) preg_match('/^#[A-Za-z0-9][A-Za-z0-9\-_]*$/', $value);
        }

        if (! str_starts_with($value, '/')) {
            return false;
        }

        // Backslash bisa dinormalisasi jadi '/' oleh sebagian browser,
        // sehingga '/\evil.com' berperilaku seperti protocol-relative.
        if (str_contains($value, '\\')) {
            return false;
        }

        return (bool) preg_match('#^/[A-Za-z0-9\-._~/%?=&+,;:@!$\'()*\#\[\]]*$#', $value);
    }

    /**
     * Link eksternal: hanya https:// dengan host yang wajar.
     */
    public static function isSafeExternal(mixed $value): bool
    {
        return static::isSafeAbsolute($value, ['https']);
    }

    /**
     * Link web: http:// ATAU https://, dengan pemeriksaan yang sama persis.
     *
     * Dipakai tautan social media halaman Bio. Aturan lain tidak dilonggarkan
     * sedikit pun -- userinfo, backslash, karakter kontrol, dan skema
     * berbahaya tetap ditolak -- hanya skema http yang ikut diterima.
     */
    public static function isSafeWeb(mixed $value): bool
    {
        return static::isSafeAbsolute($value, ['http', 'https']);
    }

    /**
     * Pemeriksaan bersama untuk URL absolut.
     *
     * @param  list<string>  $schemes  skema yang boleh, huruf kecil
     */
    protected static function isSafeAbsolute(mixed $value, array $schemes): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $value = trim($value);

        if ($value === '' || static::hasControlCharacters($value)) {
            return false;
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);

        if (! is_string($scheme) || ! in_array(strtolower($scheme), $schemes, true)) {
            return false;
        }

        /*
         | Backslash bisa dinormalisasi menjadi '/' oleh sebagian browser,
         | sehingga 'https://baik.test\@jahat.test' berakhir di host yang
         | berbeda dari yang dibaca parse_url().
         */
        if (str_contains($value, '\\')) {
            return false;
        }

        /*
         | Userinfo ditolak. 'https://tampak-benar.test@jahat.test' mengarah ke
         | jahat.test, tetapi bagi pembaca manusia tampak seperti tautan ke
         | tampak-benar.test -- persis pola phishing yang paling sering lolos.
         */
        if (parse_url($value, PHP_URL_USER) !== null || parse_url($value, PHP_URL_PASS) !== null) {
            return false;
        }

        $host = parse_url($value, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        // Host wajib punya titik dan hanya karakter domain yang sah.
        return (bool) preg_match('/^[A-Za-z0-9]([A-Za-z0-9\-.]*[A-Za-z0-9])?\.[A-Za-z]{2,}$/', $host);
    }

    /**
     * Kembalikan URL bila aman, atau null bila tidak. Dipakai saat render
     * supaya href berbahaya tidak pernah dicetak.
     */
    public static function sanitize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return static::isSafe($value) ? $value : null;
    }

    /**
     * Versi khusus untuk field yang memang hanya boleh https (social media).
     */
    public static function sanitizeExternal(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return static::isSafeExternal($value) ? $value : null;
    }

    /**
     * Versi untuk field yang boleh http maupun https (social media Bio).
     */
    public static function sanitizeWeb(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return static::isSafeWeb($value) ? $value : null;
    }

    /**
     * @return list<string>
     */
    public static function dangerousSchemes(): array
    {
        return static::DANGEROUS_SCHEMES;
    }

    /**
     * Karakter kontrol (termasuk NUL, tab, dan newline) dipakai untuk
     * menyelundupkan scheme: "java\tscript:alert(1)".
     */
    protected static function hasControlCharacters(string $value): bool
    {
        return (bool) preg_match('/[\x00-\x1F\x7F]/', $value);
    }
}
