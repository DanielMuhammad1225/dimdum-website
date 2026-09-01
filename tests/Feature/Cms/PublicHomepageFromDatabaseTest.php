<?php

namespace Tests\Feature\Cms;

use App\Models\HomepageSetting;
use App\Models\SiteSetting;
use App\Services\HomepageContentService;
use Database\Seeders\HomepageContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Perilaku homepage publik setelah sumber datanya berpindah ke database.
 */
class PublicHomepageFromDatabaseTest extends TestCase
{
    use RefreshDatabase;

    protected function homepageSetting(): HomepageSetting
    {
        return HomepageSetting::query()->firstOrFail();
    }

    protected function seedContent(): void
    {
        $this->seed(HomepageContentSeeder::class);
    }

    // ----------------------------------------------------- isi awal identik

    public function test_seeded_homepage_is_identical_to_the_config_only_version(): void
    {
        // Render pertama: baris belum ada, jadi seluruh isi berasal dari config.
        $beforeSeeding = $this->get('/')->getContent();

        $this->seedContent();
        $this->flushContentCache();

        // Render kedua: isi berasal dari database hasil bootstrap config.
        $afterSeeding = $this->get('/')->getContent();

        $this->assertSame(
            $this->comparableMarkup($beforeSeeding),
            $this->comparableMarkup($afterSeeding),
            'Bootstrap konten ke database tidak boleh mengubah isi homepage.'
        );
    }

    /**
     * Bagian halaman yang benar-benar ditentukan oleh konten: seluruh <main>
     * (header, section, footer copy) plus metadata SEO.
     *
     * Blok preload/@font-face dari Vite sengaja dikecualikan karena isinya
     * bergantung pada manifest build, bukan pada sumber konten.
     */
    protected function comparableMarkup(string $html): string
    {
        preg_match('#<main\b.*</main>#s', $html, $main);
        preg_match('#<title>.*</title>#s', $html, $title);
        preg_match('#<meta name="description"[^>]*>#', $html, $description);
        preg_match('#<footer\b.*</footer>#s', $html, $footer);

        return implode("\n", [
            $title[0] ?? '',
            $description[0] ?? '',
            $main[0] ?? '',
            $footer[0] ?? '',
        ]);
    }

    public function test_homepage_stays_successful_and_keeps_brand_copy(): void
    {
        $this->seedContent();

        $this->get('/')
            ->assertOk()
            ->assertSee('Bebas Pilih, Jajan Sesukamu.', false)
            ->assertSee('Dimsum mulai Rp1.000, bikin ketagihan.', false);
    }

    public function test_retired_tagline_and_other_brand_never_appear(): void
    {
        $this->seedContent();

        $content = $this->get('/')->getContent();

        $this->assertStringNotContainsStringIgnoringCase('Enak · Bebas Pilih', $content);
        $this->assertStringNotContainsStringIgnoringCase('Bebas Pilih · Hemat', $content);
        $this->assertStringNotContainsStringIgnoringCase('dimsumin', $content);
    }

    // ------------------------------------------------- perubahan admin nyata

