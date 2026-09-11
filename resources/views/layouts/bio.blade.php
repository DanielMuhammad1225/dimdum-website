<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ $seo['title'] }}</title>
    <meta name="description" content="{{ $seo['description'] }}">

    {{-- Halaman Bio adalah pintu masuk dari profil social media, bukan
         halaman yang perlu bersaing di hasil pencarian dengan halaman utama.
         'follow' tetap dipasang supaya tautan ke halaman publik lain tetap
         dihitung mesin pencari. --}}
    <meta name="robots" content="noindex, follow">

    @if ($seo['canonical'])
        <link rel="canonical" href="{{ $seo['canonical'] }}">
    @endif

    {{-- Tag Open Graph tetap dipasang: noindex tidak mencegah tautan Bio
         dibagikan, dan pratinjaunya harus tetap rapi. --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $brand['name'] }}">
    <meta property="og:title" content="{{ $seo['title'] }}">
    <meta property="og:description" content="{{ $seo['description'] }}">
    @if ($seo['canonical'])
        <meta property="og:url" content="{{ $seo['canonical'] }}">
    @endif
    <meta property="og:locale" content="{{ $seo['locale'] }}">
    @if ($seo['image'])
        <meta property="og:image" content="{{ asset($seo['image']['src']) }}">
        <meta property="og:image:width" content="{{ $seo['image']['width'] }}">
        <meta property="og:image:height" content="{{ $seo['image']['height'] }}">
        <meta property="og:image:type" content="{{ $seo['image']['type'] }}">
        <meta property="og:image:alt" content="Logo {{ $brand['name'] }}">
        <meta name="twitter:card" content="summary_large_image">
    @else
        <meta name="twitter:card" content="summary">
    @endif

    @php($favicon = $brand['assets']['favicon'] ?? null)
    @if ($favicon)
        <link rel="icon" href="{{ asset($favicon['ico']) }}" sizes="any">
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset($favicon['png_32']) }}">
        <link rel="apple-touch-icon" href="{{ asset($favicon['apple_touch']) }}">
    @endif

    <meta name="theme-color" content="{{ $brand['colors']['orange'] }}">

    {{ Vite::fonts() }}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-dvh bg-brand-cream-soft font-sans text-brand-brown antialiased">
    <a href="#konten-utama"
       class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-pill focus:bg-brand-brown focus:px-5 focus:py-3 focus:text-sm focus:font-semibold focus:text-brand-cream-soft">
        Lompat ke konten utama
    </a>

    {{-- Halaman turunan membawa bilah kembali ke /bio. Halaman /bio sendiri
         tidak: identitas brand sudah menjadi isi utamanya. --}}
    @hasSection('back')
        <header class="border-b-2 border-brand-brown/10 bg-brand-cream-soft">
            <div class="mx-auto flex max-w-5xl items-center justify-between gap-3 px-4 py-2 sm:px-6">
                <a href="{{ route('bio.home') }}"
                   class="inline-flex min-h-11 items-center gap-2 rounded-pill pr-3 text-sm font-semibold text-brand-brown hover:text-brand-orange">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="m15 18-6-6 6-6" />
                    </svg>
                    @yield('back')
                </a>

                <x-brand-mark :brand="$brand" :linked="false" img-class="h-8 w-auto object-contain" />
            </div>
        </header>
    @endif

    <main id="konten-utama">
        @yield('content')
    </main>

    <footer class="px-4 pb-10 pt-6 text-center text-xs text-brand-brown/60">
        <a href="{{ route('home') }}" class="inline-flex min-h-11 items-center rounded-pill px-3 font-semibold text-brand-brown/80 underline decoration-brand-orange decoration-2 underline-offset-4 hover:text-brand-orange">
            Kunjungi website {{ $brand['name'] }}
        </a>
        <p class="mt-1">&copy; {{ now()->year }} {{ $brand['name'] }}</p>
    </footer>
</body>
</html>
