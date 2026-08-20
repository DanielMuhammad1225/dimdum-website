<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\PanelPermission;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Tentukan apakah user boleh masuk ke admin panel.
     *
     * Tiga syarat, dievaluasi di server pada setiap request panel:
     *   1. Akun aktif (is_active).
     *   2. Punya permission access_admin_panel.
     *   3. Panel yang diminta memang panel admin DIMDUM.
     *
     * Pemeriksaan is_active sengaja berdiri sendiri dan dievaluasi lebih
     * dulu, supaya bypass Gate::before milik super_admin tidak bisa
     * meloloskan akun yang sudah dinonaktifkan.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() !== 'admin') {
            return false;
        }

        if (! $this->is_active) {
            return false;
        }

        return $this->can(PanelPermission::AccessAdminPanel->value);
    }

    /**
     * Label role utama untuk ditampilkan di dashboard.
     */
    public function primaryRoleLabel(): string
    {
        $role = $this->roles->first();

        if (! $role) {
            return 'Tanpa Role';
        }

        return UserRole::tryFrom($role->name)?->label() ?? $role->name;
    }
}
