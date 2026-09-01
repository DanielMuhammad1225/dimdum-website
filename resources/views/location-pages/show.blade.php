@extends('layouts.public')

@push('head')
    {{-- ItemList + FoodEstablishment, hanya dari data yang benar-benar ada.
         @json memakai flag HEX_* sehingga isi data tidak mungkin menutup
         tag script ini. --}}
    <script type="application/ld+json">@json($structuredData, \App\Services\LocationStructuredData::JSON_FLAGS)</script>
@endpush

@section('content')
    <section class="bg-brand-cream">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6 sm:py-20 lg:px-8">
            <nav aria-label="Remah roti" class="mb-6 text-sm text-brand-brown/70">
                <a href="{{ route('home') }}" class="inline-flex min-h-11 items-center rounded-pill hover:text-brand-orange">Beranda</a>
                <span aria-hidden="true" class="px-1">/</span>
                <span aria-current="page" class="font-semibold text-brand-brown">{{ $page['title'] }}</span>
            </nav>

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
@endsection
