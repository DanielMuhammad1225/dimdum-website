<?php

namespace Tests\Feature\Locations;

use App\Models\Location;
use App\Models\LocationArea;
use App\Models\LocationGroup;
use App\Models\LocationPage;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Bio\Concerns\BuildsBio;
use Tests\TestCase;

/**
 * Navigasi halaman slug lokasi /alamat/{slug}.
 *
 * Halaman ini dibuka dari Bio, jadi navigasinya SATU tombol Kembali ke /bio:
 * tanpa navbar situs, tanpa remah roti, tanpa tautan Beranda. Halaman publik
 * lain tidak boleh ikut berubah.
 */
class LocationPageNavigationTest extends TestCase
{
    use BuildsBio;
    use RefreshDatabase;

    protected function page(array $attributes = []): LocationPage
    {
        $group = LocationGroup::factory()->create();
        $area = LocationArea::factory()->for($group, 'group')->create();
        $location = Location::factory()->for($area, 'area')->create(['name' => 'Gerobak Navigasi']);

        $page = LocationPage::factory()->create([
            'slug' => 'alamat-navigasi',
            'title' => 'Alamat Navigasi',
            ...$attributes,
        ]);

        $page->groups()->attach($group);
        $page->locations()->attach($location);

        return $page;
    }

    /**
     * href dari setiap <a> di halaman.
     *
     * @return list<string>
     */
    protected static function anchorHrefs(string $html): array
    {
        preg_match_all('/<a\b[^>]*\bhref="([^"]*)"/i', $html, $matches);

        return array_map(static fn (string $href): string => html_entity_decode($href), $matches[1]);
    }

    /**
     * Tautan yang berpindah halaman di dalam situs ini: bukan anchor di
     * halaman yang sama dan bukan situs luar (WhatsApp, Maps, CTA).
     *
     * @return list<string>
     */
    protected static function internalLinks(string $html): array
    {
        $root = rtrim(url('/'), '/');

        return array_values(array_filter(
            self::anchorHrefs($html),
            static fn (string $href): bool => ! str_starts_with($href, '#')
                && ($href === $root || str_starts_with($href, $root.'/') || str_starts_with($href, '/')),
        ));
    }

    // ------------------------------------------------------------------ tests

    public function test_the_location_page_has_no_navigation_bar_or_home_link(): void
    {
        $this->page();

        $html = $this->get('/alamat/alamat-navigasi')->assertOk()->getContent();

        // Tidak ada breadcrumb Beranda di HTML maupun di JSON-LD.
        $this->assertStringNotContainsString('<nav', $html, 'Halaman slug masih memuat elemen navigasi.');
        $this->assertStringNotContainsString('id="menu-toggle"', $html);
        $this->assertStringNotContainsString('Beranda', $html);
        $this->assertStringNotContainsString('BreadcrumbList', $html);

        $hrefs = self::anchorHrefs($html);
        $this->assertNotContains(route('home'), $hrefs, 'Masih ada tautan ke Beranda.');
        $this->assertNotContains('/', $hrefs);
        $this->assertNotContains(url('/').'/', $hrefs);

        // Anchor menu homepage (#pilihan-dimsum, dll.) tidak punya target di sini.
        foreach (config('homepage.nav') as $item) {
            $this->assertNotContains($item['href'], $hrefs, "Tautan menu situs {$item['href']} masih tampil.");
        }
    }

    public function test_the_only_way_back_is_one_link_to_bio(): void
    {
        $this->page();

        $html = $this->get('/alamat/alamat-navigasi')->assertOk()->getContent();

        $this->assertSame(url('/bio'), route('bio.home'));
        $this->assertSame([route('bio.home')], self::internalLinks($html), 'Tautan internal selain Kembali ke /bio ditemukan.');

        $this->assertSame(1, preg_match_all('/<a\b[^>]*href="'.preg_quote(route('bio.home'), '/').'"[^>]*>(.*?)<\/a>/s', $html, $links));

        $tag = $links[0][0];
        $label = trim(preg_replace('/\s+/', ' ', strip_tags($links[1][0])));

        // Label terlihat "Kembali", nama aksesibel lengkap lewat teks sr-only.
        $this->assertSame('Kembali ke halaman Bio DIMDUM', $label);
        $this->assertStringContainsString('<span class="sr-only"> ke halaman Bio', $tag);

        // Target sentuh minimal 44x44 px (min-h-11 dan min-w-11 = 2.75rem).
        $this->assertStringContainsString('min-h-11', $tag);
        $this->assertStringContainsString('min-w-11', $tag);

        // Bekerja tanpa JavaScript dan tidak bergantung pada riwayat browser.
        $this->assertStringNotContainsString('onclick', $tag);
        $this->assertStringNotContainsString('history.back', $html);
    }

