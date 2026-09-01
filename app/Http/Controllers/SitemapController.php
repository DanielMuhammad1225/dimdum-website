<?php

namespace App\Http\Controllers;

use App\Services\LocationPageCatalogService;
use Illuminate\Http\Response;

/**
 * Sitemap XML minimal.
 *
 * Hanya memuat URL yang benar-benar publik: homepage dan Halaman Slug Lokasi
 * yang layak tampil. Master hierarki -- Provinsi, Kota/Grup, Area, Gerobak --
 * TIDAK pernah muncul di sini: sejak slug dipisahkan, tak satu pun dari
 * keempatnya punya URL sendiri.
 *
 * Halaman draft, nonaktif, di luar periode, atau yang kehilangan seluruh
 * gerobaknya juga tidak masuk, sehingga sitemap tidak mungkin menjanjikan URL
 * yang berakhir 404 atau halaman kosong.
 */
class SitemapController extends Controller
{
    public function __invoke(LocationPageCatalogService $catalog): Response
    {
        $urls = [
            ['loc' => route('home'), 'priority' => '1.0'],
            ...$catalog->sitemapUrls(),
        ];

        $xml = view('sitemap', ['urls' => $urls])->render();

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
