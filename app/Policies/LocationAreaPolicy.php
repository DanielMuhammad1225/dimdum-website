<?php

namespace App\Policies;

use App\Enums\PanelPermission;
use App\Models\LocationArea;
use App\Models\User;

/**
 * Authorization wilayah landing.
 *
 * Wilayah menentukan URL publik yang dipakai iklan, jadi pengelolaannya
 * dibatasi pada pemegang manage_location_areas -- Operator tidak termasuk.
 *
 * Seluruh pemeriksaan berjalan di server. Menyembunyikan menu saja tidak
 * pernah dianggap sebagai proteksi. Akun nonaktif sudah ditolak lebih dulu
 * oleh Gate::before global.
 */
class LocationAreaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PanelPermission::ViewLocations->value);
    }

    public function view(User $user, LocationArea $area): bool
    {
        return $user->can(PanelPermission::ViewLocations->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PanelPermission::ManageLocationAreas->value);
    }

    public function update(User $user, LocationArea $area): bool
    {
        return $user->can(PanelPermission::ManageLocationAreas->value);
    }

    /**
     * Wilayah tidak boleh dihapus selama masih memuat gerobak yang belum
     * dihapus. Menghapusnya akan mematikan URL yang mungkin sedang dipakai
     * iklan dan meninggalkan gerobak tanpa induk.
     */
    public function delete(User $user, LocationArea $area): bool
    {
        if (! $user->can(PanelPermission::DeleteLocations->value)) {
            return false;
        }

        return ! $area->locations()->exists();
    }

    public function restore(User $user, LocationArea $area): bool
    {
        return $user->can(PanelPermission::DeleteLocations->value);
    }

    public function forceDelete(User $user, LocationArea $area): bool
    {
        if (! $user->can(PanelPermission::DeleteLocations->value)) {
            return false;
        }

        return ! $area->locations()->withTrashed()->exists();
    }
}
