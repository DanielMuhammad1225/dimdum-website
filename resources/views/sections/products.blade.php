@php($products = $homepage['products'])

<section id="{{ $products['id'] }}" class="bg-brand-cream py-14 sm:py-20">
    <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
        <x-section-heading :title="$products['title']" :description="$products['description']" />

        @if (! empty($products['items']))
            <div class="mt-10 grid grid-cols-2 gap-4 sm:gap-5 md:grid-cols-3">
                @foreach ($products['items'] as $item)
                    <x-product-card
                        :name="$item['name']"
                        :price-note="$item['price_note'] ?? $products['fallback_price_note']"
                        :image="$item['image']" />
                @endforeach
            </div>

            <p class="mt-8 text-center text-sm text-brand-brown/75">{{ $products['note'] }}</p>
        @else
            {{-- Daftar varian belum tersedia: tampilkan empty state, bukan kartu kosong. --}}
            <div class="mx-auto mt-10 max-w-2xl rounded-card border-2 border-dashed border-brand-brown/20 bg-white px-6 py-12 text-center shadow-sticker">
                <h3 class="font-display text-xl font-bold text-brand-brown">{{ $products['empty_state']['title'] }}</h3>
                <p class="mx-auto mt-2 max-w-sm text-sm leading-relaxed text-brand-brown/65">
                    {{ $products['empty_state']['description'] }}
                </p>
            </div>
        @endif
    </div>
</section>
