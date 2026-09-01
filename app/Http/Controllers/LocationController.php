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
     * GET /lokasi -- daftar provinsi yang punya gerobak tampil.
     */
    public function index(
        SiteSettingsService $siteSettings,
        LocationCatalogService $catalog,
        LocationStructuredData $structuredData,
    ): View {
        $brand = $siteSettings->brand();
        $provinces = $catalog->visibleProvinces();

        $title = 'Lokasi Gerobak DIMDUM';
        $description = 'Daftar provinsi tempat gerobak DIMDUM tersedia. Pilih provinsi untuk melihat area dan titik lokasinya.';
        $canonical = route('locations.index');

        return view('locations.index', [
            'brand' => $brand,
            'nav' => config('homepage.nav'),
            'provinces' => $provinces,
            'structuredData' => $structuredData->forIndex($provinces, $title, $description, $canonical),
            'seo' => [
                'title' => $title.' | '.$brand['name'],
                'description' => $description,
                'canonical' => $canonical,
                'locale' => $brand['seo']['locale'],
                'image' => $brand['assets']['og_image'],
            ],
            'pageTitle' => $title,
            'pageDescription' => $description,
        ]);
    }

    /**
     * GET /lokasi/{province} -- Kota/Grup sebagai heading, Area sebagai kartu.
     */
    public function province(
        string $province,
        SiteSettingsService $siteSettings,
        LocationCatalogService $catalog,
        LocationStructuredData $structuredData,
    ): View|RedirectResponse {
        $result = $catalog->resolveProvince($province);

        if (($result['status'] ?? null) === 'redirect') {
            return redirect()->route(
                'locations.province',
                $result['slug'],
                Response::HTTP_MOVED_PERMANENTLY,
            );
        }

        abort_if(($result['status'] ?? null) !== 'ok', Response::HTTP_NOT_FOUND);

        $payload = $result['province'];
        $brand = $siteSettings->brand();
        $canonical = route('locations.province', $payload['slug']);

        return view('locations.province', [
            'brand' => $brand,
            'nav' => config('homepage.nav'),
            'province' => $payload,
            'structuredData' => $structuredData->forProvince($payload, $canonical),
            'seo' => [
                'title' => $payload['seo_title'],
                'description' => $payload['seo_description'],
                'canonical' => $canonical,
                'locale' => $brand['seo']['locale'],
                'image' => $brand['assets']['og_image'],
            ],
        ]);
    }

    /**
     * GET /lokasi/{province}/{area} -- landing satu area.
     *
     * Segmen provinsi bukan hiasan: kombinasi yang tidak sah dijawab 404,
     * sedangkan segmen yang sekadar USANG dijawab 301 ke canonical terbaru.
     * Keduanya ditentukan di service, bukan di sini.
     */
    public function area(
        string $province,
        string $area,
        SiteSettingsService $siteSettings,
        LocationCatalogService $catalog,
        LocationStructuredData $structuredData,
    ): View|RedirectResponse {
        $result = $catalog->resolveArea($province, $area);

        if (($result['status'] ?? null) === 'redirect') {
            return redirect()->route(
                'locations.area',
                [$result['province'], $result['area']],
                Response::HTTP_MOVED_PERMANENTLY,
            );
        }

        abort_if(($result['status'] ?? null) !== 'ok', Response::HTTP_NOT_FOUND);

        $payload = $result['area'];
        $brand = $siteSettings->brand();
        $canonical = route('locations.area', [$payload['province_slug'], $payload['slug']]);

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
                 | Parameter iklan (utm_*, gclid, fbclid) boleh ada di address
                 | bar tetapi tidak pernah masuk canonical dan tidak pernah
                 | mengubah konten maupun cache key.
                 */
                'canonical' => $canonical,
                'locale' => $brand['seo']['locale'],
                'image' => $brand['assets']['og_image'],
            ],
        ]);
    }
}
