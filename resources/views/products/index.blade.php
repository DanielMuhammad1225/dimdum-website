@extends('layouts.public')

@section('content')
    <section class="bg-brand-cream">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6 sm:py-20 lg:px-8">
            <nav aria-label="Remah roti" class="mb-6 text-sm text-brand-brown/70">
                <a href="{{ route('home') }}" class="inline-flex min-h-11 items-center rounded-pill hover:text-brand-orange">Beranda</a>
                <span aria-hidden="true" class="px-1">/</span>
                <span aria-current="page" class="font-semibold text-brand-brown">Produk</span>
            </nav>

            {{-- Satu-satunya H1 di halaman ini. --}}
            <h1 class="max-w-3xl font-display text-4xl font-bold leading-[1.1] text-brand-brown sm:text-5xl">
                Daftar Produk
            </h1>

            <p class="mt-4 max-w-2xl text-base leading-relaxed text-brand-brown/75 sm:text-lg">
                Semua pilihan {{ $brand['name'] }} beserta harganya. Ketersediaan bisa berbeda di setiap gerobak.
            </p>
        </div>
    </section>

    @if (! empty($sections))
        @foreach ($sections as $section)
            <section class="{{ $loop->even ? 'bg-brand-cream' : 'bg-white' }} py-12 sm:py-16"
                     aria-labelledby="kategori-{{ $section['key'] }}">
                <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                    <h2 id="kategori-{{ $section['key'] }}"
                        class="font-display text-2xl font-bold text-brand-brown sm:text-3xl">
                        {{ $section['heading'] }}
                    </h2>

                    <ul class="mt-8 grid grid-cols-2 gap-4 sm:gap-5 md:grid-cols-3 lg:grid-cols-4">
                        @foreach ($section['items'] as $item)
                            <li>
                                <x-product-card
                                    :name="$item['name']"
                                    :price="$item['price']"
                                    :image="$item['image']"
                                    :emoji="$item['emoji']"
                                    :description="$item['description']" />
                            </li>
                        @endforeach
                    </ul>
                </div>
            </section>
        @endforeach
    @else
        {{-- Katalog kosong tetap menghasilkan halaman yang utuh, bukan daftar
             kosong tanpa keterangan. --}}
        <section class="bg-white py-12 sm:py-16">
            <div class="mx-auto max-w-2xl px-4 sm:px-6 lg:px-8">
                <div class="rounded-card border-2 border-dashed border-brand-brown/20 bg-white px-6 py-12 text-center shadow-sticker">
                    <h2 class="font-display text-xl font-bold text-brand-brown">Daftar produk segera hadir</h2>
                    <p class="mx-auto mt-2 max-w-sm text-sm leading-relaxed text-brand-brown/65">
                        Pilihan {{ $brand['name'] }} sedang kami siapkan dan akan tampil di halaman ini.
                    </p>

                    <a href="{{ route('home') }}"
                       class="mt-6 inline-flex min-h-11 items-center rounded-pill border-2 border-brand-brown/15 bg-white px-6 py-3 text-sm font-semibold text-brand-brown shadow-sticker transition-colors hover:border-brand-brown/30">
                        Kembali ke beranda
                    </a>
                </div>
            </div>
        </section>
    @endif
@endsection
