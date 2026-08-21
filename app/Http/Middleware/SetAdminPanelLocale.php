<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menetapkan locale khusus untuk admin panel.
 *
 * Locale hanya diubah pada request panel, sehingga halaman publik tetap
 * memakai locale aplikasi (APP_LOCALE) dan copywriting homepage tidak
 * terpengaruh. Terjemahan yang dipakai adalah terjemahan resmi bawaan
 * Filament -- tidak ada file vendor yang diedit dan tidak ada terjemahan
 * manual yang dibuat.
 */
class SetAdminPanelLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = config('dimdum.admin.locale');

        if ($locale) {
            App::setLocale($locale);
        }

        return $next($request);
    }
}
