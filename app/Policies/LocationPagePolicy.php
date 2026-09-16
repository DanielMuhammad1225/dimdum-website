<?php

namespace App\Policies;

use App\Enums\PanelPermission;
use App\Models\LocationPage;
use App\Models\User;

/**
 * Authorization Halaman Slug Lokasi.
 *
 * Halaman slug adalah wajah publik DIMDUM: ia memiliki URL, konten, dan SEO.
 * Karena itu izinnya lebih ketat daripada master hierarki -- Operator tidak
 * mendapat satu pun permission modul ini.
 *
 * Publikasi dan penggantian slug dipisah menjadi permission tersendiri,
 * sehingga seseorang bisa menyunting isi halaman tanpa berhak menerbitkannya
 * atau mengubah URL-nya.
 *
 * Seluruh pemeriksaan berjalan di server. Menyembunyikan menu saja tidak
 * pernah dianggap sebagai proteksi. Akun nonaktif sudah ditolak lebih dulu
 * oleh Gate::before global.
 */
class LocationPagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PanelPermission::ViewLocationPages->value);
    }

    public function view(User $user, LocationPage $page): bool
    {
        return $user->can(PanelPermission::ViewLocationPages->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PanelPermission::CreateLocationPages->value);
    }

    public function update(User $user, LocationPage $page): bool
    {
        return $user->can(PanelPermission::UpdateLocationPages->value);
    }

    public function delete(User $user, LocationPage $page): bool
    {
        return $user->can(PanelPermission::DeleteLocationPages->value);
    }

    public function restore(User $user, LocationPage $page): bool
    {
        return $user->can(PanelPermission::DeleteLocationPages->value);
    }

    public function forceDelete(User $user, LocationPage $page): bool
    {
        return $user->can(PanelPermission::DeleteLocationPages->value);
    }

    /**
     * Menerbitkan atau menarik halaman dari publik.
     */
    public function publish(User $user, LocationPage $page): bool
    {
        return $user->can(PanelPermission::PublishLocationPages->value);
    }

    /**
     * Mengubah slug berarti mengubah URL yang mungkin sedang dipakai iklan.
     */
    public function changeSlug(User $user, LocationPage $page): bool
    {
        return $user->can(PanelPermission::ChangeLocationPageSlugs->value);
    }

    public function manageMedia(User $user, LocationPage $page): bool
    {
        return $user->can(PanelPermission::ManageLocationPageMedia->value);
    }

    /**
     * Menata urutan halaman mengubah tampilan homepage, jadi ia mengikuti
     * permission mengubah -- bukan sekadar "bisa melihat".
     */
    public function reorder(User $user): bool
    {
        return $user->can(PanelPermission::UpdateLocationPages->value);
    }
}
