<?php

namespace App\Enums;

/**
 * Permission DIMDUM.
 *
 * Sumber tunggal nama permission. Jangan menulis string permission langsung
 * di controller, policy, resource, atau Blade -- selalu lewat enum ini.
 *
 * Modul lokasi terbagi dua sejak slug dipisahkan dari hierarki:
 *
 *   MASTER HIERARKI  Provinsi, Kota/Grup, Area, Gerobak. Data internal
 *                    operasional; tidak punya URL publik.
 *   HALAMAN SLUG     Landing page publik. Satu-satunya yang punya slug,
 *                    publikasi, dan SEO -- karena itu izinnya lebih ketat.
 */
enum PanelPermission: string
{
    case AccessAdminPanel = 'access_admin_panel';
    case ManageUsers = 'manage_users';
    case ManageRoles = 'manage_roles';
    case ManageSiteSettings = 'manage_site_settings';
    case ManageHomepage = 'manage_homepage';

    // ------------------------------------------------- master hierarki lokasi
    case ViewLocations = 'view_locations';
    case CreateLocations = 'create_locations';
    case UpdateLocations = 'update_locations';
    case DeleteLocations = 'delete_locations';
    case ManageLocationProvinces = 'manage_location_provinces';
    case ManageLocationGroups = 'manage_location_groups';
    case ManageLocationAreas = 'manage_location_areas';
    case ManageLocationMedia = 'manage_location_media';

    // --------------------------------------------------- halaman slug lokasi
    case ViewLocationPages = 'view_location_pages';
    case CreateLocationPages = 'create_location_pages';
    case UpdateLocationPages = 'update_location_pages';
    case DeleteLocationPages = 'delete_location_pages';
    case PublishLocationPages = 'publish_location_pages';
    case ChangeLocationPageSlugs = 'change_location_page_slugs';
    case ManageLocationPageMedia = 'manage_location_page_media';

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
            self::ManageLocationProvinces => 'Kelola Provinsi',
            self::ManageLocationGroups => 'Kelola Kota/Grup',
            self::ManageLocationAreas => 'Kelola Area',
            self::ManageLocationMedia => 'Kelola Foto Gerobak',

            self::ViewLocationPages => 'Lihat Halaman Slug Lokasi',
            self::CreateLocationPages => 'Tambah Halaman Slug Lokasi',
            self::UpdateLocationPages => 'Ubah Halaman Slug Lokasi',
            self::DeleteLocationPages => 'Hapus Halaman Slug Lokasi',
            self::PublishLocationPages => 'Terbitkan Halaman Slug Lokasi',
            self::ChangeLocationPageSlugs => 'Ubah Slug Halaman Lokasi',
            self::ManageLocationPageMedia => 'Kelola Poster Halaman Lokasi',
        };
    }

    /**
     * Permission master hierarki. Dipakai UserRole untuk memberi Admin
     * seluruh kemampuan hierarki tanpa menulis ulang daftarnya.
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
            self::ManageLocationProvinces,
            self::ManageLocationGroups,
            self::ManageLocationAreas,
            self::ManageLocationMedia,
        ];
    }

    /**
     * Permission modul Halaman Slug Lokasi.
     *
     * @return list<self>
     */
    public static function locationPageCases(): array
    {
        return [
            self::ViewLocationPages,
            self::CreateLocationPages,
            self::UpdateLocationPages,
            self::DeleteLocationPages,
            self::PublishLocationPages,
            self::ChangeLocationPageSlugs,
            self::ManageLocationPageMedia,
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
