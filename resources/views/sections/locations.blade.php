@php($locations = $homepage['locations'])

{{--
    Judul dan deskripsi section tetap dikelola Homepage CMS.
    Daftar halamannya berasal dari database lewat LocationPageCatalogService
    dan sudah di-resolve controller -- tidak ada query di Blade dan tidak ada
    nama lokasi yang ditulis di config.
--}}
<section id="{{ $locations['id'] }}" class="bg-brand-cream py-14 sm:py-20">
    <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
        <x-section-heading :title="$locations['title']" :description="$locations['description']" />

        @if (! empty($locationPages))
            <ul class="mx-auto mt-10 grid max-w-4xl gap-4 sm:grid-cols-2">
                @foreach ($locationPages as $locationPage)
                    <li>
                        <a href="{{ $locationPage['url'] }}"
                           class="flex h-full flex-col rounded-card border-2 border-brand-brown/10 bg-white p-6 shadow-sticker transition-transform duration-150 ease-out hover:-translate-y-0.5 hover:border-brand-brown/25">
                            <h3 class="font-display text-lg font-bold text-brand-brown">{{ $locationPage['title'] }}</h3>

                            @if ($locationPage['short_description'])
                                <p class="mt-1 text-sm text-brand-brown/60">{{ $locationPage['short_description'] }}</p>
                            @elseif ($locationPage['period_text'])
                                <p class="mt-1 text-sm text-brand-brown/60">{{ $locationPage['period_text'] }}</p>
                            @endif

                            <span class="mt-4 inline-flex w-fit items-center rounded-pill bg-brand-cream px-4 py-1.5 text-xs font-semibold text-brand-brown">
                                {{ $locationPage['location_count'] }} titik gerobak
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>

        @else
            {{-- Belum ada halaman slug yang terbit: empty state dari Homepage
                 CMS, bukan alamat karangan. --}}
            <div class="mx-auto mt-10 max-w-2xl">
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
            </div>
        @endif
    </div>
</section>
