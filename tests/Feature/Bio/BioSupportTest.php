<?php

namespace Tests\Feature\Bio;

use App\Rules\SafeLink;
use App\Support\SafeUrl;
use App\Support\WhatsAppNumber;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Helper keamanan yang ditambahkan untuk modul Bio.
 *
 * Yang paling penting dijaga di sini: perilaku helper LAMA tidak berubah.
 * normalize() tetap longgar untuk Pengaturan Website, dan isSafeExternal()
 * tetap HTTPS-only untuk seluruh pemakainya.
 */
class BioSupportTest extends TestCase
{
    // -------------------------------------------------------------- WhatsApp

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function validIndonesianNumbers(): array
    {
        return [
            'lokal 0812' => ['081234567890', '6281234567890'],
            'internasional +62' => ['+62 812-3456-7890', '6281234567890'],
            'tanpa awalan' => ['8123456789', '628123456789'],
            'awalan ganda 62 0' => ['62 0812 3456 7890', '6281234567890'],
            'terpendek (10 digit lokal)' => ['0812345678', '62812345678'],
            'terpanjang (13 digit lokal)' => ['0812345678901', '62812345678901'],
        ];
    }

    #[DataProvider('validIndonesianNumbers')]
    public function test_indonesian_mobile_numbers_are_normalised(string $input, string $expected): void
    {
        $this->assertSame($expected, WhatsAppNumber::normalizeIndonesian($input));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidIndonesianNumbers(): array
    {
        return [
            'telepon rumah Jakarta' => ['021 1234 5678'],
            'nomor Amerika' => ['+1 555 123 4567'],
            'nomor Singapura' => ['+65 8123 4567'],
            'terlalu pendek' => ['0812'],
            'terlalu panjang' => ['08123456789012'],
            'huruf' => ['bukan nomor'],
            'kosong' => [''],
            'null' => [null],
            'array' => [['0812']],
        ];
    }

    #[DataProvider('invalidIndonesianNumbers')]
    public function test_non_indonesian_mobile_numbers_are_rejected(mixed $input): void
    {
        $this->assertNull(WhatsAppNumber::normalizeIndonesian($input));
    }

    /**
     * normalize() yang lama TIDAK boleh ikut menjadi ketat: Pengaturan
     * Website bergantung pada perilakunya.
     */
    public function test_the_existing_loose_normaliser_is_unchanged(): void
    {
        $this->assertSame('15551234567', WhatsAppNumber::normalize('+1 555 123 4567'));
        $this->assertSame('622112345678', WhatsAppNumber::normalize('021 1234 5678'));
    }

    public function test_the_chat_url_encodes_the_message(): void
    {
        $this->assertSame(
            'https://wa.me/6281234567890?text=Halo%20%26%20salam%3F%0Abaris%20dua',
            WhatsAppNumber::toIndonesianChatUrl('6281234567890', "Halo & salam?\nbaris dua"),
        );

        $this->assertSame('https://wa.me/6281234567890', WhatsAppNumber::toIndonesianChatUrl('6281234567890', '   '));
        $this->assertNull(WhatsAppNumber::toIndonesianChatUrl('15551234567', 'Halo'));
        $this->assertNull(WhatsAppNumber::toIndonesianChatUrl(null, 'Halo'));
    }

    /**
     * Pesan tidak bisa menyisipkan parameter lain ke URL.
     */
    public function test_the_message_cannot_inject_url_parameters(): void
    {
        $url = WhatsAppNumber::toIndonesianChatUrl('6281234567890', 'a&phone=6280000000&text=jahat#frag');

        $this->assertSame(1, substr_count((string) $url, '?'));
        $this->assertStringNotContainsString('&phone=', (string) $url);
        $this->assertStringNotContainsString('#', (string) $url);
    }

    // ------------------------------------------------------------------ URL

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsafeWebUrls(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'javascript kapital' => ['JAVASCRIPT:alert(1)'],
            'data' => ['data:text/html,<script>alert(1)</script>'],
            'vbscript' => ['vbscript:msgbox(1)'],
            'file' => ['file:///etc/passwd'],
            'ftp' => ['ftp://file.test/menu'],
            'userinfo' => ['https://tampak-benar.test@jahat.test'],
            'userinfo dengan sandi' => ['http://user:rahasia@jahat.test'],
            'backslash' => ['https://baik.test\\@jahat.test'],
            'tab di skema' => ["java\tscript:alert(1)"],
            // NUL di TENGAH ditolak. NUL/spasi di tepi dibuang trim() --
            // perilaku SafeUrl yang sudah ada -- dan yang tersimpan selalu
            // hasil sanitize yang bersih.
            'NUL di tengah' => ["https://baik.test/\x00jahat"],
            'newline di tengah' => ["https://baik.test/\nSet-Cookie:x"],
            'protocol-relative' => ['//jahat.test'],
            'path internal' => ['/halaman'],
            'tanpa titik di host' => ['https://localhost'],
            'kosong' => [''],
        ];
    }

    #[DataProvider('unsafeWebUrls')]
    public function test_unsafe_web_urls_are_rejected(string $url): void
    {
        $this->assertFalse(SafeUrl::isSafeWeb($url));
        $this->assertNull(SafeUrl::sanitizeWeb($url));
        $this->assertTrue(Validator::make(['url' => $url], ['url' => [SafeLink::web()]])->fails() || $url === '');
    }

    /**
     * Karakter kontrol di tepi tidak pernah ikut tersimpan: sanitize
     * mengembalikan URL yang sudah dibersihkan.
     */
    public function test_edge_control_characters_are_stripped_not_stored(): void
    {
        $this->assertSame('https://baik.test/', SafeUrl::sanitizeWeb("https://baik.test/\x00"));
        $this->assertSame('https://baik.test/', SafeUrl::sanitizeWeb("  https://baik.test/\n"));
    }

    public function test_http_and_https_are_both_accepted_for_web_links(): void
    {
        $this->assertTrue(SafeUrl::isSafeWeb('https://www.instagram.test/dimdum'));
        $this->assertTrue(SafeUrl::isSafeWeb('http://toko-lama.test/menu?ref=bio'));
        $this->assertTrue(SafeUrl::isSafeWeb('HTTPS://WWW.TIKTOK.TEST/@dimdum'));
    }

    /**
     * isSafeExternal() tetap HTTPS-only -- tidak ikut dilonggarkan.
     */
    public function test_the_existing_external_check_stays_https_only(): void
    {
        $this->assertTrue(SafeUrl::isSafeExternal('https://www.instagram.test/dimdum'));
        $this->assertFalse(SafeUrl::isSafeExternal('http://toko-lama.test'));
        $this->assertFalse(SafeUrl::isSafeExternal('https://tampak-benar.test@jahat.test'));

        $this->assertTrue(Validator::make(['url' => 'http://toko.test'], ['url' => [SafeLink::externalOnly()]])->fails());
        $this->assertFalse(Validator::make(['url' => 'http://toko.test'], ['url' => [SafeLink::web()]])->fails());
    }
}
