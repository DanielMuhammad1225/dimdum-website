@props([
    'name',
    'price' => null,
    'image' => null,
    'emoji' => null,
    'description' => null,
])

{{--
    Kartu produk.

    Tiga tingkat tampilan gambar, dipilih di sini dan bukan di controller:
      1. foto, bila berkasnya benar-benar ada di disk;
      2. emoji, bila produk belum berfoto tetapi punya emoji;
      3. placeholder netral, bila keduanya kosong.

    'image' sudah bernilai null bila berkasnya tidak ada, sehingga
    <img src=""> tidak mungkin terbentuk.

    Label harga hanya dirender bila 'price' terisi -- produk tanpa harga pasti
    tidak menampilkan baris kosong maupun "Rp0".
--}}
<article class="flex h-full flex-col overflow-hidden rounded-card border-2 border-brand-brown/10 bg-white shadow-sticker">
    @if ($image)
        <img src="{{ asset($image['src']) }}"
             alt="{{ $image['alt'] }}"
             width="{{ $image['width'] }}"
             height="{{ $image['height'] }}"
             loading="lazy"
             decoding="async"
             class="aspect-square w-full object-cover">
    @elseif ($emoji)
        {{-- Emoji dekoratif: teks di sebelahnya sudah menyebut nama produk,
             jadi ia disembunyikan dari pembaca layar agar tidak dibacakan
             sebagai nama karakter. --}}
        <div class="flex aspect-square w-full items-center justify-center bg-brand-cream">
            <span aria-hidden="true" class="text-5xl leading-none sm:text-6xl">{{ $emoji }}</span>
        </div>
    @else
        <div class="flex aspect-square w-full items-center justify-center bg-brand-cream px-3 text-center">
            <span class="text-xs font-medium text-brand-brown/70">Foto produk<br>segera hadir</span>
        </div>
    @endif

    <div class="flex flex-1 flex-col gap-1 p-4">
        <h3 class="font-display text-base font-semibold leading-snug text-brand-brown sm:text-lg">{{ $name }}</h3>

        @if ($price)
            <p class="font-display text-sm font-bold text-brand-brown/85">{{ $price }}</p>
        @endif

        @if ($description)
            <p class="mt-1 text-sm leading-relaxed text-brand-brown/70">{{ $description }}</p>
        @endif
    </div>
</article>
