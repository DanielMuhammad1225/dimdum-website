@props([
    'brand',
    'nav',
])

<footer class="border-t-2 border-brand-brown/10 bg-brand-cream">
    <div class="mx-auto max-w-6xl px-4 py-12 sm:px-6 lg:px-8">
        <div class="flex flex-col gap-10 md:flex-row md:items-start md:justify-between">
            <div class="max-w-sm">
                <x-brand-mark :brand="$brand" :linked="false" img-class="h-10 w-auto object-contain" />
                <p class="mt-3 font-display text-base font-semibold text-brand-brown">{{ $brand['tagline'] }}</p>
                <p class="mt-2 text-sm leading-relaxed text-brand-brown/75">{{ $brand['positioning'] }}</p>
            </div>

            {{-- Halaman tanpa navigasi situs (slug lokasi) mengirim nav kosong. --}}
            @if (! empty($nav))
                <nav aria-label="Navigasi footer">
                    <h2 class="font-display text-sm font-bold uppercase tracking-[0.14em] text-brand-brown/60">Jelajahi</h2>
                    <ul class="mt-2">
                        @foreach ($nav as $item)
                            <li>
                                <a href="{{ $item['href'] }}" class="inline-flex min-h-11 items-center rounded-pill text-sm font-medium text-brand-brown/80 hover:text-brand-orange">
                                    {{ $item['label'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </nav>
            @endif

            <div>
                <h2 class="font-display text-sm font-bold uppercase tracking-[0.14em] text-brand-brown/60">Hubungi</h2>

                @if (! empty($brand['contact']['whatsapp']) || ! empty($brand['social']))
                    <ul class="mt-2">
                        @if (! empty($brand['contact']['whatsapp']))
                            <li>
                                <a href="https://wa.me/{{ preg_replace('/\D/', '', $brand['contact']['whatsapp']) }}"
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   class="inline-flex min-h-11 items-center rounded-pill text-sm font-medium text-brand-brown/80 hover:text-brand-orange">
                                    {{ $brand['contact']['whatsapp_label'] ?? 'WhatsApp' }}
                                </a>
                            </li>
                        @endif
                        @foreach ($brand['social'] as $social)
                            <li>
                                <a href="{{ $social['url'] }}"
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   class="inline-flex min-h-11 items-center rounded-pill text-sm font-medium text-brand-brown/80 hover:text-brand-orange">
                                    {{ $social['label'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="mt-4 max-w-xs text-sm leading-relaxed text-brand-brown/75">
                        Kanal kontak resmi DIMDUM sedang disiapkan dan akan tampil di sini.
                    </p>
                @endif
            </div>
        </div>

        <p class="mt-10 border-t-2 border-brand-brown/10 pt-6 text-xs text-brand-brown/70">
            &copy; {{ now()->year }} {{ $brand['name'] }}. Seluruh hak cipta dilindungi.
        </p>
    </div>
</footer>