    public function test_database_changes_appear_on_the_homepage(): void
    {
        $this->seedContent();

        $setting = $this->homepageSetting();
        $setting->update([
            'hero' => [
                ...$setting->hero,
                'headline' => 'Headline Hasil Admin Panel',
                'eyebrow' => 'Eyebrow Hasil Admin Panel',
            ],
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Headline Hasil Admin Panel', false)
            ->assertSee('Eyebrow Hasil Admin Panel', false)
            ->assertDontSee(config('homepage.hero.headline'), false);
    }

    public function test_site_settings_drive_metadata_and_brand_name(): void
    {
        $this->seedContent();

        SiteSetting::query()->first()->update([
            'brand_name' => 'DIMDUM Uji',
            'tagline' => 'Tagline uji dari database.',
            'default_meta_title' => 'Judul Meta Dari Database',
            'default_meta_description' => 'Deskripsi meta dari database.',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('<title>Judul Meta Dari Database</title>', false)
            ->assertSee('Deskripsi meta dari database.', false)
            ->assertSee('Tagline uji dari database.', false);
    }

    // ------------------------------------------------------------- sections

    public function test_a_section_can_be_disabled(): void
    {
        $this->seedContent();

        $this->homepageSetting()->update([
            'sections' => [
                ['key' => 'hero', 'enabled' => true],
                ['key' => 'budget', 'enabled' => false],
                ['key' => 'cta', 'enabled' => true],
            ],
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Bebas Pilih, Jajan Sesukamu.', false)
            ->assertDontSee('Budget Berapa Pun, Tetap Bisa Jajan', false);
    }

    public function test_sections_render_in_the_stored_order(): void
    {
        $this->seedContent();

        $this->homepageSetting()->update([
            'sections' => [
                ['key' => 'cta', 'enabled' => true],
                ['key' => 'hero', 'enabled' => true],
            ],
        ]);

        $content = $this->get('/')->getContent();

        $this->assertLessThan(
            strpos($content, 'Bebas Pilih, Jajan Sesukamu.'),
            strpos($content, 'Udah Siap Pilih Dimsum Sesukamu?'),
            'Urutan section harus mengikuti database.'
        );
    }

    public function test_unknown_section_keys_are_ignored(): void
    {
        $this->seedContent();

        $this->homepageSetting()->update([
            'sections' => [
                ['key' => 'hero', 'enabled' => true],
                ['key' => '../../../etc/passwd', 'enabled' => true],
                ['key' => 'sections.hero', 'enabled' => true],
                ['key' => 'layouts.public', 'enabled' => true],
                ['key' => 'tidak-terdaftar', 'enabled' => true],
                ['key' => 'cta', 'enabled' => true],
            ],
        ]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertDontSee('tidak-terdaftar');
        $response->assertDontSee('etc/passwd');

        $this->assertSame(
            ['hero', 'cta'],
            app(HomepageContentService::class)->sectionKeys(),
            'Hanya key allowlist yang boleh lolos.'
        );
    }

    public function test_duplicate_section_keys_are_collapsed(): void
    {
        $this->seedContent();

        $this->homepageSetting()->update([
            'sections' => [
                ['key' => 'hero', 'enabled' => true],
                ['key' => 'hero', 'enabled' => true],
                ['key' => 'hero', 'enabled' => true],
            ],
        ]);

        $this->assertSame(['hero'], app(HomepageContentService::class)->sectionKeys());
        $this->assertSame(1, substr_count($this->get('/')->getContent(), '<h1'));
    }

    public function test_a_blade_view_name_stored_as_a_key_cannot_render_anything(): void
    {
        $this->seedContent();

        $this->homepageSetting()->update([
            'sections' => [['key' => 'sections.budget', 'enabled' => true]],
        ]);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('Budget Berapa Pun, Tetap Bisa Jajan', false);
    }

    // ---------------------------------------------------- empty state produk

    public function test_the_products_empty_state_is_shown_when_no_variants_exist(): void
    {
        $this->seedContent();

        $setting = $this->homepageSetting();
        $setting->update([
            'products' => [
                ...$setting->products,
                'empty_state' => [
                    'title' => 'Varian belum tersedia',
                    'description' => 'Daftar dimsum sedang disiapkan.',
                ],
            ],
        ]);

        // Daftar varian masih berasal dari config sampai modul produk dibuat.
        config(['homepage.products.items' => []]);
        $this->flushContentCache();

        $this->get('/')
            ->assertOk()
            ->assertSee('Varian belum tersedia', false)
            ->assertSee('Daftar dimsum sedang disiapkan.', false);
    }

    public function test_the_products_empty_state_is_hidden_while_variants_exist(): void
    {
        $this->seedContent();

        $this->get('/')
            ->assertOk()
            ->assertSee('Dimsum Reguler', false)
            ->assertDontSee(config('homepage.products.empty_state.title'), false);
    }

    // -------------------------------------------------------------- queries

    public function test_blade_components_never_query_the_database(): void
    {
        $this->seedContent();
        $this->flushContentCache();

        $queries = [];

        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->get('/')->assertOk();

        $this->assertLessThanOrEqual(
            3,
            count($queries),
            'Homepage hanya boleh query site settings, homepage settings, dan daftar wilayah: '.implode(' | ', $queries),
        );
    }

    // ------------------------------------------------------------- fallback

    public function test_homepage_falls_back_to_config_when_rows_are_missing(): void
    {
        // Tidak ada seeder: tabelnya ada, barisnya belum.
        $this->assertSame(0, SiteSetting::query()->count());
        $this->assertSame(0, HomepageSetting::query()->count());

        $this->get('/')
            ->assertOk()
            ->assertSee(config('homepage.hero.headline'), false)
            ->assertSee(config('dimdum.tagline'), false)
            ->assertSee(config('dimdum.seo.title'), false);
    }

    public function test_partial_rows_fall_back_field_by_field(): void
    {
        $this->seedContent();

        // Kolom JSON dikosongkan seolah kolomnya belum pernah diisi.
        $this->homepageSetting()->update(['budget' => null, 'usp' => null]);
        $this->flushContentCache();

        $this->get('/')
            ->assertOk()
            ->assertSee(config('homepage.budget.title'), false)
            ->assertSee(config('homepage.usp.title'), false);
    }

    protected function flushContentCache(): void
    {
        cache()->forget(SiteSetting::CACHE_KEY);
        cache()->forget(HomepageSetting::CACHE_KEY);
    }
}
