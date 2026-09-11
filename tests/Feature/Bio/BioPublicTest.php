<?php

namespace Tests\Feature\Bio;

use App\Enums\BioButton;
use App\Enums\SocialIcon;
use App\Models\BioSetting;
use App\Models\SocialLink;
use App\Services\BioCatalogService;
use App\Services\BioSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Bio\Concerns\BuildsBio;
use Tests\TestCase;

/**
 * Halaman /bio beserta aturan yang berlaku untuk ketiga route Bio.
 */
class BioPublicTest extends TestCase
{
    use BuildsBio;
    use RefreshDatabase;

    /** @return list<string> */
    protected function bioRoutes(): array
    {
        return [route('bio.home'), route('bio.locations'), route('bio.products')];
    }

    // ---------------------------------------------------------------- route

    public function test_the_three_routes_have_stable_names_and_paths(): void
    {
        $this->assertSame(url('/bio'), route('bio.home'));
        $this->assertSame(url('/bio/lokasi'), route('bio.locations'));
        $this->assertSame(url('/bio/produk'), route('bio.products'));
    }

    public function test_all_three_routes_answer_while_bio_is_active(): void
    {
        $this->bio();

        foreach ($this->bioRoutes() as $url) {
            $this->get($url)->assertOk();
        }
    }

    // -------------------------------------------------------- aktif/nonaktif

    public function test_every_route_is_a_tidy_404_before_the_bio_exists(): void
    {
        foreach ($this->bioRoutes() as $url) {
            $this->get($url)
                ->assertNotFound()
                ->assertSee('Halaman belum tersedia', false)
                ->assertSee('noindex, follow', false);
        }
    }

    public function test_every_route_is_a_tidy_404_while_bio_is_inactive(): void
    {
        $this->bio(['is_active' => false]);

        foreach ($this->bioRoutes() as $url) {
            $response = $this->get($url)->assertNotFound();

            $response->assertSee('Halaman belum tersedia', false);
            $response->assertSee(route('home'), false);
            $this->assertSame(1, substr_count($response->getContent(), '<h1'));
        }
    }

    /**
     * Membuka halaman pengaturan membuat baris singleton, tetapi baris baru
     * SELALU nonaktif -- tidak ada yang terbit tanpa keputusan admin.
     */
    public function test_a_freshly_created_settings_row_is_inactive(): void
    {
        $record = app(BioSettingsService::class)->recordOrCreate();

        $this->assertFalse($record->is_active);
        $this->get(route('bio.home'))->assertNotFound();
    }

    // ----------------------------------------------------------------- SEO

    public function test_every_route_is_noindex_with_its_own_canonical(): void
    {
        $this->bio();

        foreach ($this->bioRoutes() as $url) {
            $html = $this->get($url.'?utm_source=ig&fbclid=abc')->assertOk()->getContent();

            $this->assertStringContainsString('<meta name="robots" content="noindex, follow">', $html);
            // Canonical milik route itu sendiri, TANPA parameter iklan.
            $this->assertStringContainsString('<link rel="canonical" href="'.$url.'">', $html);
            $this->assertStringNotContainsString('utm_source', $html);
            $this->assertStringNotContainsString('fbclid', $html);
        }
    }

