@props([
    'title',
    'description',
    'step',
])

<li class="relative rounded-card border-2 border-brand-brown/10 bg-white p-6 shadow-sticker">
    <span class="font-display text-4xl font-bold text-brand-orange/35" aria-hidden="true">{{ str_pad((string) $step, 2, '0', STR_PAD_LEFT) }}</span>
    <h3 class="mt-1 font-display text-xl font-bold text-brand-brown">{{ $title }}</h3>
    <p class="mt-2 text-sm leading-relaxed text-brand-brown/70">{{ $description }}</p>
</li>
