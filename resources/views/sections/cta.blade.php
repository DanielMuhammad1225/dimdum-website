@php($cta = $homepage['cta'])

<section class="py-14 sm:py-20">
    <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
        <div class="rounded-card bg-brand-brown px-6 py-12 text-center sm:px-12 sm:py-16">
            {{-- Mascot dipakai sebagai aksen kecil, bukan pusat section. --}}
            <x-brand-picture
                :image="$brand['assets']['icon']"
                alt=""
                img-class="mx-auto h-16 w-16 object-contain sm:h-20 sm:w-20"
                class="block" />

            <h2 class="mx-auto mt-6 max-w-2xl font-display text-3xl font-bold leading-tight text-brand-cream-soft sm:text-4xl">
                {{ $cta['headline'] }}
            </h2>

            <p class="mx-auto mt-4 max-w-xl text-base leading-relaxed text-brand-cream-soft/80">
                {{ $cta['description'] }}
            </p>

            <div class="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                <x-primary-button :href="$cta['primary_cta']['href']">{{ $cta['primary_cta']['label'] }}</x-primary-button>
                <x-primary-button :href="$cta['secondary_cta']['href']" variant="ghost">{{ $cta['secondary_cta']['label'] }}</x-primary-button>
            </div>

            @unless ($brand['locations']['available'])
                <p class="mx-auto mt-6 max-w-md text-sm text-brand-cream-soft/65">
                    {{ $homepage['locations']['coming_soon_title'] }}. Pantau halaman ini untuk info gerobak terbaru.
                </p>
            @endunless
        </div>
    </div>
</section>
