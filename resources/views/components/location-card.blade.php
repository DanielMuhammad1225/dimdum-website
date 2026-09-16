@props([
    'location',
])

{{--
    Kartu satu gerobak.

    Hanya field yang benar-benar terisi yang dirender: tidak ada label kosong,
    tidak ada href="#", dan tombol Maps hanya muncul bila URL-nya sudah lolos
    pemeriksaan di service.

    Pengelompokan gerobak SUDAH menjadi tugas entitas Area, jadi kartu ini
    tidak lagi membawa label filter berbasis string.
--}}
<article
    class="flex h-full flex-col rounded-card border-2 border-brand-brown/10 bg-white p-6 shadow-sticker"
    data-location-card>

    <h3 class="font-display text-lg font-bold text-brand-brown sm:text-xl">{{ $location['name'] }}</h3>

    <dl class="mt-3 space-y-2 text-sm leading-relaxed text-brand-brown/75">
        @if (! empty($location['full_address']))
            <div>
                <dt class="sr-only">Alamat</dt>
                <dd class="flex gap-2">
                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-brand-orange" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 1 1 16 0Z" />
                        <circle cx="12" cy="10" r="3" />
                    </svg>
                    <span>{{ $location['full_address'] }}</span>
                </dd>
            </div>
        @endif

        @if (! empty($location['landmark']))
            <div>
                <dt class="sr-only">Patokan</dt>
                <dd class="flex gap-2">
                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-brand-orange" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 2v20M2 12h20" />
                    </svg>
                    <span>Patokan: {{ $location['landmark'] }}</span>
                </dd>
            </div>
        @endif

        @if (! empty($location['operational_hours_text']))
            <div>
                <dt class="sr-only">Jam operasional</dt>
                <dd class="flex gap-2">
                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-brand-orange" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="9" />
                        <path d="M12 7v5l3 2" />
                    </svg>
                    <span>{{ $location['operational_hours_text'] }}</span>
                </dd>
            </div>
        @endif
    </dl>

    @if (! empty($location['images']))
        @php($cover = $location['images'][0])
        <img
            src="{{ asset($cover['src']) }}"
            alt="{{ $cover['alt'] }}"
            width="{{ $cover['width'] }}"
            height="{{ $cover['height'] }}"
            loading="lazy"
            decoding="async"
            {{-- object-contain: bentuk gerobak tidak dipotong menyesatkan. --}}
            class="mt-4 h-40 w-full rounded-2xl border-2 border-brand-brown/10 bg-brand-cream object-contain">
    @endif

    @if (! empty($location['maps_url']) || ! empty($location['whatsapp_url']))
        <div class="mt-auto flex flex-wrap gap-2 pt-5">
            @if (! empty($location['maps_url']))
                <a href="{{ $location['maps_url'] }}"
                   target="_blank"
                   rel="noopener noreferrer"
                   class="inline-flex min-h-11 items-center gap-2 rounded-pill bg-brand-orange px-5 text-sm font-semibold text-brand-brown shadow-sticker transition-colors hover:bg-brand-orange/90">
                    Buka di Google Maps
                    <span class="sr-only">untuk {{ $location['name'] }}</span>
                </a>
            @endif

            @if (! empty($location['whatsapp_url']))
                <a href="{{ $location['whatsapp_url'] }}"
                   target="_blank"
                   rel="noopener noreferrer"
                   class="inline-flex min-h-11 items-center rounded-pill border-2 border-brand-brown/15 bg-white px-5 text-sm font-semibold text-brand-brown shadow-sticker transition-colors hover:border-brand-brown/30">
                    WhatsApp
                    <span class="sr-only">untuk {{ $location['name'] }}</span>
                </a>
            @endif
        </div>
    @else
        {{-- Tanpa koordinat dan tanpa link sah: sampaikan apa adanya, jangan
             membuat tombol mati. --}}
        <p class="mt-auto pt-5 text-xs text-brand-brown/60">Tautan peta belum tersedia untuk titik ini.</p>
    @endif
</article>
