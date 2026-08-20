<?php

namespace App\Enums;

/**
 * Permission DIMDUM.
 *
 * Sumber tunggal nama permission. Jangan menulis string permission langsung
 * di controller, policy, resource, atau Blade -- selalu lewat enum ini.
 */
enum PanelPermission: string
{
    case AccessAdminPanel = 'access_admin_panel';
    case ManageUsers = 'manage_users';
    case ManageRoles = 'manage_roles';
    case ManageSiteSettings = 'manage_site_settings';
    case ManageHomepage = 'manage_homepage';

    public function label(): string
    {
        return match ($this) {
            self::AccessAdminPanel => 'Akses Admin Panel',
            self::ManageUsers => 'Kelola User',
            self::ManageRoles => 'Kelola Role',
            self::ManageSiteSettings => 'Kelola Pengaturan Situs',
            self::ManageHomepage => 'Kelola Homepage',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
