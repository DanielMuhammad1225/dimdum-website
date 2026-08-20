<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ $seo['title'] }}</title>
    <meta name="description" content="{{ $seo['description'] }}">
    <link rel="canonical" href="{{ $seo['canonical'] }}">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $brand['name'] }}">
    <meta property="og:title" content="{{ $seo['title'] }}">
    <meta property="og:description" content="{{ $seo['description'] }}">
    <meta property="og:url" content="{{ $seo['canonical'] }}">
    <meta property="og:locale" content="{{ $seo['locale'] }}">
    @if ($seo['image'])
        <meta property="og:image" content="{{ asset($seo['image']['src']) }}">
        <meta property="og:image:width" content="{{ $seo['image']['width'] }}">
        <meta property="og:image:height" content="{{ $seo['image']['height'] }}">
        <meta property="og:image:type" content="{{ $seo['image']['type'] }}">
        <meta property="og:image:alt" content="Logo {{ $brand['name'] }}">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:image" content="{{ asset($seo['image']['src']) }}">
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

    <x-public-header :brand="$brand" :nav="$nav" />

    <main id="konten-utama">
        @yield('content')
    </main>

    <x-public-footer :brand="$brand" :nav="$nav" />
</body>
</html>
