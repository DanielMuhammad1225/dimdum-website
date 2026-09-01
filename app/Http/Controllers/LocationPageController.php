<?php

namespace App\Http\Controllers;

use App\Services\LocationPageCatalogService;
use App\Services\LocationStructuredData;
use App\Services\SiteSettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Halaman publik /alamat/{slug}.
 *
 * Alurnya lurus: Route -> Controller -> Service -> array siap pakai -> Blade.
 * Tidak ada query di Blade dan tidak ada model Eloquent yang sampai ke view.
 *
 * Service di-inject lewat PARAMETER METHOD, bukan constructor. Route menyimpan
 * instance controller selama object route hidup; pada worker berumur panjang,
 * service hasil constructor injection akan bertahan lintas request dan
 * menyajikan konten basi setelah admin menyimpan perubahan.
 */
class LocationPageController extends Controller
{
    public function show(
        string $slug,
        LocationPageCatalogService $catalog,
        SiteSettingsService $siteSettings,
        LocationStructuredData $structuredData,
    ): View|RedirectResponse {
        $resolution = $catalog->resolve($slug);

        /*
         | Slug lama -> 301 ke canonical terbaru, SATU lompatan. Redirect
         | hanya diberikan bila halaman tujuannya benar-benar tampil; URL lama
         | yang mengarah ke draft tetap 404, bukan dialihkan ke halaman mati.
         */
        if ($resolution['status'] === 'redirect') {
            return redirect()->route('location-pages.show', $resolution['slug'], 301);
        }

        if ($resolution['status'] !== 'ok') {
            throw new NotFoundHttpException;
        }

        $payload = $catalog->pagePayload($slug);

        if ($payload === null) {
            throw new NotFoundHttpException;
        }

        $brand = $siteSettings->brand();

        /*
         | Canonical dibangun dari slug canonical, BUKAN dari URL request.
         | Parameter iklan (utm_*, gclid, fbclid) boleh tetap ada di address
         | bar, tetapi tidak pernah ikut ke canonical maupun ke key cache.
         */
        $canonical = route('location-pages.show', $payload['slug']);

        return view('location-pages.show', [
            'brand' => $brand,
            'nav' => config('homepage.nav'),
            'page' => $payload,
            'canonical' => $canonical,
            'structuredData' => $structuredData->forPage($payload, $brand, $canonical),
            'seo' => [
                'title' => $payload['seo_title'].' | '.$brand['name'],
                'description' => $payload['seo_description'],
                'canonical' => $canonical,
                'locale' => $brand['seo']['locale'],
                /*
                 | Poster halaman lebih relevan daripada OG image default,
                 | tetapi hanya dipakai bila metadatanya LENGKAP: og:image
                 | tanpa dimensi atau tipe membuat pratinjau tautan pecah.
                 */
                'image' => self::openGraphImage($payload['poster']) ?? $brand['assets']['og_image'],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $poster
     * @return array<string, mixed>|null
     */
    protected static function openGraphImage(?array $poster): ?array
    {
        if ($poster === null) {
            return null;
        }

        foreach (['src', 'width', 'height', 'type'] as $key) {
            if (blank($poster[$key] ?? null)) {
                return null;
            }
        }

        return [
            'src' => $poster['src'],
            'width' => $poster['width'],
            'height' => $poster['height'],
            'type' => $poster['type'],
        ];
    }
}
