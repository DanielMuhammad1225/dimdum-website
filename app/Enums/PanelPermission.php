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

    // --------------------------------------------------------- modul lokasi
    case ViewLocations = 'view_locations';
    case CreateLocations = 'create_locations';
    case UpdateLocations = 'update_locations';
    case DeleteLocations = 'delete_locations';
    case ManageLocationAreas = 'manage_location_areas';
    case PublishLocations = 'publish_locations';
    case ChangeLocationSlugs = 'change_location_slugs';
    case ManageLocationMedia = 'manage_location_media';

    public function label(): string
    {
        return match ($this) {
            self::AccessAdminPanel => 'Akses Admin Panel',
            self::ManageUsers => 'Kelola User',
            self::ManageRoles => 'Kelola Role',
            self::ManageSiteSettings => 'Kelola Pengaturan Situs',
            self::ManageHomepage => 'Kelola Homepage',

            self::ViewLocations => 'Lihat Lokasi',
            self::CreateLocations => 'Tambah Gerobak',
            self::UpdateLocations => 'Ubah Gerobak',
            self::DeleteLocations => 'Hapus Lokasi',
            self::ManageLocationAreas => 'Kelola Wilayah Landing',
            self::PublishLocations => 'Terbitkan Lokasi',
            self::ChangeLocationSlugs => 'Ubah Slug Lokasi',
            self::ManageLocationMedia => 'Kelola Foto Gerobak',
        };
    }

    /**
     * Permission modul lokasi. Dipakai UserRole untuk memberi Admin seluruh
     * kemampuan lokasi tanpa menulis ulang daftarnya.
     *
     * @return list<self>
     */
    public static function locationCases(): array
    {
        return [
            self::ViewLocations,
            self::CreateLocations,
            self::UpdateLocations,
            self::DeleteLocations,
            self::ManageLocationAreas,
            self::PublishLocations,
            self::ChangeLocationSlugs,
            self::ManageLocationMedia,
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
