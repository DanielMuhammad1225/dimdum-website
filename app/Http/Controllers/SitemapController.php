<?php

namespace App\Http\Controllers;

use App\Services\LocationCatalogService;
use Illuminate\Http\Response;

/**
 * Sitemap XML minimal.
 *
 * Hanya memuat URL yang benar-benar publik: homepage, indeks lokasi, provinsi
 * yang tampil, dan Area yang tampil di bawah Kota/Grup aktif. Draft, nonaktif,
 * terjadwal, dan terhapus tidak pernah masuk -- seluruhnya sudah tersaring di
 * payload yang sama dengan yang dipakai halaman publik, jadi sitemap tidak
 * mungkin menjanjikan URL yang berakhir 404.
 *
 * Kota/Grup tidak punya URL sendiri sehingga tidak pernah muncul di sini.
 */
class SitemapController extends Controller
{
    public function __invoke(LocationCatalogService $catalog): Response
    {
        $urls = [
            ['loc' => route('home'), 'priority' => '1.0'],
            ['loc' => route('locations.index'), 'priority' => '0.8'],
            ...$catalog->sitemapUrls(),
        ];

        $xml = view('sitemap', ['urls' => $urls])->render();

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
