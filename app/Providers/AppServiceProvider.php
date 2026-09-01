<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         | HomepageContentService dan SiteSettingsService sengaja TIDAK
         | didaftarkan sebagai singleton/scoped.
         |
         | Keduanya memoize hasil bacaannya di dalam instance. HomeController
         | menerima satu instance lewat constructor, jadi satu render halaman
         | tetap hanya menghasilkan satu query per singleton. Membuatnya
         | shared justru berbahaya: instance yang sama akan menyimpan data
         | lama setelah admin menyimpan perubahan.
         */
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerAuthorizationGate();
    }

    /**
     * Aturan authorization global DIMDUM.
     *
     * Urutan evaluasi:
     *   1. Akun nonaktif  -> false. Seluruh authorization ditolak, termasuk
     *      permission yang masih ter-assign dan policy apa pun.
     *   2. Super Admin aktif -> true. Bypass HANYA berdasarkan role, tanpa
     *      email atau ID yang di-hardcode.
     *   3. Selain itu -> null, sehingga permission/policy normal tetap jalan.
     *
     * Catatan penting soal urutan callback:
     * spatie/laravel-permission mendaftarkan Gate::before miliknya sendiri
     * lewat callAfterResolving(Gate::class), sehingga callback-nya selalu
     * terdaftar LEBIH DULU daripada callback ini. Callback Spatie akan
     * mengembalikan true untuk permission yang dimiliki user dan Gate berhenti
     * di hasil non-null yang pertama -- artinya `false` di sini saja TIDAK
     * cukup untuk memblokir akun nonaktif.
     *
     * Karena itu User::hasPermissionTo() juga di-override agar mengembalikan
     * false saat akun nonaktif. Kombinasi keduanya membuat jalur Spatie
     * menghasilkan null lalu jatuh ke callback ini.
     */
    protected function registerAuthorizationGate(): void
    {
        Gate::before(function (?User $user, string $ability): ?bool {
            if (! $user instanceof User) {
                return null;
            }

            if (! $user->is_active) {
                return false;
            }

            return $user->hasRole(UserRole::SuperAdmin->value) ? true : null;
        });
    }
}
