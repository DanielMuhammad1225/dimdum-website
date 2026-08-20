<?php

namespace Tests\Feature;

use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HomepageTest extends TestCase
{
    /**
     * Homepage fase pertama tidak menyentuh database, jadi tidak ada
     * RefreshDatabase di sini secara sengaja.
     */
    public function test_homepage_returns_successful_response(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_homepage_uses_home_controller_via_named_route(): void
    {
        $route = app('router')->getRoutes()->getByName('home');

        $this->assertNotNull($route, 'Route bernama [home] tidak ditemukan.');
        $this->assertSame('/', $route->uri());
        $this->assertStringContainsString(HomeController::class, $route->getActionName());
    }

    public function test_homepage_shows_hero_copy_and_final_tagline(): void
    {
        $this->get('/')
            ->assertSee('Bebas Pilih, Jajan Sesukamu.', false)
            ->assertSee('Street Food Dimsum Satuan')
            ->assertSee('Dimsum mulai Rp1.000, bikin ketagihan.', false)
            ->assertSee('Pilih varian favoritmu, tentukan sendiri jumlahnya, lalu jajan sesuai budget kamu.', false);
    }

    public function test_homepage_does_not_show_retired_tagline(): void
    {
        $content = $this->get('/')->getContent();

        $this->assertStringNotContainsStringIgnoringCase('Bebas Pilih · Hemat', $content);
        $this->assertStringNotContainsStringIgnoringCase('Enak · Bebas Pilih', $content);

        // Varian tagline yang ter-bake di aset yang ditolak juga tidak boleh
        // bocor ke markup (alt text, komentar, path file).
        $this->assertStringNotContainsStringIgnoringCase('Rasa Bikin Ketagihan', $content);
        $this->assertStringNotContainsStringIgnoringCase('Mulai Seribuan', $content);
    }

    public function test_homepage_has_no_reference_to_other_brand(): void
    {
        $this->assertStringNotContainsStringIgnoringCase('dimsumin', $this->get('/')->getContent());
    }

    public function test_homepage_exposes_all_navigation_anchors(): void
    {
        $content = $this->get('/')->getContent();

        foreach (['pilihan-dimsum', 'cara-jajan', 'lokasi'] as $anchor) {
            $this->assertStringContainsString('id="'.$anchor.'"', $content);
            $this->assertStringContainsString('href="#'.$anchor.'"', $content);
        }
    }

    public function test_homepage_has_no_dummy_links(): void
    {
        $content = $this->get('/')->getContent();

        $this->assertStringNotContainsString('href="#"', $content);
        $this->assertDoesNotMatchRegularExpression('/href="(javascript:|)"/', $content);
    }

    public function test_homepage_renders_single_h1(): void
    {
        $this->assertSame(1, substr_count($this->get('/')->getContent(), '<h1'));
    }

    public function test_homepage_renders_section_content(): void
    {
        $this->get('/')
            ->assertSee('Jajan Jadi Lebih Bebas')
            ->assertSee('Pilih yang Kamu Suka')
            ->assertSee('Kamu yang Tentukan Cara Jajannya')
            ->assertSee('Budget Berapa Pun, Tetap Bisa Jajan')
            ->assertSee('Cari DIMDUM di Dekat Kamu')
            ->assertSee('Udah Siap Pilih Dimsum Sesukamu?', false)
            ->assertSee('Informasi lokasi segera hadir');
    }

    public function test_homepage_lists_configured_product_variants(): void
    {
        $response = $this->get('/');

        foreach (config('homepage.products.items') as $item) {
            $response->assertSee($item['name']);
        }
    }

    public function test_homepage_renders_seo_metadata(): void
    {
        $this->get('/')
            ->assertSee('DIMDUM | Dimsum Mulai Rp1.000, Bikin Ketagihan', false)
            ->assertSee('DIMDUM adalah street food dimsum satuan mulai Rp1.000.', false)
            ->assertSee('rel="canonical"', false)
            ->assertSee('og:title', false);
    }

    public function test_homepage_does_not_touch_the_database(): void
    {
        DB::listen(function (): void {
            $this->fail('Homepage tidak boleh melakukan query database pada fase ini.');
        });

        $this->get('/')->assertOk();
    }

    public function test_every_image_has_an_alt_attribute(): void
    {
        $content = $this->get('/')->getContent();

        preg_match_all('/<img\s[^>]*>/i', $content, $matches);

        $this->assertNotEmpty($matches[0], 'Homepage seharusnya sudah memuat gambar brand.');

        foreach ($matches[0] as $tag) {
            $this->assertMatchesRegularExpression(
                '/\salt="[^"]*"/i',
                $tag,
                "Tag <img> tanpa atribut alt: {$tag}"
            );
        }
    }

    public function test_images_declare_explicit_dimensions(): void
    {
        $content = $this->get('/')->getContent();

        preg_match_all('/<img\s[^>]*>/i', $content, $matches);

        foreach ($matches[0] as $tag) {
            $this->assertMatchesRegularExpression('/\swidth="\d+"/i', $tag, "Tag <img> tanpa width: {$tag}");
            $this->assertMatchesRegularExpression('/\sheight="\d+"/i', $tag, "Tag <img> tanpa height: {$tag}");
        }
    }

    public function test_all_referenced_local_assets_exist_on_disk(): void
    {
        $content = $this->get('/')->getContent();

        preg_match_all('/(?:src|srcset|href)="([^"]+\.(?:png|jpe?g|webp|svg|ico))"/i', $content, $matches);

        $this->assertNotEmpty($matches[1], 'Tidak ada aset gambar yang dirujuk homepage.');

        foreach (array_unique($matches[1]) as $url) {
            $path = public_path(parse_url($url, PHP_URL_PATH) ?? $url);

            $this->assertFileExists($path, "Aset dirujuk tapi tidak ada di disk (akan 404): {$url}");
            $this->assertGreaterThan(0, filesize($path), "Aset kosong (0 byte): {$url}");
        }
    }

    public function test_brand_logo_and_favicon_are_wired_up(): void
    {
        $this->get('/')
            ->assertSee('dimdum-logo-horizontal', false)
            ->assertSee('rel="apple-touch-icon"', false)
            ->assertSee('og:image', false);
    }

    public function test_sections_resolve_only_through_the_allowlist(): void
    {
        config([
            'homepage.sections' => ['hero', '../../../etc/passwd', 'welcome', 'cta'],
        ]);

        $sections = (new HomeController)->__invoke()->getData()['sections'];

        $this->assertSame(['sections.hero', 'sections.cta'], $sections);
    }

    public function test_unregistered_section_key_cannot_render(): void
    {
        config(['homepage.sections' => ['hero', 'tidak-terdaftar']]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Bebas Pilih, Jajan Sesukamu.', false)
            ->assertDontSee('tidak-terdaftar');
    }

    public function test_section_order_is_configurable(): void
    {
        config(['homepage.sections' => ['cta', 'hero']]);

        $content = $this->get('/')->getContent();

        $this->assertLessThan(
            strpos($content, 'Bebas Pilih, Jajan Sesukamu.'),
            strpos($content, 'Udah Siap Pilih Dimsum Sesukamu?'),
            'Urutan section harus mengikuti config.'
        );
    }

    public function test_all_assets_are_self_hosted(): void
    {
        $content = $this->get('/')->getContent();

        preg_match_all('/(?:src|href)="(https?:\/\/[^"]+)"/i', $content, $matches);

        $appUrl = rtrim(config('app.url'), '/');

        foreach ($matches[1] as $url) {
            $this->assertStringStartsWith(
                $appUrl,
                $url,
                "Aset harus di-self-host, bukan dari domain luar: {$url}"
            );
        }
    }

    public function test_no_tracking_scripts_are_present(): void
    {
        $content = $this->get('/')->getContent();

        foreach (['googletagmanager', 'google-analytics', 'gtag(', 'fbq(', 'connect.facebook.net', 'ttq.', 'analytics.tiktok'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $content);
        }
    }

    public function test_brand_logo_assets_exist_and_are_not_empty(): void
    {
        foreach (['logo', 'logo_horizontal', 'icon'] as $key) {
            $asset = config("dimdum.assets.{$key}");

            $this->assertIsArray($asset, "Aset [{$key}] harus berupa array.");

            foreach (array_filter([$asset['src'] ?? null, $asset['webp'] ?? null]) as $path) {
                $this->assertFileExists(public_path($path));
                $this->assertGreaterThan(1000, filesize(public_path($path)), "Aset terlalu kecil: {$path}");
            }
        }
    }

    public function test_external_links_are_safe_when_present(): void
    {
        $content = $this->get('/')->getContent();

        preg_match_all('/<a[^>]*target="_blank"[^>]*>/i', $content, $matches);

        $safe = array_filter(
            $matches[0],
            fn (string $tag): bool => str_contains($tag, 'rel="noopener noreferrer"')
        );

        $this->assertCount(
            count($matches[0]),
            $safe,
            'Setiap link yang membuka tab baru wajib memakai rel="noopener noreferrer".'
        );
    }
}
