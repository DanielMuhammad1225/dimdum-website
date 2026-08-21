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
use Spatie\Permission\Contracts\Permission;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    // Method Spatie di-alias supaya versi asli tetap bisa dipanggil dari
    // override hasPermissionTo() di bawah.
    use HasRoles {
        hasPermissionTo as protected spatieHasPermissionTo;
    }

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
     * Akun nonaktif tidak pernah dianggap punya permission.
     *
     * Override ini menutup jalur milik spatie/laravel-permission: package
     * tersebut mendaftarkan Gate::before-nya sendiri lewat
     * callAfterResolving(Gate::class), sehingga selalu dievaluasi SEBELUM
     * Gate::before milik aplikasi. Tanpa override ini, permission yang masih
     * ter-assign akan mengembalikan true dan Gate berhenti di situ, sehingga
     * aturan "nonaktif = ditolak" tidak pernah tercapai.
     *
     * Assignment permission di database sengaja TIDAK dihapus -- yang berubah
     * hanya hasil pemeriksaan authorization-nya. Begitu akun diaktifkan lagi,
     * seluruh permission langsung berlaku kembali tanpa perlu re-seed.
     *
     * @param  \BackedEnum|Permission|string|int  $permission
     */
    public function hasPermissionTo($permission, ?string $guardName = null): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return $this->spatieHasPermissionTo($permission, $guardName);
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
