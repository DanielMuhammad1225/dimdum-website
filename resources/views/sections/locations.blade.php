@php($locations = $homepage['locations'])

<section id="{{ $locations['id'] }}" class="bg-brand-cream py-14 sm:py-20">
    <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
        <x-section-heading :title="$locations['title']" :description="$locations['description']" />

        <div class="mx-auto mt-10 max-w-2xl">
            @if ($brand['locations']['available'] && ! empty($brand['locations']['items']))
                <ul class="grid gap-4 sm:grid-cols-2">
                    @foreach ($brand['locations']['items'] as $location)
                        <li class="rounded-card border-2 border-brand-brown/10 bg-white p-6 shadow-sticker">
                            <h3 class="font-display text-lg font-bold text-brand-brown">{{ $location['name'] }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-brown/70">{{ $location['address'] }}</p>
                        </li>
                    @endforeach
                </ul>
            @else
                {{-- Data lokasi belum tersedia: tampilkan empty state, bukan alamat karangan. --}}
                <div class="rounded-card border-2 border-dashed border-brand-brown/20 bg-white px-6 py-12 text-center shadow-sticker">
                    <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-pill bg-brand-yellow/40" aria-hidden="true">
                        <svg class="h-6 w-6 text-brand-brown" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 1 1 16 0Z" />
                            <circle cx="12" cy="10" r="3" />
                        </svg>
                    </span>
                    <h3 class="mt-4 font-display text-xl font-bold text-brand-brown">{{ $locations['coming_soon_title'] }}</h3>
                    <p class="mx-auto mt-2 max-w-sm text-sm leading-relaxed text-brand-brown/65">
                        {{ $locations['coming_soon_description'] }}
                    </p>
                </div>
            @endif
        </div>
    </div>
</section>
