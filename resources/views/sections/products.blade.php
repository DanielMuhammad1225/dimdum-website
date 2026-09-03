@php($products = $homepage['products'])

<section id="{{ $products['id'] }}" class="bg-brand-cream py-14 sm:py-20">
    <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
        {{-- Judul dan deskripsi tetap milik Homepage CMS. Yang berpindah ke
             database hanyalah daftar produknya. --}}
        <x-section-heading :title="$products['title']" :description="$products['description']" />

        @if (! empty($products['items']))
            <div class="mt-10 grid grid-cols-2 gap-4 sm:gap-5 md:grid-cols-3">
                @foreach ($products['items'] as $item)
                    <x-product-card
                        :name="$item['name']"
                        :price="$item['price']"
                        :image="$item['image']"
                        :emoji="$item['emoji']" />
                @endforeach
            </div>

            {{-- Tombol hanya muncul bila memang ADA sisa yang belum tampil di
                 sini. Menautkan ke halaman yang isinya sama persis hanya akan
                 membuang satu klik pengunjung. --}}
            @if ($products['has_more'])
                <div class="mt-8 text-center">
                    <a href="{{ route('products.index') }}"
                       class="inline-flex min-h-11 items-center rounded-pill bg-brand-orange px-6 py-3.5 text-sm font-semibold text-brand-brown shadow-sticker-lg transition-transform duration-150 ease-out hover:-translate-y-0.5 sm:text-base">
                        Lihat Semua
                    </a>
                </div>
            @endif

            <p class="mt-8 text-center text-sm text-brand-brown/75">{{ $products['note'] }}</p>
        @else
            {{-- Daftar produk belum tersedia: tampilkan empty state, bukan kartu kosong. --}}
            <div class="mx-auto mt-10 max-w-2xl rounded-card border-2 border-dashed border-brand-brown/20 bg-white px-6 py-12 text-center shadow-sticker">
                <h3 class="font-display text-xl font-bold text-brand-brown">{{ $products['empty_state']['title'] }}</h3>
                <p class="mx-auto mt-2 max-w-sm text-sm leading-relaxed text-brand-brown/65">
                    {{ $products['empty_state']['description'] }}
                </p>
            </div>
        @endif
    </div>
</section>
