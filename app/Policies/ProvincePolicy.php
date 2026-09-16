<?php

namespace App\Policies;

use App\Enums\PanelPermission;
use App\Models\Province;
use App\Models\User;

/**
 * Authorization provinsi.
 *
 * Slug provinsi adalah segmen pertama setiap URL landing, jadi mengubahnya
 * ikut memutus URL seluruh Area di bawahnya. Pengelolaannya dibatasi pada
 * pemegang manage_location_provinces -- Operator tidak termasuk.
 *
 * Seluruh pemeriksaan berjalan di server. Menyembunyikan menu saja tidak
 * pernah dianggap sebagai proteksi. Akun nonaktif sudah ditolak lebih dulu
 * oleh Gate::before global.
 */
class ProvincePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PanelPermission::ViewLocations->value);
    }

    public function view(User $user, Province $province): bool
    {
        return $user->can(PanelPermission::ViewLocations->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PanelPermission::ManageLocationProvinces->value);
    }

    public function update(User $user, Province $province): bool
    {
        return $user->can(PanelPermission::ManageLocationProvinces->value);
    }

    /**
     * Provinsi tidak boleh dihapus selama masih memuat Kota/Grup yang belum
     * dihapus. Menghapusnya akan mematikan URL yang mungkin sedang dipakai
     * iklan dan meninggalkan seluruh turunannya tanpa induk.
     */
    public function delete(User $user, Province $province): bool
    {
        if (! $user->can(PanelPermission::DeleteLocations->value)) {
            return false;
        }

        return ! $province->groups()->exists();
    }

    public function restore(User $user, Province $province): bool
    {
        return $user->can(PanelPermission::DeleteLocations->value);
    }

    public function forceDelete(User $user, Province $province): bool
    {
        if (! $user->can(PanelPermission::DeleteLocations->value)) {
            return false;
        }

        return ! $province->groups()->withTrashed()->exists();
    }
}
