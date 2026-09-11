@props([
    'icon' => 'link',
])

{{--
    Ikon social media dari ALLOWLIST (App\Enums\SocialIcon).

    Admin hanya memilih nama ikon; markup di bawah hidup di kode. Tidak ada
    HTML maupun SVG dari admin yang pernah sampai ke halaman. Nilai yang tidak
    dikenal jatuh ke ikon tautan generik.

    Ikonnya dekoratif -- nama platform selalu ditulis sebagai teks di
    sebelahnya -- jadi disembunyikan dari pembaca layar.
--}}
@php($name = \App\Enums\SocialIcon::resolve($icon)->value)

<svg {{ $attributes->merge(['class' => 'h-5 w-5 shrink-0']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    @switch($name)
        @case('instagram')
            <rect x="3" y="3" width="18" height="18" rx="5" />
            <circle cx="12" cy="12" r="4" />
            <path d="M17.5 6.5h.01" />
            @break
        @case('tiktok')
            <path d="M14 3v11.5a3.5 3.5 0 1 1-3.5-3.5" />
            <path d="M14 3c.6 2.6 2.5 4.2 5 4.5" />
            @break
        @case('facebook')
            <path d="M15 3h-2a4 4 0 0 0-4 4v3H7v4h2v7h4v-7h2.5l.5-4h-3V7.5a1 1 0 0 1 1-1H15z" />
            @break
        @case('youtube')
            <rect x="2.5" y="5.5" width="19" height="13" rx="4" />
            <path d="m10 9.5 5 2.5-5 2.5z" />
            @break
        @case('x')
            <path d="M4 4l16 16" />
            <path d="M20 4 4 20" />
            @break
        @case('whatsapp')
            <path d="M3.5 20.5 5 16.3A8.5 8.5 0 1 1 7.7 19z" />
            <path d="M9 9.2c.2 2.6 3.2 5.6 5.8 5.8l1.2-1.6-2-1.1-.9.8a4 4 0 0 1-2.2-2.2l.8-.9-1.1-2z" />
            @break
        @case('marketplace')
            <path d="M5.5 8h13l-1.2 12h-10.6z" />
            <path d="M9 8a3 3 0 0 1 6 0" />
            @break
        @case('food_delivery')
            <path d="M4 12h16a8 8 0 0 1-16 0z" />
            <path d="M8 8c0-1.2 1-1.2 1-2.4M12 8c0-1.2 1-1.2 1-2.4M16 8c0-1.2 1-1.2 1-2.4" />
            @break
        @case('website')
            <circle cx="12" cy="12" r="9" />
            <path d="M3 12h18" />
            <path d="M12 3a14 14 0 0 1 0 18 14 14 0 0 1 0-18z" />
            @break
        @default
            <path d="M10 14a4 4 0 0 0 5.66 0l3-3a4 4 0 0 0-5.66-5.66l-1 1" />
            <path d="M14 10a4 4 0 0 0-5.66 0l-3 3a4 4 0 0 0 5.66 5.66l1-1" />
    @endswitch
</svg>
