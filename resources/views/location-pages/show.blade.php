@extends('layouts.public')

@push('head')
    {{-- ItemList + FoodEstablishment, hanya dari data yang benar-benar ada.
         @json memakai flag HEX_* sehingga isi data tidak mungkin menutup
         tag script ini. --}}
    <script type="application/ld+json">@json($structuredData, \App\Services\LocationStructuredData::JSON_FLAGS)</script>
@endpush

{{-- Halaman ini dibuka dari Bio, jadi navigasinya SATU tombol Kembali ke
     /bio: tanpa navbar situs, tanpa remah roti, tanpa tautan ke Beranda.
     <a> biasa, bukan history.back(): berfungsi tanpa JavaScript dan tetap
     menuju /bio walau halaman dibuka langsung dari tautan yang dibagikan.
     Tingginya sama dengan navbar situs, sehingga offset anchor #daftar-lokasi
     tidak berubah. --}}
@section('header')
    <header class="sticky top-0 z-40 border-b-2 border-brand-brown/10 bg-brand-cream-soft/95 backdrop-blur supports-[backdrop-filter]:bg-brand-cream-soft/80">
        <div class="mx-auto flex h-header max-w-6xl items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
            <a href="{{ route('bio.home') }}"
               class="inline-flex min-h-11 min-w-11 items-center gap-2 rounded-pill border-2 border-brand-brown/15 bg-white px-4 text-sm font-semibold text-brand-brown shadow-sticker transition-colors hover:border-brand-brown/30">
                <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="m15 18-6-6 6-6" />
                </svg>
                Kembali<span class="sr-only"> ke halaman Bio {{ $brand['name'] }}</span>
            </a>

            <x-brand-mark :brand="$brand" :linked="false" loading="eager" img-class="h-8 w-auto object-contain sm:h-9" />
        </div>
    </header>
@endsection

