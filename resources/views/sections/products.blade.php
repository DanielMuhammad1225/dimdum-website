@php($products = $homepage['products'])

<section id="{{ $products['id'] }}" class="bg-brand-cream py-14 sm:py-20">
    <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
        <x-section-heading :title="$products['title']" :description="$products['description']" />

        <div class="mt-10 grid grid-cols-2 gap-4 sm:gap-5 md:grid-cols-3">
            @foreach ($products['items'] as $item)
                <x-product-card
                    :name="$item['name']"
                    :price-note="$item['price_note'] ?? $products['fallback_price_note']"
                    :image="$item['image']" />
            @endforeach
        </div>

        <p class="mt-8 text-center text-sm text-brand-brown/75">{{ $products['note'] }}</p>
    </div>
</section>
