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
                <a href="{{ route('locations.index') }}" class="inline-flex min-h-11 items-center rounded-pill hover:text-brand-orange">Lokasi</a>
                <span aria-hidden="true" class="px-1">/</span>
                <span aria-current="page" class="font-semibold text-brand-brown">{{ $area['name'] }}</span>
            </nav>

            <p class="inline-flex items-center rounded-pill border-2 border-brand-brown/10 bg-white px-4 py-1.5 text-xs font-semibold uppercase tracking-[0.16em] text-brand-brown/85">
                Wilayah {{ $area['name'] }}
            </p>

            <h1 class="mt-5 max-w-3xl font-display text-4xl font-bold leading-[1.1] text-brand-brown sm:text-5xl">
                {{ $area['headline'] }}
            </h1>

            <p class="mt-4 max-w-2xl text-base leading-relaxed text-brand-brown/75 sm:text-lg">
                {{ $area['description'] }}
            </p>

            @if (! empty($area['locations']))
                <div class="mt-8">
                    <a href="#daftar-lokasi"
                       class="inline-flex min-h-11 items-center rounded-pill bg-brand-orange px-6 py-3.5 text-sm font-semibold text-brand-brown shadow-sticker-lg transition-transform duration-150 ease-out hover:-translate-y-0.5 sm:text-base">
                        Lihat Lokasi
                    </a>
                </div>
            @endif
        </div>
    </section>

    @if (! empty($area['gallery']))
        <section class="py-12 sm:py-16" aria-labelledby="galeri-wilayah">
            <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                <h2 id="galeri-wilayah" class="font-display text-2xl font-bold text-brand-brown sm:text-3xl">
                    Suasana Gerobak di {{ $area['name'] }}
                </h2>

                <ul class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                    @foreach ($area['gallery'] as $image)
                        <li class="overflow-hidden rounded-card border-2 border-brand-brown/10 bg-brand-cream shadow-sticker">
                            <img
                                src="{{ asset($image['src']) }}"
                                alt="{{ $image['alt'] }}"
                                width="{{ $image['width'] }}"
                                height="{{ $image['height'] }}"
                                loading="lazy"
                                decoding="async"
                                class="h-40 w-full object-contain sm:h-44">

                            @if (! empty($image['caption']))
                                <p class="px-3 py-2 text-xs leading-relaxed text-brand-brown/70">{{ $image['caption'] }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif

    <section id="daftar-lokasi" class="py-14 sm:py-20">
        <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
            <h2 class="font-display text-3xl font-bold leading-tight text-brand-brown sm:text-4xl">
                Pilih Lokasi Gerobak
            </h2>

            @if (! empty($area['locations']))
                @if (! empty($area['filters']))
                    {{-- Filter hanya muncul bila memang ada lebih dari satu
                         kelompok. Tanpa JavaScript seluruh kartu tetap terlihat
                         karena penyembunyian dilakukan oleh skrip, bukan CSS. --}}
                    <div class="mt-6" data-location-filter>
                        <h3 class="sr-only">Saring berdasarkan area</h3>
                        <ul class="flex flex-wrap gap-2">
                            <li>
                                <button type="button"
                                        data-filter-value=""
                                        aria-pressed="true"
                                        class="inline-flex min-h-11 items-center rounded-pill border-2 border-brand-brown/15 bg-brand-orange px-5 text-sm font-semibold text-brand-brown shadow-sticker aria-pressed:border-brand-brown/30">
                                    Semua
                                </button>
                            </li>
                            @foreach ($area['filters'] as $filter)
                                <li>
                                    <button type="button"
                                            data-filter-value="{{ $filter }}"
                                            aria-pressed="false"
                                            class="inline-flex min-h-11 items-center rounded-pill border-2 border-brand-brown/15 bg-white px-5 text-sm font-semibold text-brand-brown shadow-sticker transition-colors hover:border-brand-brown/30">
                                        {{ $filter }}
                                    </button>
                                </li>
                            @endforeach
                        </ul>

                        <p class="mt-3 text-sm text-brand-brown/70" role="status" aria-live="polite" data-filter-status></p>
                    </div>
                @endif

                <ul class="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-3" data-location-list>
                    @foreach ($area['locations'] as $location)
                        <li class="h-full">
                            <x-location-card :location="$location" />
                        </li>
                    @endforeach
                </ul>
            @else
                {{-- Wilayah masih terbit tetapi belum ada gerobak yang tampil.
                     Halaman tetap 200 supaya iklan yang sudah berjalan tidak
                     mendarat di 404, tanpa menampilkan alamat karangan. --}}
                <div class="mx-auto mt-8 max-w-2xl rounded-card border-2 border-dashed border-brand-brown/20 bg-white px-6 py-12 text-center shadow-sticker">
                    <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-pill bg-brand-yellow/40" aria-hidden="true">
                        <svg class="h-6 w-6 text-brand-brown" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 1 1 16 0Z" />
                            <circle cx="12" cy="10" r="3" />
                        </svg>
                    </span>
                    <h3 class="mt-4 font-display text-xl font-bold text-brand-brown">Titik lokasi sedang diperbarui</h3>
                    <p class="mx-auto mt-2 max-w-sm text-sm leading-relaxed text-brand-brown/65">
                        Daftar gerobak DIMDUM di wilayah {{ $area['name'] }} sedang kami perbarui dan akan tampil kembali di halaman ini.
                    </p>
                    <div class="mt-6 flex flex-wrap justify-center gap-3">
                        <a href="{{ route('locations.index') }}"
                           class="inline-flex min-h-11 items-center rounded-pill bg-brand-orange px-6 text-sm font-semibold text-brand-brown shadow-sticker transition-colors hover:bg-brand-orange/90">
                            Lihat Wilayah Lain
                        </a>
                        <a href="{{ route('home') }}"
                           class="inline-flex min-h-11 items-center rounded-pill border-2 border-brand-brown/15 bg-white px-6 text-sm font-semibold text-brand-brown shadow-sticker transition-colors hover:border-brand-brown/30">
                            Kembali ke Beranda
                        </a>
                    </div>
                </div>
            @endif
        </div>
    </section>
@endsection