@section('content')
    <section class="bg-brand-cream">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6 sm:py-20 lg:px-8">
            <div class="grid gap-10 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)] lg:items-start">
                <div>
                    @if ($page['period_text'])
                        <p class="inline-flex items-center rounded-pill border-2 border-brand-brown/10 bg-white px-4 py-1.5 text-xs font-semibold uppercase tracking-[0.16em] text-brand-brown/85">
                            {{ $page['period_text'] }}
                        </p>
                    @endif

                    {{-- Satu-satunya H1 di halaman ini. --}}
                    <h1 class="mt-5 max-w-3xl font-display text-4xl font-bold leading-[1.1] text-brand-brown sm:text-5xl">
                        {{ $page['title'] }}
                    </h1>

                    @if ($page['short_description'])
                        <p class="mt-4 max-w-2xl text-base leading-relaxed text-brand-brown/75 sm:text-lg">
                            {{ $page['short_description'] }}
                        </p>
                    @endif

                    <div class="mt-8 flex flex-wrap gap-3">
                        @if (! empty($page['locations']))
                            <a href="#daftar-lokasi"
                               class="inline-flex min-h-11 items-center rounded-pill bg-brand-orange px-6 py-3.5 text-sm font-semibold text-brand-brown shadow-sticker-lg transition-transform duration-150 ease-out hover:-translate-y-0.5 sm:text-base">
                                Lihat Lokasi
                            </a>
                        @endif

                        {{-- Menu produk dibuka sebagai dialog, bukan section
                             permanen: daftar produk sama di setiap halaman
                             lokasi, jadi menaruhnya di alur baca hanya
                             mendorong titik gerobak makin ke bawah.

                             Tombolnya HANYA ada bila service benar-benar
                             mengembalikan produk yang layak tampil, sehingga
                             tidak pernah ada tombol yang membuka dialog
                             kosong. Tanpa JavaScript pun tombol ini tidak
                             menyesatkan: ia menaut ke id dialognya. --}}
                        @if (! empty($page['products']))
                            <button
                                type="button"
                                data-menu-modal-open
                                aria-haspopup="dialog"
                                aria-controls="menu-produk"
                                class="inline-flex min-h-11 items-center gap-2 rounded-pill border-2 border-brand-brown/15 bg-white px-6 py-3.5 text-sm font-semibold text-brand-brown shadow-sticker transition-colors hover:border-brand-brown/30 sm:text-base">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M4 7h16M4 12h16M4 17h10" />
                                </svg>
                                Lihat Menu
                            </button>
                        @endif

                        {{-- CTA hanya dirender bila teks DAN URL-nya sama-sama
                             lolos pemeriksaan di service. Tidak pernah ada
                             tombol mati di halaman ini. --}}
                        @if ($page['cta'])
                            <a href="{{ $page['cta']['url'] }}"
                               target="_blank"
                               rel="noopener noreferrer"
                               class="inline-flex min-h-11 items-center rounded-pill border-2 border-brand-brown/15 bg-white px-6 py-3.5 text-sm font-semibold text-brand-brown shadow-sticker transition-colors hover:border-brand-brown/30 sm:text-base">
                                {{ $page['cta']['text'] }}
                            </a>
                        @endif
                    </div>
                </div>

                @if ($page['poster'])
                    <img
                        src="{{ asset($page['poster']['src']) }}"
                        alt="{{ $page['poster']['alt'] }}"
                        @if ($page['poster']['width']) width="{{ $page['poster']['width'] }}" @endif
                        @if ($page['poster']['height']) height="{{ $page['poster']['height'] }}" @endif
                        decoding="async"
                        {{-- object-contain: poster tidak dipotong menyesatkan. --}}
                        class="w-full rounded-card border-2 border-brand-brown/10 bg-white object-contain shadow-sticker">
                @endif
            </div>
        </div>
    </section>

    @if ($page['detailed_description'])
        <section class="py-12 sm:py-16" aria-labelledby="keterangan">
            <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                <h2 id="keterangan" class="font-display text-2xl font-bold text-brand-brown sm:text-3xl">
                    Keterangan
                </h2>

                {{-- Teks bebas dari admin. Dicetak sebagai teks biasa dengan
                     baris baru dipertahankan -- TIDAK pernah sebagai HTML,
                     supaya markup dari isian admin tidak bisa dirender. --}}
                <div class="mt-4 space-y-4 text-base leading-relaxed text-brand-brown/75">
                    @foreach (preg_split('/\R{2,}/', $page['detailed_description']) as $paragraph)
                        <p>{!! nl2br(e(trim($paragraph))) !!}</p>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    <section id="daftar-lokasi" class="bg-white py-12 sm:py-16" aria-labelledby="daftar-lokasi-judul">
        <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
            <h2 id="daftar-lokasi-judul" class="font-display text-2xl font-bold text-brand-brown sm:text-3xl">
                Titik Gerobak
            </h2>

            @if (! empty($page['sections']))
                @foreach ($page['sections'] as $section)
                    <div class="mt-10">
                        {{-- Konteks Kota/Grup dan Provinsi berasal dari
                             hierarki, bukan teks yang diketik ulang. --}}
                        <h3 class="font-display text-xl font-bold text-brand-brown">
                            {{ $section['group_name'] }}
                        </h3>
                        <p class="mt-1 text-sm text-brand-brown/60">{{ $section['province_name'] }}</p>

                        @foreach ($section['areas'] as $area)
                            <div class="mt-6">
                                <h4 class="text-sm font-semibold uppercase tracking-[0.14em] text-brand-brown/70">
                                    {{ $area['area_name'] }}
                                </h4>

                                <ul class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                    @foreach ($area['locations'] as $location)
                                        <li>
                                            <x-location-card :location="$location" />
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            @else
                {{-- Halaman aktif yang kehilangan seluruh gerobaknya tetap
                     dapat diakses, tetapi TIDAK menampilkan alamat atau tombol
                     karangan. --}}
                <div class="mx-auto mt-8 max-w-2xl">
                    <div class="rounded-card border-2 border-dashed border-brand-brown/20 bg-brand-cream px-6 py-12 text-center shadow-sticker">
                        <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-pill bg-brand-yellow/40" aria-hidden="true">
                            <svg class="h-6 w-6 text-brand-brown" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 1 1 16 0Z" />
                                <circle cx="12" cy="10" r="3" />
                            </svg>
                        </span>
                        <h3 class="mt-4 font-display text-xl font-bold text-brand-brown">Titik gerobak sedang disiapkan</h3>
                        <p class="mx-auto mt-2 max-w-sm text-sm leading-relaxed text-brand-brown/65">
                            Daftar lokasi untuk halaman ini belum tersedia. Silakan cek kembali beberapa saat lagi.
                        </p>
                    </div>
                </div>
            @endif
        </div>
    </section>

    {{--
        Dialog menu produk.

        Satu pemeriksaan saja: daftarnya kosong atau tidak. Service sudah
        memutuskan segalanya -- saklar mati, produk nonaktif, produk terhapus,
        maupun berkas foto yang hilang -- sehingga di sini tidak pernah ada
        kartu kosong maupun gambar rusak.

        Memakai <dialog> asli, bukan div buatan sendiri: showModal() memberi
        focus trap, Escape, latar inert, dan pengembalian fokus ke tombol
        pembuka secara gratis dari browser. Yang tersisa untuk JavaScript
        hanyalah klik overlay dan kunci scroll halaman -- dua hal yang memang
        tidak ditangani <dialog>. Tidak ada dependency baru.

        Kartunya memakai komponen yang sama dengan homepage, jadi aturan foto,
        emoji cadangan, dan label harga tidak pernah dituliskan dua kali.
        Deskripsi sengaja tidak diteruskan, dan TIDAK ADA tombol detail:
        halaman detail produk belum ada, jadi tombolnya hanya akan mati.
    --}}
    @if (! empty($page['products']))
        <dialog
            id="menu-produk"
            data-menu-modal
            aria-labelledby="menu-produk-judul"
            class="w-full max-w-3xl rounded-t-card bg-brand-cream-soft p-0 text-brand-brown backdrop:bg-brand-brown/50 sm:rounded-card">
            <div class="flex max-h-[85dvh] flex-col sm:max-h-[80dvh]">
                {{-- Pegangan seret khas bottom sheet; dekoratif saja. --}}
                <div class="pt-3 sm:hidden" aria-hidden="true">
                    <span class="mx-auto block h-1.5 w-10 rounded-pill bg-brand-brown/20"></span>
                </div>

                <div class="flex items-start justify-between gap-4 border-b-2 border-brand-brown/10 px-5 py-4 sm:px-6">
                    <div>
                        <h2 id="menu-produk-judul" class="font-display text-xl font-bold text-brand-brown sm:text-2xl">
                            Menu DIMDUM
                        </h2>
                        <p class="mt-0.5 text-sm text-brand-brown/65">
                            Pilihan yang tersedia di {{ $page['title'] }}.
                        </p>
                    </div>

                    <button
                        type="button"
                        data-menu-modal-close
                        class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-pill border-2 border-brand-brown/10 bg-white text-brand-brown transition-colors hover:border-brand-brown/30">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M18 6 6 18M6 6l12 12" />
                        </svg>
                        <span class="sr-only">Tutup menu</span>
                    </button>
                </div>

                <div class="overflow-y-auto overscroll-contain px-5 py-5 sm:px-6">
                    <ul class="grid grid-cols-2 gap-4 sm:gap-5 md:grid-cols-3">
                        @foreach ($page['products'] as $product)
                            <li>
                                <x-product-card
                                    :name="$product['name']"
                                    :price="$product['price']"
                                    :image="$product['image']"
                                    :emoji="$product['emoji']" />
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </dialog>
    @endif
@endsection
