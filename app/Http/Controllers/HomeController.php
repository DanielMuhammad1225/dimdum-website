<?php

namespace App\Http\Controllers;

use App\Services\HomepageContentService;
use App\Services\SiteSettingsService;
use Illuminate\Contracts\View\View;

class HomeController extends Controller
{
    /**
     * Tampilkan homepage publik DIMDUM.
     *
     * Seluruh konten diambil lewat service, bukan query model langsung dan
     * bukan query dari dalam Blade. Service yang mengurus cache, penggabungan
     * fallback config, serta validasi allowlist section.
     *
     * Service sengaja di-inject lewat PARAMETER METHOD, bukan constructor.
     * Illuminate\Routing\Route menyimpan (cache) instance controller selama
     * object route hidup; pada worker yang berumur panjang, service hasil
     * constructor injection akan ikut bertahan lintas request dan menyajikan
     * konten basi setelah admin menyimpan perubahan. Dependency method
     * di-resolve ulang setiap kali route di-dispatch, sehingga memo di dalam
     * service selalu berlaku untuk satu request saja.
     */
    public function __invoke(
        SiteSettingsService $siteSettings,
        HomepageContentService $homepageContent,
    ): View {
        $brand = $siteSettings->brand();
        $homepage = $homepageContent->homepage();

        return view('home', [
            'brand' => $brand,
            'homepage' => $homepage,
            'nav' => $homepage['nav'],
            'sections' => $this->resolveSections($homepage),
            'seo' => $siteSettings->seo(),
        ]);
    }

    /**
     * Petakan urutan section ke nama view lewat allowlist.
     *
     * View di-resolve HANYA dari config('homepage.section_views'). Key yang
     * tidak terdaftar dibuang, sehingga sumber urutan section (admin panel /
     * database) tidak pernah bisa memuat template sembarang.
     *
     * Ini lapis kedua: service sudah membuang key asing lebih dulu. Keduanya
     * dipertahankan supaya controller tetap aman meski dipanggil dengan array
     * homepage dari sumber lain.
     *
     * @param  array<string, mixed>  $homepage
     * @return list<string>
     */
    protected function resolveSections(array $homepage): array
    {
        $allowed = $homepage['section_views'] ?? [];

        $views = [];

        foreach ($homepage['sections'] ?? [] as $key) {
            if (! is_string($key) || ! isset($allowed[$key])) {
                continue;
            }

            $views[] = $allowed[$key];
        }

        return $views;
    }
}
