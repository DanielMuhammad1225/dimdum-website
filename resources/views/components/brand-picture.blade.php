@props([
    'image',
    'alt' => '',
    'loading' => 'lazy',
    'fetchpriority' => null,
    'imgClass' => '',
])

{{-- WebP diutamakan, PNG/JPG sebagai fallback. Width/height selalu eksplisit
     agar tidak terjadi layout shift. --}}
<picture {{ $attributes }}>
    @if (! empty($image['webp']))
        <source srcset="{{ asset($image['webp']) }}" type="image/webp">
    @endif
    <img src="{{ asset($image['src']) }}"
         alt="{{ $alt }}"
         width="{{ $image['width'] }}"
         height="{{ $image['height'] }}"
         loading="{{ $loading }}"
         decoding="async"
         @if ($fetchpriority) fetchpriority="{{ $fetchpriority }}" @endif
         class="{{ $imgClass }}">
</picture>
