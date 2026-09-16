<?php

/*
|--------------------------------------------------------------------------
| Konten Homepage DIMDUM
|--------------------------------------------------------------------------
| Seluruh copy homepage disimpan terpusat di sini sehingga Blade hanya
| bertugas menampilkan data. Urutan section dikendalikan lewat key
| 'sections' dan setiap nilainya memetakan ke resources/views/sections/*.
*/

return [

    /*
    | Allowlist section: satu-satunya pemetaan key -> view yang diizinkan.
    | Nama view TIDAK PERNAH dirangkai dari input. Ketika admin panel dibuat,
    | database hanya boleh menentukan URUTAN dan AKTIF/TIDAK dari key di sini,
    | bukan nama file Blade.
    */
    'section_views' => [
        'hero' => 'sections.hero',
        'usp' => 'sections.usp',
        'products' => 'sections.products',
        'how' => 'sections.how',
        'budget' => 'sections.budget',
        'locations' => 'sections.locations',
        'cta' => 'sections.cta',
    ],

    // Urutan tampil. Key yang tidak terdaftar di 'section_views' diabaikan.
    'sections' => ['hero', 'usp', 'products', 'how', 'budget', 'locations', 'cta'],

    'nav' => [
        ['label' => 'Beranda', 'href' => '/'],
        ['label' => 'Pilihan Dimsum', 'href' => '#pilihan-dimsum'],
        ['label' => 'Cara Jajan', 'href' => '#cara-jajan'],
        ['label' => 'Lokasi', 'href' => '#lokasi'],
    ],

    'hero' => [
        'eyebrow' => 'Street Food Dimsum Satuan',
        'headline' => 'Bebas Pilih, Jajan Sesukamu.',
        'description' => 'Pilih varian favoritmu, tentukan sendiri jumlahnya, lalu jajan sesuai budget kamu.',
        'primary_cta' => ['label' => 'Lihat Pilihan Dimsum', 'href' => '#pilihan-dimsum'],
        'secondary_cta' => ['label' => 'Cari Gerobak Terdekat', 'href' => '#lokasi'],
        'highlights' => [
            'Dijual satuan',
            'Bebas pilih varian',
            'Bebas tentukan jumlah',
        ],
        /*
        | Foto hero. Isi dengan array ['src' => ..., 'alt' => ..., 'width' => ..., 'height' => ...]
        | ketika foto resmi tersedia. Selama null, tampil placeholder rapi.
        */
        'image' => null,
    ],

    'usp' => [
        'title' => 'Jajan Jadi Lebih Bebas',
        'items' => [
            [
                'title' => 'Mulai Rp1.000',
                'description' => 'Harga masuk yang ramah untuk jajan harian.',
            ],
            [
                'title' => 'Banyak Pilihan',
                'description' => 'Pilih berbagai varian dimsum favoritmu dalam satu tempat.',
            ],
            [
                'title' => 'Bebas Tentukan Jumlah',
                'description' => 'Mau sedikit atau banyak, kamu yang menentukan.',
            ],
        ],
    ],

    'products' => [
        'id' => 'pilihan-dimsum',
        'title' => 'Pilih yang Kamu Suka',
        'description' => 'Mau satu varian atau dicampur? Tinggal pilih sesuai selera dan budget kamu.',
        /*
        | 'items' dan 'has_more' SENGAJA tidak ada di config: keduanya berasal
        | dari tabel products lewat ProductCatalogService dan ditimpa oleh
        | HomeController. Daftar statis sebelumnya dibuang supaya tidak ada dua
        | sumber kebenaran untuk daftar yang sama.
        |
        | Judul, deskripsi, catatan, dan empty state di bawah tetap milik
        | Homepage CMS.
        */
        'note' => 'Ketersediaan varian bisa berbeda di setiap gerobak.',
        /*
        | Ditampilkan hanya bila belum ada satu pun produk yang dipilih untuk
        | homepage.
        */
        'empty_state' => [
            'title' => 'Daftar varian segera hadir',
            'description' => 'Pilihan dimsum DIMDUM sedang kami siapkan dan akan tampil di halaman ini.',
        ],
    ],

    'how' => [
        'id' => 'cara-jajan',
        'title' => 'Kamu yang Tentukan Cara Jajannya',
        'steps' => [
            [
                'title' => 'Pilih Varian',
                'description' => 'Lihat pilihan dimsum yang tersedia dan pilih favoritmu.',
            ],
            [
                'title' => 'Tentukan Jumlah',
                'description' => 'Mau satu, dua, atau mix beberapa varian? Bebas.',
            ],
            [
                'title' => 'Jajan Sesuai Budget',
                'description' => 'Bayar sesuai produk dan jumlah yang kamu pilih.',
            ],
        ],
        'closing' => 'Tidak harus membeli satu paket. Kamu bebas menentukan pilihanmu sendiri.',
    ],

    'budget' => [
        'title' => 'Budget Berapa Pun, Tetap Bisa Jajan',
        'copy' => 'Jajan Rp3.000? Bisa. Rp5.000? Bisa. Mau mix lebih banyak? Tinggal pilih.',
        'support_copy' => 'Di DIMDUM, jumlah pembelian ditentukan oleh kamu sendiri.',
        /*
        | Nominal di bawah hanya ilustrasi fleksibilitas budget.
        | Bukan paket, bukan minimum transaksi.
        */
        'examples' => ['Rp3.000', 'Rp5.000', 'Sesukamu'],
        'disclaimer' => 'Nominal di atas hanya contoh. Tidak ada paket dan tidak ada minimum pembelian.',
    ],

    'locations' => [
        'id' => 'lokasi',
        'title' => 'Cari DIMDUM di Dekat Kamu',
        'description' => 'Gerobak DIMDUM hadir dekat aktivitas harianmu. Temukan lokasi yang paling mudah kamu kunjungi.',
        'coming_soon_title' => 'Informasi lokasi segera hadir',
        'coming_soon_description' => 'Daftar titik gerobak DIMDUM sedang kami siapkan dan akan tampil di halaman ini.',
    ],

    'cta' => [
        'headline' => 'Udah Siap Pilih Dimsum Sesukamu?',
        'description' => 'Temukan gerobak DIMDUM dan racik pilihanmu sendiri.',
        /*
        | CTA hanya diarahkan ke anchor yang benar-benar ada di halaman ini.
        | Tidak ada tautan ke halaman yang belum dibuat.
        */
        'primary_cta' => ['label' => 'Lihat Pilihan Dimsum', 'href' => '#pilihan-dimsum'],
        'secondary_cta' => ['label' => 'Pelajari Cara Jajan', 'href' => '#cara-jajan'],
    ],

];
