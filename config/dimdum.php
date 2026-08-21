<?php

/*
|--------------------------------------------------------------------------
| Konfigurasi Brand DIMDUM
|--------------------------------------------------------------------------
| Sumber data brand terpusat untuk fase sebelum admin panel tersedia.
| Struktur ini nantinya dapat digantikan oleh database tanpa mengubah
| kontrak data yang diterima oleh view.
*/

return [

    'name' => 'DIMDUM',

    'tagline' => 'Dimsum mulai Rp1.000, bikin ketagihan.',

    'positioning' => 'Fun & Affordable Dimsum Street Food',

    'core_value' => 'Freedom of Choice',

    /*
    | Palet resmi brand. Nilai ini menjadi acuan tunggal; implementasi
    | visualnya berupa design tokens di resources/css/app.css.
    */
    'colors' => [
        'orange' => '#f47a32',
        'cream' => '#fff1d6',
        'brown' => '#38251e',
        'yellow' => '#ffc84a',
        'cream_soft' => '#fff9ed',
    ],

    /*
    | Aset brand (path relatif terhadap folder public/).
    |
    | Seluruh lockup di bawah berasal dari lembar variasi logo resmi DIMDUM
    | dan TIDAK memuat tagline di dalam gambar -- tagline final selalu
    | dirender sebagai teks HTML terpisah.
    |
    | Turunan web dihasilkan oleh tools/build-brand-assets.php dari master di
    | storage/app/private/brand-masters/. Set ke null untuk kembali memakai
    | text fallback "DIMDUM".
    */
    'assets' => [
        'logo' => [
            'src' => 'images/brand/dimdum-logo-primary.png',
            'webp' => 'images/brand/dimdum-logo-primary.webp',
            'width' => 720,
            'height' => 442,
        ],
        'logo_horizontal' => [
            'src' => 'images/brand/dimdum-logo-horizontal.png',
            'webp' => 'images/brand/dimdum-logo-horizontal.webp',
            'width' => 521,
            'height' => 130,
        ],
        'icon' => [
            'src' => 'images/brand/dimdum-icon.png',
            'webp' => 'images/brand/dimdum-icon.webp',
            'width' => 258,
            'height' => 258,
        ],
        'favicon' => [
            'ico' => 'favicon.ico',
            'png_32' => 'images/brand/dimdum-icon-32.png',
            'apple_touch' => 'images/brand/dimdum-icon-180.png',
        ],
        'og_image' => [
            'src' => 'images/brand/dimdum-og.jpg',
            'width' => 1200,
            'height' => 630,
            'type' => 'image/jpeg',
        ],
    ],

    /*
    | Kontak resmi. Biarkan null selama nomor/akun resmi belum tersedia agar
    | tidak ada link palsu yang tampil di halaman publik.
    */
    'contact' => [
        'whatsapp' => null,
        'whatsapp_label' => null,
    ],

    /*
    | Social media. Tambahkan entri hanya bila URL resmi sudah tersedia:
    | ['label' => 'Instagram', 'url' => 'https://...']
    */
    'social' => [],

    /*
    | Status ketersediaan data lokasi gerobak.
    */
    'locations' => [
        'available' => false,
        'items' => [],
    ],

    /*
    | Pengaturan admin panel.
    |
    | 'locale' hanya berlaku untuk request panel (lihat
    | App\Http\Middleware\SetAdminPanelLocale). Locale aplikasi dan halaman
    | publik tidak ikut berubah. Set null untuk mengikuti APP_LOCALE.
    */
    'admin' => [
        'locale' => 'id',
    ],

    'seo' => [
        'title' => 'DIMDUM | Dimsum Mulai Rp1.000, Bikin Ketagihan',
        'description' => 'DIMDUM adalah street food dimsum satuan mulai Rp1.000. Bebas pilih varian, bebas menentukan jumlah, dan jajan sesuai budget kamu.',
        'locale' => 'id_ID',
    ],

];
