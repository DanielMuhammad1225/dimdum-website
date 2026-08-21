<?php

namespace App\Enums;

/**
 * Role DIMDUM.
 *
 * Sumber tunggal nama role. Authorization tidak boleh memakai ID role
 * maupun string yang ditulis ulang di banyak file.
 */
enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Operator = 'operator';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Admin => 'Admin',
            self::Operator => 'Operator',
        };
    }

    /**
     * Permission bawaan untuk setiap role.
     *
     * Super Admin mendapat seluruh permission secara eksplisit DAN dilindungi
     * Gate::before khusus role ini (lihat AppServiceProvider), sehingga
     * permission yang ditambahkan di fase berikutnya otomatis ikut berlaku
     * tanpa perlu seeding ulang. Gate::before tidak pernah aktif untuk akun
     * nonaktif.
     *
     * @return list<PanelPermission>
     */
    public function defaultPermissions(): array
    {
        return match ($this) {
            self::SuperAdmin => PanelPermission::cases(),

            // manage_users sengaja BELUM diberikan ke Admin sampai aturan
            // User Management dibuat pada fase berikutnya.
            self::Admin => [
                PanelPermission::AccessAdminPanel,
                PanelPermission::ManageSiteSettings,
                PanelPermission::ManageHomepage,
            ],

            // Operator belum punya permission konten karena resource-nya
            // memang belum ada.
            self::Operator => [
                PanelPermission::AccessAdminPanel,
            ],
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
