@extends('layouts.bio')

@section('content')
    {{-- Bio nonaktif. Tetap halaman yang utuh -- judul, penjelasan, dan jalan
         keluar -- bukan layar error kosong. --}}
    <div class="mx-auto flex max-w-md flex-col items-center px-4 pb-4 pt-16 text-center">
        <p class="font-display text-sm font-semibold uppercase tracking-[0.16em] text-brand-brown/60">404</p>

        <h1 class="mt-2 font-display text-3xl font-bold leading-tight text-brand-brown">
            Halaman belum tersedia
        </h1>

        <p class="mt-3 text-base leading-relaxed text-brand-brown/75">
            Halaman ini sedang tidak aktif. Informasi {{ $brand['name'] }} tetap bisa dilihat di website utama.
        </p>

        <a href="{{ route('home') }}"
           class="mt-8 inline-flex min-h-14 items-center justify-center rounded-pill bg-brand-orange px-8 text-base font-semibold text-brand-brown shadow-sticker-lg">
            Ke website {{ $brand['name'] }}
        </a>
    </div>
@endsection
