@props([
    'title',
    'description' => null,
    'eyebrow' => null,
    'align' => 'center',
    'tone' => 'dark',
    'level' => 'h2',
])

@php
    $alignment = $align === 'left' ? 'text-left' : 'mx-auto max-w-2xl text-center';
    $titleTone = $tone === 'light' ? 'text-white' : 'text-brand-brown';
    $descTone = $tone === 'light' ? 'text-white/85' : 'text-brand-brown/70';
    $eyebrowTone = $tone === 'light' ? 'text-brand-yellow' : 'text-brand-brown/85';
@endphp

<div {{ $attributes->merge(['class' => $alignment]) }}>
    @if ($eyebrow)
        <p class="mb-3 text-xs font-semibold uppercase tracking-[0.18em] {{ $eyebrowTone }}">{{ $eyebrow }}</p>
    @endif

    <{{ $level }} class="font-display text-3xl font-bold leading-tight sm:text-4xl {{ $titleTone }}">{{ $title }}</{{ $level }}>

    @if ($description)
        <p class="mt-4 text-base leading-relaxed {{ $descTone }}">{{ $description }}</p>
    @endif
</div>