    public function test_the_page_keeps_its_ctas_menu_dialog_and_metadata(): void
    {
        $page = $this->page([
            'show_products' => true,
            'button_text' => 'Pesan Sekarang',
            'button_url' => 'https://wa.me/6281234567890',
        ]);
        $page->products()->attach(Product::factory()->create(['name' => 'Dimsum Navigasi']));

        $html = $this->get('/alamat/alamat-navigasi')->assertOk()->getContent();

        // CTA lokasi, tombol menu, dan dialognya.
        $this->assertStringContainsString('href="#daftar-lokasi"', $html);
        $this->assertStringContainsString('data-menu-modal-open', $html);
        $this->assertStringContainsString('<dialog', $html);
        $this->assertStringContainsString('id="menu-produk"', $html);
        $this->assertStringContainsString('data-menu-modal-close', $html);
        $this->assertStringContainsString('Dimsum Navigasi', $html);
        $this->assertStringContainsString('href="https://wa.me/6281234567890"', $html);
        $this->assertStringContainsString('Pesan Sekarang', $html);
        $this->assertStringContainsString('Gerobak Navigasi', $html);

        // Metadata dan canonical.
        $canonical = route('location-pages.show', 'alamat-navigasi');
        $this->assertStringContainsString('<link rel="canonical" href="'.$canonical.'">', $html);
        $this->assertStringContainsString('<meta property="og:url" content="'.$canonical.'">', $html);
        $this->assertStringNotContainsString('noindex', $html);

        // Structured data: JSON valid, ItemList berisi gerobak tetap ada,
        // BreadcrumbList tidak ada lagi.
        $this->assertSame(1, preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $scripts));
        $data = json_decode($scripts[1][0], true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('https://schema.org', $data['@context']);
        $this->assertSame(['ItemList'], array_column($data['@graph'], '@type'));

        $list = $data['@graph'][0];
        $this->assertSame($canonical, $list['url']);
        $this->assertSame(1, $list['numberOfItems']);
        $this->assertSame('FoodEstablishment', $list['itemListElement'][0]['item']['@type']);
        $this->assertStringContainsString('Gerobak Navigasi', $list['itemListElement'][0]['item']['name']);

        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_the_empty_state_page_still_answers_with_only_the_back_link(): void
    {
        LocationPage::factory()->create(['slug' => 'alamat-kosong', 'title' => 'Alamat Kosong']);

        $html = $this->get('/alamat/alamat-kosong')->assertOk()->getContent();

        $this->assertStringContainsString('Titik gerobak sedang disiapkan', $html);
        $this->assertSame([route('bio.home')], self::internalLinks($html));
    }

    public function test_the_homepage_and_catalog_keep_the_site_navigation(): void
    {
        foreach (['/', '/produk'] as $uri) {
            $html = $this->get($uri)->assertOk()->getContent();

            $this->assertStringContainsString('aria-label="Navigasi utama"', $html, "{$uri} kehilangan navbar.");
            $this->assertStringContainsString('id="menu-toggle"', $html, "{$uri} kehilangan menu mobile.");
            $this->assertStringContainsString('aria-label="Navigasi footer"', $html, "{$uri} kehilangan navigasi footer.");
            // Logo di navbar tetap menaut ke Beranda.
            $this->assertContains(route('home'), self::anchorHrefs($html), "{$uri} kehilangan tautan logo ke Beranda.");

            foreach (config('homepage.nav') as $item) {
                $this->assertContains($item['href'], self::anchorHrefs($html), "{$uri} kehilangan tautan {$item['label']}.");
            }
        }
    }

    public function test_the_bio_pages_keep_their_own_navigation(): void
    {
        $this->bio();
        $this->chain();
        Product::factory()->onBio()->create();

        // /bio tidak punya bilah kembali; turunannya punya, menuju /bio.
        $home = $this->get('/bio')->assertOk()->getContent();
        $this->assertNotContains(route('bio.home'), self::anchorHrefs($home));
        $this->assertContains(route('home'), self::anchorHrefs($home));

        foreach (['/bio/lokasi', '/bio/produk'] as $uri) {
            $hrefs = self::anchorHrefs($this->get($uri)->assertOk()->getContent());

            $this->assertContains(route('bio.home'), $hrefs, "{$uri} kehilangan tautan kembali ke /bio.");
            $this->assertContains(route('home'), $hrefs, "{$uri} kehilangan tautan ke website.");
        }
    }
}
