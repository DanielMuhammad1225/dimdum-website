<?php

namespace App\Http\Controllers;

use App\Services\LocationCatalogService;
use App\Services\LocationStructuredData;
use App\Services\SiteSettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Halaman publik lokasi gerobak DIMDUM.
 *
 * Service di-inject lewat PARAMETER METHOD, bukan constructor: object Route
 * menyimpan instance controller selama route hidup, sehingga service hasil
 * constructor injection bisa bertahan lintas request pada worker berumur
 * panjang dan menyajikan data basi.
 */
class LocationController extends Controller
{
    /**
     * GET /lokasi -- daftar wilayah landing.
     */
    public function index(
        SiteSettingsService $siteSettings,
        LocationCatalogService $catalog,
    ): View {
        $brand = $siteSettings->brand();
        $areas = $catalog->visibleAreas();

        $title = 'Lokasi Gerobak DIMDUM';
        $description = 'Daftar wilayah tempat gerobak DIMDUM tersedia. Pilih wilayah untuk melihat titik lokasinya.';

        return view('locations.index', [
            'brand' => $brand,
            'nav' => config('homepage.nav'),
            'areas' => $areas,
            'seo' => [
                'title' => $title.' | '.$brand['name'],
                'description' => $description,
                'canonical' => route('locations.index'),
                'locale' => $brand['seo']['locale'],
                'image' => $brand['assets']['og_image'],
            ],
            'pageTitle' => $title,
            'pageDescription' => $description,
        ]);
    }

    /**
     * GET /lokasi/{area} -- landing satu wilayah.
     *
     * Slug lama yang pernah terbit dijawab 301 ke slug canonical terbaru,
     * sehingga URL yang sudah beredar di iklan tidak pernah mati.
     */
    public function area(
        string $area,
        SiteSettingsService $siteSettings,
        LocationCatalogService $catalog,
        LocationStructuredData $structuredData,
    ): View|RedirectResponse {
        $result = $catalog->resolveArea($area);

        if (($result['status'] ?? null) === 'redirect') {
            return redirect()->route('locations.area', $result['slug'], Response::HTTP_MOVED_PERMANENTLY);
        }

        abort_if(($result['status'] ?? null) !== 'ok', Response::HTTP_NOT_FOUND);

        $payload = $result['area'];
        $brand = $siteSettings->brand();
        $canonical = route('locations.area', $payload['slug']);

        return view('locations.area', [
            'brand' => $brand,
            'nav' => config('homepage.nav'),
            'area' => $payload,
            'structuredData' => $structuredData->forArea($payload, $brand, $canonical),
            'seo' => [
                'title' => $payload['seo_title'],
                'description' => $payload['seo_description'],
                /*
                 | Canonical SELALU dari route bernama, tanpa query apa pun.
                 | Parameter iklan (utm_*, gclid, fbclid) dan ?filter= boleh
                 | ada di address bar tetapi tidak pernah masuk canonical dan
                 | tidak pernah mengubah konten maupun cache key.
                 */
                'canonical' => $canonical,
                'locale' => $brand['seo']['locale'],
                'image' => $brand['assets']['og_image'],
            ],
        ]);
    }
}
