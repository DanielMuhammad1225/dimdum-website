@extends('layouts.bio')

@section('back', 'Kembali ke Bio')

@section('content')
    <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 sm:py-12">
        {{-- Satu-satunya H1 di halaman ini. --}}
        <h1 class="font-display text-3xl font-bold leading-tight text-brand-brown sm:text-4xl">
            Lokasi Gerobak
        </h1>

        <p class="mt-2 max-w-2xl text-base leading-relaxed text-brand-brown/75">
            @if ($isPages)
                Pilih halaman lokasi untuk melihat titik gerobak {{ $brand['name'] }} di wilayah tersebut.
            @else
                Titik gerobak {{ $brand['name'] }} yang sedang beroperasi.
            @endif
        </p>

        @if ($total > 0 && ! empty($provinces))
            {{--
                Filter memakai form GET biasa: berfungsi tanpa JavaScript,
                sepenuhnya bisa dipakai dengan keyboard, dan hasilnya bisa
                dibagikan lewat URL. Pilihan TIDAK dikirim otomatis saat
                berubah -- mengirim form ketika pengguna keyboard baru
                menggeser pilihan dengan panah justru membuatnya tersesat.
            --}}
            <form method="GET" action="{{ route('bio.locations') }}"
                  class="mt-6 grid gap-3 rounded-card border-2 border-brand-brown/10 bg-white p-4 shadow-sticker sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                <div>
                    <label for="filter-provinsi" class="block text-sm font-semibold text-brand-brown">Provinsi</label>
                    <select id="filter-provinsi" name="provinsi"
                            class="mt-1 block min-h-11 w-full rounded-pill border-2 border-brand-brown/15 bg-brand-cream-soft px-4 text-sm text-brand-brown">
                        <option value="">Semua provinsi</option>
                        @foreach ($provinces as $province)
                            <option value="{{ $province['id'] }}" @selected($selectedProvince === $province['id'])>
                                {{ $province['name'] }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="filter-kota" class="block text-sm font-semibold text-brand-brown">Kota/Grup</label>
                    <select id="filter-kota" name="kota"
                            class="mt-1 block min-h-11 w-full rounded-pill border-2 border-brand-brown/15 bg-brand-cream-soft px-4 text-sm text-brand-brown">
                        <option value="">Semua Kota/Grup</option>
                        @foreach ($groupOptions as $group)
                            <option value="{{ $group['id'] }}" @selected($selectedGroup === $group['id'])>
                                {{-- Nama provinsi disebut bila belum ada provinsi
                                     terpilih: nama Kota/Grup bisa berulang
                                     antarprovinsi. --}}
                                {{ $selectedProvince === null ? $group['name'].' — '.$group['province_name'] : $group['name'] }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="flex gap-2">
                    <button type="submit"
                            class="inline-flex min-h-11 flex-1 items-center justify-center rounded-pill bg-brand-orange px-5 text-sm font-semibold text-brand-brown shadow-sticker sm:flex-none">
                        Terapkan
                    </button>

                    @if ($isFiltered)
                        <a href="{{ route('bio.locations') }}"
                           class="inline-flex min-h-11 flex-1 items-center justify-center rounded-pill border-2 border-brand-brown/15 bg-white px-5 text-sm font-semibold text-brand-brown sm:flex-none">
                            Reset
                        </a>
                    @endif
                </div>
            </form>

            <p class="mt-4 text-sm text-brand-brown/70" aria-live="polite">
                Menampilkan {{ count($items) }} dari {{ $total }} {{ $isPages ? 'halaman lokasi' : 'gerobak' }}.
            </p>
        @endif

        @if ($total === 0)
            <div class="mt-8 rounded-card border-2 border-dashed border-brand-brown/20 bg-white px-6 py-12 text-center shadow-sticker">
                <h2 class="font-display text-xl font-bold text-brand-brown">Lokasi sedang disiapkan</h2>
                <p class="mx-auto mt-2 max-w-sm text-sm leading-relaxed text-brand-brown/65">
                    Titik gerobak {{ $brand['name'] }} belum tersedia di halaman ini. Silakan cek kembali beberapa saat lagi.
                </p>
            </div>
        @elseif (empty($items))
            <div class="mt-6 rounded-card border-2 border-dashed border-brand-brown/20 bg-white px-6 py-10 text-center shadow-sticker">
                <h2 class="font-display text-xl font-bold text-brand-brown">Belum ada lokasi untuk pilihan ini</h2>
                <p class="mx-auto mt-2 max-w-sm text-sm leading-relaxed text-brand-brown/65">
                    Coba pilih provinsi atau Kota/Grup lain.
                </p>
                <a href="{{ route('bio.locations') }}"
                   class="mt-5 inline-flex min-h-11 items-center rounded-pill border-2 border-brand-brown/15 bg-white px-6 text-sm font-semibold text-brand-brown">
                    Tampilkan semua lokasi
                </a>
            </div>
        @elseif ($isPages)
            {{-- Mode halaman slug: daftar datar. Satu halaman bisa mencakup
                 beberapa wilayah, jadi mengelompokkannya per wilayah akan
                 menampilkan halaman yang sama lebih dari sekali. --}}
            <ul class="mt-6 grid gap-4 sm:grid-cols-2">
                @foreach ($items as $page)
                    <li>
                        <article class="flex h-full flex-col rounded-card border-2 border-brand-brown/10 bg-white p-5 shadow-sticker">
                            <h2 class="font-display text-lg font-bold leading-snug text-brand-brown">{{ $page['title'] }}</h2>

                            @if (! empty($page['regions']))
                                <p class="mt-1 break-words text-xs font-semibold uppercase tracking-[0.12em] text-brand-brown/60">
                                    {{ implode(' · ', $page['regions']) }}
                                </p>
                            @endif

                            @if ($page['period_text'])
                                <p class="mt-2 text-sm font-semibold text-brand-orange">{{ $page['period_text'] }}</p>
                            @endif

                            @if ($page['short_description'])
                                <p class="mt-2 break-words text-sm leading-relaxed text-brand-brown/75">{{ $page['short_description'] }}</p>
                            @endif

                            <a href="{{ route('location-pages.show', $page['slug']) }}"
                               class="mt-4 inline-flex min-h-11 items-center justify-center self-start rounded-pill bg-brand-orange px-5 text-sm font-semibold text-brand-brown shadow-sticker">
                                Lihat lokasi
                                <span class="sr-only">{{ $page['title'] }}</span>
                            </a>
                        </article>
                    </li>
                @endforeach
            </ul>
        @else
            {{-- Mode seluruh gerobak: Provinsi -> Kota/Grup sebagai judul,
                 Area hanya label pada kartu. --}}
            @foreach ($sections as $section)
                <section class="mt-8">
                    <h2 class="font-display text-2xl font-bold text-brand-brown">{{ $section['province_name'] }}</h2>

                    @foreach ($section['groups'] as $group)
                        <div class="mt-4">
                            <h3 class="text-sm font-semibold uppercase tracking-[0.14em] text-brand-brown/70">{{ $group['group_name'] }}</h3>

                            <ul class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                @foreach ($group['items'] as $location)
                                    <li>
                                        <article class="flex h-full flex-col rounded-card border-2 border-brand-brown/10 bg-white p-5 shadow-sticker">
                                            <h4 class="font-display text-lg font-bold leading-snug text-brand-brown">{{ $location['name'] }}</h4>

                                            @if ($location['area_name'] !== '')
                                                <p class="mt-1 text-xs font-semibold uppercase tracking-[0.12em] text-brand-brown/60">{{ $location['area_name'] }}</p>
                                            @endif

                                            <dl class="mt-3 space-y-2 text-sm leading-relaxed text-brand-brown/80">
                                                @if ($location['full_address'])
                                                    <div>
                                                        <dt class="sr-only">Alamat</dt>
                                                        <dd class="break-words">{{ $location['full_address'] }}</dd>
                                                    </div>
                                                @endif

                                                @if ($location['landmark'])
                                                    <div>
                                                        <dt class="inline font-semibold text-brand-brown">Patokan:</dt>
                                                        <dd class="inline break-words">{{ $location['landmark'] }}</dd>
                                                    </div>
                                                @endif

                                                @if ($location['operational_hours_text'])
                                                    <div>
                                                        <dt class="inline font-semibold text-brand-brown">Jam:</dt>
                                                        <dd class="inline break-words">{{ $location['operational_hours_text'] }}</dd>
                                                    </div>
                                                @endif
                                            </dl>

                                            {{-- Tombol Maps hanya bila service berhasil
                                                 membentuk URL yang aman. --}}
                                            @if ($location['maps_url'])
                                                <a href="{{ $location['maps_url'] }}"
                                                   target="_blank"
                                                   rel="noopener noreferrer"
                                                   class="mt-4 inline-flex min-h-11 items-center justify-center gap-2 self-start rounded-pill border-2 border-brand-brown/15 bg-white px-5 text-sm font-semibold text-brand-brown">
                                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                        <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 1 1 16 0Z" />
                                                        <circle cx="12" cy="10" r="3" />
                                                    </svg>
                                                    Buka di Maps
                                                    <span class="sr-only">untuk {{ $location['name'] }} (tab baru)</span>
                                                </a>
                                            @endif
                                        </article>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </section>
            @endforeach
        @endif
    </div>
@endsection
