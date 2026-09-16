<?php

namespace App\Http\Controllers;

use App\Enums\BioLocationMode;
use App\Services\BioCatalogService;
use App\Services\SiteSettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Halaman Bio: /bio, /bio/lokasi, /bio/produk.
 *
 * Alurnya lurus: Route -> Controller -> Service -> array siap pakai -> Blade.
 * Tidak ada query di Blade dan tidak ada model Eloquent yang sampai ke view.
 *
 * Service di-inject lewat PARAMETER METHOD, bukan constructor, supaya pada
 * worker berumur panjang tidak ada service yang bertahan lintas request dan
 * menyajikan pengaturan basi.
 *
 * Ketiga halaman noindex: halaman Bio adalah pintu masuk dari profil social
 * media, bukan halaman yang perlu bersaing di hasil pencarian dengan halaman
 * utama. Canonical tetap dipasang dan TIDAK PERNAH memuat query string.
 */
class BioController extends Controller
{
    public function home(BioCatalogService $catalog, SiteSettingsService $siteSettings): View|Response
    {
        $settings = $catalog->settings();

        if (! $settings['active']) {
            return $this->unavailable($siteSettings);
        }

        $brand = $siteSettings->brand();

        return view('bio.home', [
            ...$this->shared($settings, $brand, route('bio.home')),
            'buttons' => $catalog->buttons($settings),
            'social' => $settings['social'],
        ]);
    }

    public function locations(Request $request, BioCatalogService $catalog, SiteSettingsService $siteSettings): View|Response
    {
        $settings = $catalog->settings();

        if (! $settings['active']) {
            return $this->unavailable($siteSettings);
        }

        $brand = $siteSettings->brand();
        $payload = $catalog->locations($settings['location_mode']);
        $filtered = $catalog->filterLocations($payload, $request->query('provinsi'), $request->query('kota'));

        $isPages = $payload['mode'] === BioLocationMode::LocationPages->value;

        return view('bio.locations', [
            ...$this->shared($settings, $brand, route('bio.locations'), 'Lokasi Gerobak'),
            'mode' => $payload['mode'],
            'isPages' => $isPages,
            'total' => count($payload['items']),
            'items' => $filtered['items'],
            // Mode gerobak dikelompokkan per wilayah. Mode halaman slug TIDAK:
            // satu halaman bisa mencakup beberapa wilayah dan pengelompokan
            // akan menampilkannya lebih dari sekali.
            'sections' => $isPages ? [] : BioCatalogService::groupByRegion($filtered['items']),
            'provinces' => $payload['provinces'],
            'groupOptions' => $filtered['group_options'],
            'selectedProvince' => $filtered['province_id'],
            'selectedGroup' => $filtered['group_id'],
            'isFiltered' => $filtered['province_id'] !== null || $filtered['group_id'] !== null,
        ]);
    }

    public function products(BioCatalogService $catalog, SiteSettingsService $siteSettings): View|Response
    {
        $settings = $catalog->settings();

        if (! $settings['active']) {
            return $this->unavailable($siteSettings);
        }

        $brand = $siteSettings->brand();

        return view('bio.products', [
            ...$this->shared($settings, $brand, route('bio.products'), 'Menu'),
            'sections' => $catalog->products(),
        ]);
    }

    /**
     * Data yang dipakai ketiga halaman.
     *
     * Judul dan deskripsi jatuh ke nama dan tagline brand bila admin belum
     * mengisinya -- di sini, bukan di cache Bio, supaya perubahan Pengaturan
     * Website tidak terkunci di dalam cache milik modul lain.
     *
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $brand
     * @return array<string, mixed>
     */
    protected function shared(array $settings, array $brand, string $canonical, ?string $pageName = null): array
    {
        $title = $settings['title'] ?? $brand['name'];
        $description = $settings['description'] ?? $brand['tagline'];

        return [
            'brand' => $brand,
            'bioTitle' => $title,
            'bioDescription' => $description,
            'seo' => [
                'title' => $pageName === null ? $title : $pageName.' | '.$title,
                'description' => $description,
                'canonical' => $canonical,
                'locale' => $brand['seo']['locale'],
                'image' => $brand['assets']['og_image'],
            ],
        ];
    }

    /**
     * Bio nonaktif: 404 yang rapi, dan tetap noindex.
     *
     * Ditangani di sini, bukan lewat halaman error global, supaya tampilan
     * 404 bagian lain situs tidak ikut berubah.
     */
    protected function unavailable(SiteSettingsService $siteSettings): Response
    {
        $brand = $siteSettings->brand();

        return response()->view('bio.unavailable', [
            'brand' => $brand,
            'seo' => [
                'title' => 'Halaman tidak tersedia | '.$brand['name'],
                'description' => $brand['tagline'],
                'canonical' => null,
                'locale' => $brand['seo']['locale'],
                'image' => null,
            ],
        ], Response::HTTP_NOT_FOUND);
    }
}
