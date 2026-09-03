<?php

namespace App\Http\Controllers;

use App\Services\ProductCatalogService;
use App\Services\SiteSettingsService;
use Illuminate\Contracts\View\View;

/**
 * Halaman publik /produk.
 *
 * Alurnya lurus: Route -> Controller -> Service -> array siap pakai -> Blade.
 * Tidak ada query di Blade dan tidak ada model Eloquent yang sampai ke view.
 *
 * Service di-inject lewat PARAMETER METHOD, bukan constructor. Route menyimpan
 * instance controller selama object route hidup; pada worker berumur panjang,
 * service hasil constructor injection akan bertahan lintas request dan
 * menyajikan katalog basi setelah admin menyimpan perubahan.
 */
class ProductController extends Controller
{
    public function __invoke(
        ProductCatalogService $catalog,
        SiteSettingsService $siteSettings,
    ): View {
        $brand = $siteSettings->brand();
        $sections = $catalog->catalog();

        // Canonical dibangun dari named route, bukan dari URL request, supaya
        // parameter iklan tidak pernah ikut.
        $canonical = route('products.index');

        return view('products.index', [
            'brand' => $brand,
            'nav' => config('homepage.nav'),
            'sections' => $sections,
            'canonical' => $canonical,
            'seo' => [
                'title' => 'Daftar Produk | '.$brand['name'],
                'description' => 'Semua pilihan dimsum, varian, dan frozen food '.$brand['name']
                    .' beserta harganya.',
                'canonical' => $canonical,
                'locale' => $brand['seo']['locale'],
                'image' => $brand['assets']['og_image'],
            ],
        ]);
    }
}
