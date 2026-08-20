@props([
    'href',
    'variant' => 'primary',
])

@php
    $base = 'inline-flex items-center justify-center gap-2 rounded-pill px-6 py-3.5 text-sm font-semibold transition-transform duration-150 ease-out hover:-translate-y-0.5 sm:text-base';

    $variants = [
        'primary' => 'bg-brand-orange text-brand-brown shadow-sticker-lg hover:bg-brand-orange/90',
        'secondary' => 'border-2 border-brand-brown/15 bg-white text-brand-brown shadow-sticker hover:border-brand-brown/30',
        'inverse' => 'bg-brand-cream-soft text-brand-brown shadow-sticker-lg hover:bg-white',
        'ghost' => 'border-2 border-brand-cream-soft/60 text-brand-cream-soft hover:bg-white/10',
    ];
@endphp

<a href="{{ $href }}" {{ $attributes->merge(['class' => $base . ' ' . ($variants[$variant] ?? $variants['primary'])]) }}>
    {{ $slot }}
</a>
