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
            // User Management dibuat pada fase berikutnya. Seluruh permission
            // modul lokasi diberikan penuh.
            self::Admin => [
                PanelPermission::AccessAdminPanel,
                PanelPermission::ManageSiteSettings,
                PanelPermission::ManageHomepage,
                ...PanelPermission::locationCases(),
            ],

            /*
             | Operator bekerja pada data gerobak sehari-hari: melihat,
             | membuat draft, memperbarui informasi, dan mengelola foto.
             |
             | SENGAJA TIDAK diberikan: manage_location_areas, publish_locations,
             | change_location_slugs, dan delete_locations. Keempatnya mengubah
             | URL publik atau menghapus data, jadi tetap menjadi kewenangan
             | Admin dan Super Admin.
             */
            self::Operator => [
                PanelPermission::AccessAdminPanel,
                PanelPermission::ViewLocations,
                PanelPermission::CreateLocations,
                PanelPermission::UpdateLocations,
                PanelPermission::ManageLocationMedia,
            ],
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