    public function test_every_route_has_exactly_one_h1(): void
    {
        $this->bio();

        foreach ($this->bioRoutes() as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, '<h1'), "{$url} harus punya tepat satu H1.");
        }
    }

    public function test_the_bio_pages_never_enter_the_sitemap(): void
    {
        $this->bio();

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertDontSee('/bio', false);
    }

    /**
     * Tidak ada analytics, pixel, maupun modul yang di luar scope fase ini.
     */
    public function test_no_tracking_and_no_out_of_scope_modules(): void
    {
        $this->bio();
        SocialLink::factory()->create();

        foreach ($this->bioRoutes() as $url) {
            $html = strtolower($this->get($url)->assertOk()->getContent());

            foreach (['gtag(', 'googletagmanager', 'fbq(', 'connect.facebook.net', 'analytics.js', 'lowongan', 'kemitraan'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $html, "{$url} memuat {$forbidden}.");
            }

            $this->assertStringNotContainsStringIgnoringCase('dimsumin', $html);
        }
    }

    // --------------------------------------------------------------- isi

    public function test_the_title_and_description_fall_back_to_the_brand(): void
    {
        $this->bio(['title' => null, 'description' => null]);

        $this->get(route('bio.home'))
            ->assertOk()
            ->assertSee(config('dimdum.name'), false)
            ->assertSee(config('dimdum.tagline'), false);
    }

    public function test_the_admin_title_and_description_are_shown(): void
    {
        $this->bio(['title' => 'Jajan DIMDUM', 'description' => 'Semua tautan resmi di satu tempat.']);

        $this->get(route('bio.home'))
            ->assertOk()
            ->assertSee('Jajan DIMDUM', false)
            ->assertSee('Semua tautan resmi di satu tempat.', false);
    }

    /**
     * Tujuan tombol Lokasi dan Menu berasal dari named route di kode.
     */
    public function test_the_location_and_menu_buttons_point_at_named_routes(): void
    {
        $this->bio();

        $html = $this->get(route('bio.home'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '#<a href="'.preg_quote(route('bio.locations'), '#').'"[^>]*data-bio-button="location"#',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '#<a href="'.preg_quote(route('bio.products'), '#').'"[^>]*data-bio-button="menu"#',
            $html,
        );
    }

    /**
     * Payload tombol di database yang berisi URL karangan tidak berpengaruh:
     * tidak ada kolom URL, dan key asing dibuang.
     */
    public function test_a_forged_button_payload_cannot_inject_a_url(): void
    {
        $this->bio(['buttons' => [
            ['key' => 'location', 'label' => 'Lokasi', 'visible' => true, 'url' => 'https://jahat.test'],
            ['key' => 'jahat', 'label' => 'Klik', 'visible' => true, 'url' => 'javascript:alert(1)'],
        ]]);

        $html = $this->get(route('bio.home'))->assertOk()->getContent();

        $this->assertStringNotContainsString('jahat.test', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString(route('bio.locations'), $html);
    }

    public function test_the_button_order_follows_the_admin(): void
    {
        $this->bio([
            'whatsapp_number' => '6281234567890',
            'buttons' => [
                ['key' => 'whatsapp', 'label' => 'Chat Dulu', 'visible' => true],
                ['key' => 'menu', 'label' => 'Menu Kami', 'visible' => true],
                ['key' => 'location', 'label' => 'Cari Gerobak', 'visible' => true],
            ],
        ]);

        $html = $this->get(route('bio.home'))->assertOk()->getContent();

        $this->assertLessThan(strpos($html, 'Menu Kami'), strpos($html, 'Chat Dulu'));
        $this->assertLessThan(strpos($html, 'Cari Gerobak'), strpos($html, 'Menu Kami'));
    }

    public function test_a_hidden_button_is_not_rendered(): void
    {
        $this->bio(['buttons' => [
            ['key' => 'location', 'label' => 'Cari Gerobak', 'visible' => true],
            ['key' => 'menu', 'label' => 'Menu Tersembunyi', 'visible' => false],
            ['key' => 'whatsapp', 'label' => 'Chat', 'visible' => false],
        ]]);

        $this->get(route('bio.home'))
            ->assertOk()
            ->assertSee('Cari Gerobak', false)
            ->assertDontSee('Menu Tersembunyi', false)
            ->assertDontSee('data-bio-button="menu"', false);
    }

    // ------------------------------------------------------------- WhatsApp

    public function test_a_valid_whatsapp_number_builds_a_wa_me_link_with_the_message(): void
    {
        $this->bio([
            'whatsapp_number' => '6281234567890',
            'whatsapp_message' => 'Halo DIMDUM, mau tanya lokasi & menu?',
        ]);

        $this->get(route('bio.home'))
            ->assertOk()
            ->assertSee('https://wa.me/6281234567890?text=Halo%20DIMDUM%2C%20mau%20tanya%20lokasi%20%26%20menu%3F', false)
            ->assertSee('rel="noopener noreferrer"', false);
    }

    public function test_an_empty_message_leaves_no_text_parameter(): void
    {
        $this->bio(['whatsapp_number' => '6281234567890', 'whatsapp_message' => null]);

        $html = $this->get(route('bio.home'))->assertOk()->getContent();

        $this->assertStringContainsString('href="https://wa.me/6281234567890"', $html);
        $this->assertStringNotContainsString('?text=', $html);
    }

    public function test_an_empty_whatsapp_number_hides_the_button_without_a_dead_link(): void
    {
        $this->bio(['whatsapp_number' => null]);

        $html = $this->get(route('bio.home'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-bio-button="whatsapp"', $html);
        $this->assertStringNotContainsString('wa.me', $html);
        $this->assertStringNotContainsString('href="#"', $html);
    }

    /**
     * Nomor tidak sah yang lolos ke database lewat jalur lain tetap tidak
     * pernah menjadi tombol.
     */
    public function test_an_invalid_stored_number_hides_the_button(): void
    {
        foreach (['12345', '15551234567', '622112345678', 'bukan-nomor'] as $invalid) {
            // Ditulis langsung, melewati normalisasi form.
            BioSetting::query()->delete();
            $this->bio(['whatsapp_number' => $invalid]);

            $html = $this->get(route('bio.home'))->assertOk()->getContent();

            $this->assertStringNotContainsString('data-bio-button="whatsapp"', $html, "Nomor {$invalid} lolos.");
            $this->assertStringNotContainsString('href="#"', $html);
        }
    }

    // --------------------------------------------------------- social media

    public function test_only_active_social_links_appear_in_admin_order(): void
    {
        $this->bio();

        SocialLink::factory()->create(['name' => 'Kedua', 'sort_order' => 2, 'url' => 'https://kedua.test']);
        SocialLink::factory()->create(['name' => 'Pertama', 'sort_order' => 1, 'url' => 'https://pertama.test']);
        SocialLink::factory()->inactive()->create(['name' => 'Nonaktif', 'sort_order' => 3]);

        $html = $this->get(route('bio.home'))->assertOk()->getContent();

        $this->assertStringContainsString('Pertama', $html);
        $this->assertStringContainsString('Kedua', $html);
        $this->assertStringNotContainsString('Nonaktif', $html);
        $this->assertLessThan(strpos($html, 'Kedua'), strpos($html, 'Pertama'));
    }

    public function test_social_links_open_safely_in_a_new_tab(): void
    {
        $this->bio();
        SocialLink::factory()->create(['url' => 'https://www.instagram.test/dimdum']);

        $this->get(route('bio.home'))
            ->assertOk()
            ->assertSee('href="https://www.instagram.test/dimdum"', false)
            ->assertSee('target="_blank"', false)
            ->assertSee('rel="noopener noreferrer"', false);
    }

    /**
     * URL berbahaya yang ditulis langsung ke database tidak pernah dicetak.
     */
    public function test_a_dangerous_stored_url_is_never_rendered(): void
    {
        $this->bio();

        foreach ([
            'javascript:alert(1)',
            'data:text/html,<script>alert(1)</script>',
            'https://tampak-benar.test@jahat.test',
            'https://baik.test\\@jahat.test',
            "java\tscript:alert(1)",
            'ftp://file.test/menu',
        ] as $i => $url) {
            DB::table('social_links')->insert([
                'name' => 'Berbahaya '.$i,
                'url' => $url,
                'icon' => 'link',
                'is_active' => true,
                'sort_order' => $i,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        BioCatalogService::flushCache();

        $html = $this->get(route('bio.home'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Berbahaya', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('jahat.test', $html);
    }

    public function test_an_unknown_icon_falls_back_to_the_generic_icon(): void
    {
        $this->bio();

        DB::table('social_links')->insert([
            'name' => 'Platform Baru',
            'url' => 'https://platform-baru.test',
            'icon' => '<svg onload=alert(1)>',
            'is_active' => true,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        BioCatalogService::flushCache();

        $html = $this->get(route('bio.home'))->assertOk()->getContent();

        $this->assertStringContainsString('Platform Baru', $html);
        $this->assertStringNotContainsString('onload', $html);
        $this->assertSame(SocialIcon::Link, SocialIcon::resolve('<svg onload=alert(1)>'));
    }

    public function test_the_page_stays_whole_without_buttons_or_social_links(): void
    {
        $this->bio(['buttons' => array_map(
            fn (BioButton $button): array => ['key' => $button->value, 'label' => '', 'visible' => false],
            BioButton::cases(),
        )]);

        $this->get(route('bio.home'))
            ->assertOk()
            ->assertSee('sedang disiapkan', false);
    }

    // ---------------------------------------------------------------- query

    public function test_the_home_query_count_does_not_grow_per_link(): void
    {
        $this->bio();

        SocialLink::factory()->count(2)->create();
        $few = $this->queriesFor(route('bio.home'));

        SocialLink::factory()->count(15)->create();
        $many = $this->queriesFor(route('bio.home'));

        $this->assertSame(count($few), count($many), 'Query bertambah seiring jumlah tautan.');
    }

    public function test_a_warm_cache_serves_the_home_page_without_bio_queries(): void
    {
        $this->bio();
        SocialLink::factory()->count(3)->create();

        $this->get(route('bio.home'))->assertOk();

        $queries = $this->queriesFor(route('bio.home'));

        $this->assertSame(
            [],
            array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'bio_settings') || str_contains($sql, 'social_links'))),
        );
    }

    /**
     * @return list<string>
     */
    protected function queriesFor(string $url): array
    {
        $queries = [];

        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->get($url)->assertOk();

        return $queries;
    }
}
