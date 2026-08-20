<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class HomeController extends Controller
{
    /**
     * Tampilkan homepage publik DIMDUM.
     *
     * Fase ini belum menyentuh database: seluruh konten diambil dari
     * konfigurasi terpusat (config/dimdum.php dan config/homepage.php).
     */
    public function __invoke(): View
    {
        $brand = config('dimdum');
        $homepage = config('homepage');

        return view('home', [
            'brand' => $brand,
            'homepage' => $homepage,
            'nav' => $homepage['nav'],
            'sections' => $this->resolveSections($homepage),
            'seo' => [
                'title' => $brand['seo']['title'],
                'description' => $brand['seo']['description'],
                'canonical' => route('home'),
                'locale' => $brand['seo']['locale'],
                'image' => $brand['assets']['og_image'],
            ],
        ]);
    }

    /**
     * Petakan urutan section ke nama view lewat allowlist.
     *
     * View di-resolve HANYA dari config('homepage.section_views'). Key yang
     * tidak terdaftar dibuang, sehingga sumber urutan section (nanti: admin
     * panel/database) tidak pernah bisa memuat template sembarang.
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
