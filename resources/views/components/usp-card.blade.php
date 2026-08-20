@props([
    'title',
    'description',
    'index' => 0,
])

@php
    $accents = [
        'bg-brand-orange text-brand-brown',
        'bg-brand-yellow text-brand-brown',
        'bg-brand-brown text-brand-cream-soft',
    ];
    $accent = $accents[$index % count($accents)];
@endphp

<div class="rounded-card border-2 border-brand-brown/10 bg-white p-6 shadow-sticker sm:p-7">
    <span class="mb-5 flex h-11 w-11 items-center justify-center rounded-pill {{ $accent }} font-display text-lg font-bold" aria-hidden="true">
        {{ $index + 1 }}
    </span>
    <h3 class="font-display text-xl font-bold text-brand-brown">{{ $title }}</h3>
    <p class="mt-2 text-sm leading-relaxed text-brand-brown/70">{{ $description }}</p>
</div>
