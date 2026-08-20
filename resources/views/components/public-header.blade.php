@props([
    'brand',
    'nav',
])

<header class="sticky top-0 z-40 border-b-2 border-brand-brown/10 bg-brand-cream-soft/95 backdrop-blur supports-[backdrop-filter]:bg-brand-cream-soft/80">
    <div class="mx-auto flex h-header max-w-6xl items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
        <x-brand-mark :brand="$brand" loading="eager" fetchpriority="high" />

        <nav class="hidden md:block" aria-label="Navigasi utama">
            <ul class="flex items-center gap-1">
                @foreach ($nav as $item)
                    <li>
                        <a href="{{ $item['href'] }}"
                           class="inline-flex min-h-11 items-center rounded-pill px-3 text-sm font-medium text-brand-brown/80 transition-colors hover:bg-brand-cream hover:text-brand-brown">
                            {{ $item['label'] }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>

        <div class="hidden md:block">
            <a href="#lokasi"
               class="inline-flex min-h-11 items-center rounded-pill bg-brand-orange px-5 text-sm font-semibold text-brand-brown shadow-sticker transition-colors hover:bg-brand-orange/90">
                Cari Gerobak
            </a>
        </div>

        <button type="button"
                id="menu-toggle"
                class="inline-flex h-11 w-11 items-center justify-center rounded-pill border-2 border-brand-brown/15 text-brand-brown md:hidden"
                aria-controls="menu-mobile"
                aria-expanded="false">
            <span class="sr-only">Buka menu navigasi</span>
            <svg data-menu-icon="open" class="h-5 w-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                <path d="M3 6h14M3 10h14M3 14h14" />
            </svg>
            <svg data-menu-icon="close" class="hidden h-5 w-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                <path d="M5 5l10 10M15 5L5 15" />
            </svg>
        </button>
    </div>

    <nav id="menu-mobile" class="hidden border-t-2 border-brand-brown/10 bg-brand-cream-soft md:hidden" aria-label="Navigasi mobile">
        <ul class="mx-auto max-w-6xl space-y-1 px-4 py-4 sm:px-6">
            @foreach ($nav as $item)
                <li>
                    <a href="{{ $item['href'] }}"
                       class="block rounded-2xl px-4 py-3 text-base font-medium text-brand-brown hover:bg-brand-cream">
                        {{ $item['label'] }}
                    </a>
                </li>
            @endforeach
            <li class="pt-2">
                <a href="#lokasi"
                   class="block rounded-pill bg-brand-orange px-4 py-3 text-center text-base font-semibold text-brand-brown shadow-sticker">
                    Cari Gerobak
                </a>
            </li>
        </ul>
    </nav>
</header>
