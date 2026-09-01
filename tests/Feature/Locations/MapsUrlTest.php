<?php

namespace Tests\Feature\Locations;

use App\Support\MapsUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Penjaga tautan Google Maps -- diuji langsung karena juga dipakai ulang
 * saat render halaman publik, bukan hanya saat validasi form.
 */
class MapsUrlTest extends TestCase
{
    // ------------------------------------------------------- koordinat

    public function test_coordinates_build_the_official_maps_url(): void
    {
        $this->assertSame(
            'https://www.google.com/maps/search/?api=1&query=-6.8123456%2C107.1234567',
            MapsUrl::fromCoordinates(-6.8123456, 107.1234567),
        );
    }

    public function test_trailing_zeroes_are_trimmed(): void
    {
        $this->assertSame(
            'https://www.google.com/maps/search/?api=1&query=-6.5%2C107.25',
            MapsUrl::fromCoordinates(-6.5, 107.25),
        );
    }

    /**
     * @return array<string, array{mixed, mixed}>
     */
    public static function invalidCoordinateProvider(): array
    {
        return [
            'null' => [null, null],
            'kosong' => ['', ''],
            'bukan angka' => ['abc', 'def'],
            'latitude di luar rentang' => [91, 107],
            'latitude negatif di luar rentang' => [-91, 107],
            'longitude di luar rentang' => [-6, 181],
            'longitude negatif di luar rentang' => [-6, -181],
            'null island' => [0, 0],
            'hanya latitude' => [-6.8, null],
            'hanya longitude' => [null, 107.1],
        ];
    }

    #[DataProvider('invalidCoordinateProvider')]
    public function test_invalid_coordinates_produce_no_url(mixed $latitude, mixed $longitude): void
    {
        $this->assertFalse(MapsUrl::hasValidCoordinates($latitude, $longitude));
        $this->assertNull(MapsUrl::fromCoordinates($latitude, $longitude));
    }

    // ------------------------------------------------------ link diterima

    /**
     * @return array<string, array{string}>
     */
    public static function safeMapsUrlProvider(): array
    {
        return [
            'google maps search' => ['https://www.google.com/maps/search/?api=1&query=-6.8,107.1'],
            'google maps place' => ['https://www.google.com/maps/place/Contoh+Tempat'],
            'maps subdomain' => ['https://maps.google.com/?q=-6.8,107.1'],
            'google tanpa www' => ['https://google.com/maps'],
            'short link maps app' => ['https://maps.app.goo.gl/AbCdEf123'],
            'short link goo.gl' => ['https://goo.gl/maps/AbCdEf123'],
            'domain negara' => ['https://www.google.co.id/maps'],
            'domain negara maps' => ['https://maps.google.co.id/?q=-6.8,107.1'],
        ];
    }

    #[DataProvider('safeMapsUrlProvider')]
    public function test_it_accepts_legitimate_google_maps_links(string $url): void
    {
        $this->assertTrue(MapsUrl::isSafe($url), "Seharusnya diterima: {$url}");
        $this->assertSame($url, MapsUrl::sanitize($url));
    }

