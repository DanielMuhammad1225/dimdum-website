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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerSuperAdminGate();
    }

    /**
     * Super Admin lolos seluruh pemeriksaan permission.
     *
     * Bypass ini HANYA berdasarkan role super_admin -- tidak ada email atau
     * ID yang di-hardcode. Akun nonaktif tidak pernah mendapat bypass, dan
     * akses panel tetap diverifikasi terpisah lewat User::canAccessPanel().
     */
    protected function registerSuperAdminGate(): void
    {
        Gate::before(function (?User $user, string $ability): ?bool {
            if (! $user instanceof User) {
                return null;
            }

            if (! $user->is_active) {
                return null;
            }

            return $user->hasRole(UserRole::SuperAdmin->value) ? true : null;
        });
    }
}
