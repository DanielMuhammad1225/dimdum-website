@props([
    'name',
    'priceNote' => null,
    'image' => null,
])

<article class="flex h-full flex-col overflow-hidden rounded-card border-2 border-brand-brown/10 bg-white shadow-sticker">
    @if ($image)
        <img src="{{ asset($image['src']) }}"
             alt="{{ $image['alt'] }}"
             width="{{ $image['width'] ?? 480 }}"
             height="{{ $image['height'] ?? 480 }}"
             loading="lazy"
             decoding="async"
             class="aspect-square w-full object-cover">
    @else
        {{-- Placeholder foto produk. Diganti otomatis saat 'image' terisi di config. --}}
        <div class="flex aspect-square w-full items-center justify-center bg-brand-cream px-3 text-center">
            <span class="text-xs font-medium text-brand-brown/70">Foto produk<br>segera hadir</span>
        </div>
    @endif

    <div class="flex flex-1 flex-col gap-1 p-4">
        <h3 class="font-display text-base font-semibold leading-snug text-brand-brown sm:text-lg">{{ $name }}</h3>
        <p class="font-display text-sm font-bold text-brand-brown/85">{{ $priceNote }}</p>
    </div>
</article>
