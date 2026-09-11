@extends('layouts.bio')

@section('back', 'Kembali ke Bio')

@section('content')
    <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 sm:py-12">
        {{-- Satu-satunya H1 di halaman ini. --}}
        <h1 class="font-display text-3xl font-bold leading-tight text-brand-brown sm:text-4xl">
            Menu {{ $brand['name'] }}
        </h1>

        <p class="mt-2 max-w-2xl text-base leading-relaxed text-brand-brown/75">
            Pilihan dimsum {{ $brand['name'] }} beserta harganya.
        </p>

        @if (empty($sections))
            <div class="mt-8 rounded-card border-2 border-dashed border-brand-brown/20 bg-white px-6 py-12 text-center shadow-sticker">
                <h2 class="font-display text-xl font-bold text-brand-brown">Menu sedang disiapkan</h2>
                <p class="mx-auto mt-2 max-w-sm text-sm leading-relaxed text-brand-brown/65">
                    Daftar menu {{ $brand['name'] }} belum tersedia di halaman ini. Silakan cek kembali beberapa saat lagi.
                </p>
            </div>
        @else
            {{-- Nama dan urutan kelompok berasal dari ProductCategory lewat
                 service -- tidak ada nama kategori yang ditulis di sini. --}}
            @foreach ($sections as $section)
                <section class="mt-8" aria-labelledby="kategori-{{ $section['key'] }}">
                    <h2 id="kategori-{{ $section['key'] }}" class="font-display text-2xl font-bold text-brand-brown">
                        {{ $section['heading'] }}
                    </h2>

                    <ul class="mt-4 grid grid-cols-2 gap-4 sm:gap-5 md:grid-cols-3 lg:grid-cols-4">
                        @foreach ($section['items'] as $item)
                            <li>
                                <x-product-card
                                    :name="$item['name']"
                                    :price="$item['price']"
                                    :image="$item['image']"
                                    :emoji="$item['emoji']"
                                    :badge="$item['type']" />
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        @endif
    </div>
@endsection