    // -------------------------------------------------------- link ditolak

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeMapsUrlProvider(): array
    {
        return [
            // Skema berbahaya.
            'javascript' => ['javascript:alert(1)'],
            'javascript huruf besar' => ['JavaScript:alert(1)'],
            'data uri' => ['data:text/html,<script>alert(1)</script>'],
            'file' => ['file:///c:/windows/win.ini'],
            'vbscript' => ['vbscript:msgbox(1)'],
            'blob' => ['blob:https://www.google.com/uuid'],

            // Protocol-relative dan penyelundupan.
            'protocol relative' => ['//maps.google.com/maps'],
            'protocol relative evil' => ['//evil.example.com'],
            'backslash smuggling' => ['https:\\\\www.google.com/maps'],
            'backslash di path' => ['https://www.google.com\\@evil.example.com'],
            'tab di scheme' => ["java\tscript:alert(1)"],
            // Newline DI TENGAH URL, bukan di ujung: spasi/baris baru di ujung
            // memang dirapikan trim() lebih dulu, dan itu perilaku yang benar.
            'newline di tengah host' => ["https://www.goo\ngle.com/maps"],
            'newline sebelum host' => ["https://\nevil.example.com/maps"],
            'null byte' => ["https://www.google.com/maps\x00.evil.com"],

            // userinfo.
            'userinfo' => ['https://www.google.com@evil.example.com/maps'],
            'userinfo dengan sandi' => ['https://user:pass@evil.example.com/maps'],

            // Host palsu yang memuat kata "google".
            'subdomain palsu' => ['https://google.com.evil.example.com/maps'],
            'awalan palsu' => ['https://notgoogle.com/maps'],
            'sisipan palsu' => ['https://google.evil.com/maps'],
            'tanpa titik' => ['https://googlecom/maps'],
            'mirip goo.gl' => ['https://goo.gl.evil.com/maps'],
            'mirip maps.app' => ['https://maps.app.goo.gl.evil.com/x'],

            // Bukan HTTPS.
            'http polos' => ['http://www.google.com/maps'],
            'http maps' => ['http://maps.google.com/maps'],

            // Port dan bentuk lain.
            'port kustom' => ['https://www.google.com:8443/maps'],
            'kosong' => [''],
            'spasi saja' => ['   '],
            'bukan url' => ['google maps depan pasar'],
            'ip address' => ['https://142.250.190.78/maps'],
        ];
    }

    #[DataProvider('unsafeMapsUrlProvider')]
    public function test_it_rejects_unsafe_or_fake_links(string $url): void
    {
        $this->assertFalse(MapsUrl::isSafe($url), "Seharusnya ditolak: {$url}");
        $this->assertNull(MapsUrl::sanitize($url), "sanitize() harus null untuk: {$url}");
    }

    public function test_trailing_dot_host_is_normalised_not_bypassed(): void
    {
        // "google.com." menunjuk host yang sama; harus tetap dikenali sebagai
        // Google, bukan lolos begitu saja sebagai host asing.
        $this->assertTrue(MapsUrl::isAllowedHost('www.google.com.'));
        $this->assertFalse(MapsUrl::isAllowedHost('www.google.com.evil.com'));
    }

    public function test_host_matching_is_not_a_substring_check(): void
    {
        foreach (['evilgoogle.com', 'google.com.id.evil.com', 'my-google.com', 'googles.com'] as $host) {
            $this->assertFalse(MapsUrl::isAllowedHost($host), "Host palsu diterima: {$host}");
        }
    }

    public function test_non_string_values_are_rejected(): void
    {
        foreach ([null, 123, [], true, new \stdClass] as $value) {
            $this->assertFalse(MapsUrl::isSafe($value));
            $this->assertNull(MapsUrl::sanitize($value));
        }
    }

    // ----------------------------------------------------------- resolve

    public function test_coordinates_take_priority_over_a_manual_link(): void
    {
        $resolved = MapsUrl::resolve(-6.8, 107.1, 'https://maps.app.goo.gl/AbCdEf123');

        $this->assertStringContainsString('maps/search/?api=1', $resolved);
        $this->assertStringNotContainsString('goo.gl', $resolved);
    }

    public function test_a_manual_link_is_used_when_coordinates_are_missing(): void
    {
        $this->assertSame(
            'https://maps.app.goo.gl/AbCdEf123',
            MapsUrl::resolve(null, null, 'https://maps.app.goo.gl/AbCdEf123'),
        );
    }

    public function test_nothing_valid_produces_null(): void
    {
        $this->assertNull(MapsUrl::resolve(null, null, 'javascript:alert(1)'));
        $this->assertNull(MapsUrl::resolve(null, null, null));
    }
}
