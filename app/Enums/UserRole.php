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
            // modul lokasi -- master hierarki DAN halaman slug -- diberikan
            // penuh: Admin memang pemilik halaman publik.
            self::Admin => [
                PanelPermission::AccessAdminPanel,
                PanelPermission::ManageSiteSettings,
                PanelPermission::ManageHomepage,
                ...PanelPermission::locationCases(),
                ...PanelPermission::locationPageCases(),
                ...PanelPermission::productCases(),
                ...PanelPermission::bioCases(),
            ],

            /*
             | Operator bekerja pada MASTER DATA gerobak sehari-hari: melihat,
             | menambah, memperbarui informasi, dan mengelola foto.
             |
             | SENGAJA TIDAK diberikan: manage_location_areas, delete_locations,
             | dan SELURUH permission Halaman Slug Lokasi. Halaman slug adalah
             | wajah publik DIMDUM -- membuat, menerbitkan, menghapus, dan
             | mengganti slug-nya tetap menjadi kewenangan Admin dan Super
             | Admin. Operator tetap dapat mengubah data gerobak yang tampil
             | di halaman itu, karena itu memang tugasnya.
             |
             | Pada modul Produk polanya sama: Operator mengurus isi katalog
             | sehari-hari -- melihat, menambah, mengubah, dan menggantikan
             | fotonya. Menghapus, apalagi menghapus permanen, tetap milik
             | Admin dan Super Admin: satu produk yang hilang berarti satu
             | kartu hilang dari homepage tanpa jejak yang bisa dipulihkan
             | Operator sendiri.
             |
             | Modul Bio SENGAJA tidak diberikan sama sekali. Halaman Bio
             | adalah tautan yang dipasang di profil social media: nomor
             | WhatsApp, tombol, dan daftar tautannya dibuka orang dari luar
             | situs. Mengubahnya tetap kewenangan Admin dan Super Admin.
             */
            self::Operator => [
                PanelPermission::AccessAdminPanel,
                PanelPermission::ViewLocations,
                PanelPermission::CreateLocations,
                PanelPermission::UpdateLocations,
                PanelPermission::ManageLocationMedia,
                PanelPermission::ViewProducts,
                PanelPermission::CreateProducts,
                PanelPermission::UpdateProducts,
                PanelPermission::ManageProductMedia,
            ],
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
