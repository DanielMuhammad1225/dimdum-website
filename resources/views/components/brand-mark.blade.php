@props([
    'brand',
    'linked' => true,
    'tone' => 'dark',
    'imgClass' => 'h-9 w-auto object-contain sm:h-10',
    'loading' => 'lazy',
    'fetchpriority' => null,
])

@php
    $logo = $brand['assets']['logo_horizontal'] ?? null;
    $wordmarkTone = $tone === 'light' ? 'text-brand-cream-soft' : 'text-brand-brown';
    $accentTone = $tone === 'light' ? 'text-brand-yellow' : 'text-brand-orange';
    $classes = 'inline-flex min-h-11 items-center gap-2 rounded-pill';
@endphp

@if ($linked)
    <a href="{{ route('home') }}" {{ $attributes->merge(['class' => $classes]) }} aria-label="{{ $brand['name'] }} — beranda">
@else
    <span {{ $attributes->merge(['class' => $classes]) }}>
@endif

@if ($logo)
    <x-brand-picture
        :image="$logo"
        :alt="$brand['name']"
        :loading="$loading"
        :fetchpriority="$fetchpriority"
        :img-class="$imgClass"
        class="block" />
@else
    {{-- Logo resmi belum tersedia: pakai text fallback wordmark. --}}
    <span class="font-display text-2xl font-bold tracking-tight {{ $wordmarkTone }}">DIM<span class="{{ $accentTone }}">DUM</span></span>
@endif

@if ($linked)
    </a>
@else
    </span>
@endif
