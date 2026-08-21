<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Http\Middleware\SetAdminPanelLocale;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()

            /*
             | Registrasi publik, reset password, dan halaman profil sengaja
             | TIDAK diaktifkan. Akun admin hanya dibuat lewat command
             | interaktif dimdum:create-super-admin. Reset password menunggu
             | konfigurasi mailer.
             */

            ->brandName('DIMDUM')
            ->brandLogo(asset('images/brand/dimdum-logo-horizontal.png'))
            ->brandLogoHeight('2rem')
            ->favicon(asset('images/brand/dimdum-icon-32.png'))
            /*
             | Orange dipakai sebagai warna aksi (primary). Skala netral memakai
             | Stone -- abu-abu hangat yang senada dengan Deep Brown tanpa
             | mengorbankan kontras teks. Deep Brown TIDAK dipakai sebagai skala
             | netral: karena warnanya sangat gelap, Filament akan mewarnai
             | seluruh permukaan panel dan keterbacaan turun drastis.
             */
            ->colors([
                'primary' => Color::hex('#f47a32'),
                'gray' => Color::Stone,
            ])

            ->pages([
                Dashboard::class,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')

            // Tidak ada widget bawaan: belum ada data yang layak ditampilkan.
            ->widgets([])

            /*
             | Admin panel tidak boleh diindeks mesin pencari.
             */
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => Blade::render('<meta name="robots" content="noindex, nofollow">'),
            )

            /*
             | Locale panel di-set lewat middleware (API resmi Filament untuk
             | menyisipkan middleware per panel). isPersistent: true supaya
             | request update Livewire ikut memakai locale yang sama.
             */
            ->middleware([
                SetAdminPanelLocale::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ], isPersistent: true)
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
