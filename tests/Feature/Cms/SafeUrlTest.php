<?php

namespace Tests\Feature\Cms;

use App\Support\SafeUrl;
use App\Support\WhatsAppNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Penjaga URL dan normalisasi nomor -- diuji langsung, terpisah dari form,
 * karena keduanya juga dipakai ulang saat render halaman publik.
 */
class SafeUrlTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function safeLinkProvider(): array
    {
        return [
            'root' => ['/'],
            'internal path' => ['/tentang-kami'],
            'nested path' => ['/blog/artikel-pertama'],
            'path with query' => ['/cari?q=dimsum'],
            'anchor' => ['#lokasi'],
            'anchor with dash' => ['#pilihan-dimsum'],
            'https url' => ['https://www.instagram.com/dimdum'],
            'wa.me url' => ['https://wa.me/6281234567890'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeLinkProvider(): array
    {
        return [
            'javascript scheme' => ['javascript:alert(1)'],
            'javascript uppercase' => ['JavaScript:alert(1)'],
            'javascript with spaces' => ['  javascript:alert(1)  '],
            'javascript with tab' => ["java\tscript:alert(1)"],
            'javascript with newline' => ["java\nscript:alert(1)"],
            'javascript with null byte' => ["javascript\x00:alert(1)"],
            'data uri html' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='],
            'data uri svg' => ['data:image/svg+xml,<svg onload=alert(1)>'],
            'vbscript scheme' => ['vbscript:msgbox(1)'],
            'file scheme' => ['file:///c:/windows/win.ini'],
            'protocol relative' => ['//evil.example.com'],
            'protocol relative with path' => ['//evil.example.com/pwn'],
            'backslash smuggling' => ['/\\evil.example.com'],
            'plain http' => ['http://insecure.example.com'],
            'bare domain' => ['evil.example.com'],
            'empty anchor' => ['#'],
            'empty string' => [''],
            'whitespace only' => ['   '],
            'relative path' => ['../../etc/passwd'],
            'about blank' => ['about:blank'],
            'blob scheme' => ['blob:https://example.com/uuid'],
        ];
    }

    #[DataProvider('safeLinkProvider')]
    public function test_it_accepts_safe_links(string $url): void
    {
        $this->assertTrue(SafeUrl::isSafe($url), "Seharusnya aman: {$url}");
        $this->assertSame(trim($url), SafeUrl::sanitize($url));
    }

    #[DataProvider('unsafeLinkProvider')]
    public function test_it_rejects_unsafe_links(string $url): void
    {
        $this->assertFalse(SafeUrl::isSafe($url), "Seharusnya ditolak: {$url}");
        $this->assertNull(SafeUrl::sanitize($url), "sanitize() harus mengembalikan null untuk: {$url}");
    }

    public function test_external_only_mode_rejects_internal_links(): void
    {
        $this->assertFalse(SafeUrl::isSafeExternal('/tentang-kami'));
        $this->assertFalse(SafeUrl::isSafeExternal('#lokasi'));
        $this->assertFalse(SafeUrl::isSafeExternal('http://example.com'));

        $this->assertTrue(SafeUrl::isSafeExternal('https://www.tiktok.com/@dimdum'));
    }

    public function test_non_string_input_is_never_accepted(): void
    {
        foreach ([null, 123, [], new \stdClass, true] as $value) {
            $this->assertFalse(SafeUrl::isSafe($value));
            $this->assertNull(SafeUrl::sanitize($value));
        }
    }

    // ---------------------------------------------------------- WhatsApp

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function whatsAppProvider(): array
    {
        return [
            'local format' => ['081234567890', '6281234567890'],
            'spaced local' => ['0812 3456 7890', '6281234567890'],
            'dashed local' => ['0812-3456-7890', '6281234567890'],
            'plus country code' => ['+6281234567890', '6281234567890'],
            'spaced country code' => ['+62 812-3456-7890', '6281234567890'],
            'bare country code' => ['6281234567890', '6281234567890'],
            'without prefix' => ['81234567890', '6281234567890'],
            'wrapped in text' => ['(0812) 3456 7890', '6281234567890'],
            'empty' => ['', null],
            'letters only' => ['bukan-nomor', null],
            'too short' => ['0812', null],
            'too long' => ['0812345678901234567', null],
        ];
    }

    #[DataProvider('whatsAppProvider')]
    public function test_it_normalizes_whatsapp_numbers(string $input, ?string $expected): void
    {
        $this->assertSame($expected, WhatsAppNumber::normalize($input));
    }

    public function test_it_never_invents_a_number(): void
    {
        $this->assertNull(WhatsAppNumber::normalize(null));
        $this->assertNull(WhatsAppNumber::normalize('   '));
        $this->assertNull(WhatsAppNumber::toUrl(null));
    }

    public function test_it_builds_the_official_whatsapp_url(): void
    {
        $this->assertSame(
            'https://wa.me/6281234567890',
            WhatsAppNumber::toUrl(WhatsAppNumber::normalize('0812-3456-7890')),
        );
    }
}
