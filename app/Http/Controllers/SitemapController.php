<?php

namespace App\Http\Controllers;

use App\Services\LocationCatalogService;
use Illuminate\Http\Response;

/**
 * Sitemap XML minimal.
 *
 * Hanya memuat URL yang benar-benar publik: homepage, indeks lokasi, dan
 * wilayah yang aktif + sudah terbit + punya gerobak yang tampil. Wilayah
 * draft, nonaktif, terjadwal, atau terhapus tidak pernah masuk.
 */
class SitemapController extends Controller
{
    public function __invoke(LocationCatalogService $catalog): Response
    {
        $urls = [
            ['loc' => route('home'), 'priority' => '1.0'],
            ['loc' => route('locations.index'), 'priority' => '0.8'],
        ];

        foreach ($catalog->visibleAreas() as $area) {
            $urls[] = ['loc' => $area['url'], 'priority' => '0.7'];
        }

        $xml = view('sitemap', ['urls' => $urls])->render();

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
