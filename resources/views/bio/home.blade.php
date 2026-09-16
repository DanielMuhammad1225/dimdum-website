@extends('layouts.bio')

@section('content')
    <div class="mx-auto flex max-w-md flex-col items-center px-4 pb-4 pt-12 text-center sm:pt-16">
        {{-- Identitas brand. Ikon dipakai, bukan lockup horizontal: kolom
             Bio sempit dan logo lebar akan mengecil sampai tidak terbaca. --}}
        @php($icon = $brand['assets']['icon'] ?? null)
        @if ($icon)
            <x-brand-picture
                :image="$icon"
                :alt="'Logo '.$brand['name']"
                loading="eager"
                fetchpriority="high"
                img-class="h-24 w-24 rounded-full border-4 border-white object-cover shadow-sticker-lg"
                class="block" />
        @endif

        {{-- Satu-satunya H1 di halaman ini. --}}
        <h1 class="mt-5 font-display text-3xl font-bold leading-tight text-balance text-brand-brown sm:text-4xl">
            {{ $bioTitle }}
        </h1>

        @if ($bioDescription)
            <p class="mt-2 max-w-sm text-base leading-relaxed text-brand-brown/75">
                {{ $bioDescription }}
            </p>
        @endif

        @if (! empty($buttons))
            {{-- Urutan tombol mengikuti pengaturan admin. Tombol pertama diberi
                 bobot visual paling kuat, sehingga urutan yang dipilih admin
                 juga menjadi prioritas yang terlihat pengunjung. --}}
            <nav aria-label="Tautan utama" class="mt-8 w-full">
                <ul class="flex flex-col gap-3">
                    @foreach ($buttons as $button)
                        <li>
                            <a href="{{ $button['url'] }}"
                               @if ($button['external']) target="_blank" rel="noopener noreferrer" @endif
                               data-bio-button="{{ $button['key'] }}"
                               class="flex min-h-14 w-full items-center justify-center gap-3 rounded-pill border-2 px-6 py-3 text-base font-semibold shadow-sticker transition-transform duration-150 ease-out hover:-translate-y-0.5 {{ $loop->first ? 'border-brand-orange bg-brand-orange text-brand-brown shadow-sticker-lg' : 'border-brand-brown/15 bg-white text-brand-brown' }}">
                                @switch($button['key'])
                                    @case('location')
                                        <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 1 1 16 0Z" />
                                            <circle cx="12" cy="10" r="3" />
                                        </svg>
                                        @break
                                    @case('menu')
                                        <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <path d="M4 7h16M4 12h16M4 17h10" />
                                        </svg>
                                        @break
                                    @case('whatsapp')
                                        <x-social-icon icon="whatsapp" />
                                        @break
                                @endswitch
                                <span>{{ $button['label'] }}</span>
                                @if ($button['external'])
                                    <span class="sr-only">(membuka WhatsApp)</span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>
        @endif

        @if (! empty($social))
            <section aria-labelledby="bio-social" class="mt-10 w-full">
                <h2 id="bio-social" class="font-display text-sm font-semibold uppercase tracking-[0.16em] text-brand-brown/70">
                    Ikuti {{ $brand['name'] }}
                </h2>

                <ul class="mt-4 flex flex-wrap justify-center gap-3">
                    @foreach ($social as $link)
                        <li>
                            <a href="{{ $link['url'] }}"
                               target="_blank"
                               rel="noopener noreferrer"
                               class="inline-flex min-h-11 items-center gap-2 rounded-pill border-2 border-brand-brown/10 bg-white px-4 py-2 text-sm font-semibold text-brand-brown shadow-sticker transition-colors hover:border-brand-brown/30">
                                <x-social-icon :icon="$link['icon']" />
                                <span class="break-words">{{ $link['name'] }}</span>
                                <span class="sr-only">(tab baru)</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if (empty($buttons) && empty($social))
            {{-- Bio aktif tetapi admin menyembunyikan seluruh tombol dan belum
                 menambah social media: halaman tetap utuh, tidak kosong
                 tanpa keterangan. --}}
            <p class="mt-8 rounded-card border-2 border-dashed border-brand-brown/20 bg-white px-6 py-8 text-sm text-brand-brown/70">
                Tautan {{ $brand['name'] }} sedang disiapkan. Silakan cek kembali beberapa saat lagi.
            </p>
        @endif
    </div>
@endsection
