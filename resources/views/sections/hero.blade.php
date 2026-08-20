@php($hero = $homepage['hero'])

<section class="relative overflow-hidden bg-brand-cream">
    <div aria-hidden="true" class="pointer-events-none absolute -right-24 -top-24 hidden h-64 w-64 rounded-full bg-brand-yellow/30 lg:block"></div>
    <div aria-hidden="true" class="pointer-events-none absolute -bottom-28 -left-20 hidden h-56 w-56 rounded-full bg-brand-orange/15 lg:block"></div>

    <div class="relative mx-auto grid max-w-6xl gap-10 px-4 py-14 sm:px-6 sm:py-20 lg:grid-cols-2 lg:items-center lg:gap-14 lg:px-8">
        <div>
            <p class="inline-flex items-center rounded-pill border-2 border-brand-brown/10 bg-white px-4 py-1.5 text-xs font-semibold uppercase tracking-[0.16em] text-brand-brown/85">
                {{ $hero['eyebrow'] }}
            </p>

            <h1 class="mt-5 font-display text-4xl font-bold leading-[1.1] text-brand-brown sm:text-5xl lg:text-6xl">
                {{ $hero['headline'] }}
            </h1>

            <p class="mt-4 font-display text-xl font-semibold text-brand-brown sm:text-2xl">
                {{ $brand['tagline'] }}
            </p>

            <p class="mt-4 max-w-lg text-base leading-relaxed text-brand-brown/75 sm:text-lg">
                {{ $hero['description'] }}
            </p>

            <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                <x-primary-button :href="$hero['primary_cta']['href']">{{ $hero['primary_cta']['label'] }}</x-primary-button>
                <x-primary-button :href="$hero['secondary_cta']['href']" variant="secondary">{{ $hero['secondary_cta']['label'] }}</x-primary-button>
            </div>

            <ul class="mt-8 flex flex-wrap gap-2">
                @foreach ($hero['highlights'] as $highlight)
                    <li class="rounded-pill bg-white px-4 py-2 text-xs font-semibold text-brand-brown/80 shadow-sticker sm:text-sm">
                        {{ $highlight }}
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="lg:pl-6">
            @if ($hero['image'])
                <x-brand-picture
                    :image="$hero['image']"
                    :alt="$hero['image']['alt']"
                    loading="eager"
                    fetchpriority="high"
                    img-class="w-full rounded-card border-2 border-brand-brown/10 object-cover shadow-sticker-lg"
                    class="block" />
            @else
                {{-- Foto produk resmi belum tersedia. Menampilkan lockup logo
                     resmi + harga masuk, bukan foto makanan buatan. --}}
                <div class="rounded-card border-2 border-brand-brown/10 bg-brand-cream-soft px-6 py-10 shadow-sticker-lg sm:px-10">
                    <x-brand-picture
                        :image="$brand['assets']['logo']"
                        :alt="'Logo ' . $brand['name'] . ' dengan mascot dimsum'"
                        loading="eager"
                        fetchpriority="high"
                        img-class="animate-brand-float mx-auto h-auto w-full max-w-xs object-contain sm:max-w-sm"
                        class="block" />

                    <p class="mt-8 flex items-center justify-center gap-2">
                        <span class="rounded-pill bg-brand-orange px-5 py-2.5 font-display text-lg font-bold text-brand-brown shadow-sticker sm:text-xl">
                            Mulai Rp1.000
                        </span>
                        <span class="rounded-pill bg-brand-yellow px-5 py-2.5 font-display text-lg font-bold text-brand-brown shadow-sticker sm:text-xl">
                            Satuan
                        </span>
                    </p>
                </div>
            @endif
        </div>
    </div>
</section>
