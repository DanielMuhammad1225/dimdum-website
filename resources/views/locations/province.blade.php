@extends('layouts.public')

@push('head')
    {{-- BreadcrumbList + ItemList area. @json memakai flag HEX_* sehingga isi
         data tidak mungkin menutup tag script ini. --}}
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
                <span aria-current="page" class="font-semibold text-brand-brown">{{ $province['name'] }}</span>
            </nav>

            <p class="inline-flex items-center rounded-pill border-2 border-brand-brown/10 bg-white px-4 py-1.5 text-xs font-semibold uppercase tracking-[0.16em] text-brand-brown/85">
                Provinsi {{ $province['name'] }}
            </p>

            <h1 class="mt-5 max-w-3xl font-display text-4xl font-bold leading-[1.1] text-brand-brown sm:text-5xl">
                {{ $province['headline'] }}
            </h1>

            <p class="mt-4 max-w-2xl text-base leading-relaxed text-brand-brown/75 sm:text-lg">
                {{ $province['description'] }}
            </p>
        </div>
    </section>

    <section class="py-14 sm:py-20">
        <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
            @if (! empty($province['groups']))
                <div class="space-y-12 sm:space-y-16">
                    @foreach ($province['groups'] as $group)
                        {{-- Kota/Grup hanya HEADING, bukan tautan: ia tidak punya
                             halaman sendiri, jadi menautkannya akan menghasilkan
                             tautan mati. --}}
                        <div>
                            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                <h2 class="font-display text-2xl font-bold text-brand-brown sm:text-3xl">
                                    {{ $group['name'] }}
                                </h2>
                                <span class="inline-flex items-center rounded-pill bg-brand-cream px-3 py-1 text-xs font-semibold text-brand-brown/80">
                                    {{ $group['type_label'] }}
                                </span>
                            </div>

                            @if (! empty($group['description']))
                                <p class="mt-2 max-w-2xl text-sm leading-relaxed text-brand-brown/70">
                                    {{ $group['description'] }}
                                </p>
                            @endif

                            <ul class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                                @foreach ($group['areas'] as $area)
                                    <li>
                                        <a href="{{ $area['url'] }}"
                                           class="flex h-full flex-col rounded-card border-2 border-brand-brown/10 bg-white p-6 shadow-sticker transition-transform duration-150 ease-out hover:-translate-y-0.5 hover:border-brand-brown/25">
                                            <h3 class="font-display text-lg font-bold text-brand-brown sm:text-xl">{{ $area['name'] }}</h3>

                                            <p class="mt-3 flex-1 text-sm leading-relaxed text-brand-brown/75">
                                                {{ $area['description'] }}
                                            </p>

                                            <span class="mt-5 inline-flex w-fit items-center rounded-pill bg-brand-cream px-4 py-1.5 text-xs font-semibold text-brand-brown">
                                                {{ $area['location_count'] }} titik gerobak
                                            </span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            @else
                {{-- Provinsi terbit tetapi belum punya area/gerobak tampil. --}}
                <div class="mx-auto max-w-2xl rounded-card border-2 border-dashed border-brand-brown/20 bg-white px-6 py-12 text-center shadow-sticker">
                    <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-pill bg-brand-yellow/40" aria-hidden="true">
                        <svg class="h-6 w-6 text-brand-brown" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 1 1 16 0Z" />
                            <circle cx="12" cy="10" r="3" />
                        </svg>
                    </span>
                    <h2 class="mt-4 font-display text-xl font-bold text-brand-brown">Area di {{ $province['name'] }} sedang disiapkan</h2>
                    <p class="mx-auto mt-2 max-w-sm text-sm leading-relaxed text-brand-brown/65">
                        Titik gerobak DIMDUM di provinsi ini sedang kami perbarui dan akan tampil kembali di halaman ini.
                    </p>
                    <a href="{{ route('locations.index') }}"
                       class="mt-6 inline-flex min-h-11 items-center rounded-pill bg-brand-orange px-6 text-sm font-semibold text-brand-brown shadow-sticker transition-colors hover:bg-brand-orange/90">
                        Lihat provinsi lain
                    </a>
                </div>
            @endif
        </div>
    </section>
@endsection
