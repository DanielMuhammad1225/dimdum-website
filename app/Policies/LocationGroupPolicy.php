<?php

namespace App\Policies;

use App\Enums\PanelPermission;
use App\Models\LocationGroup;
use App\Models\User;

/**
 * Authorization Kota/Grup.
 *
 * Kota/Grup tidak punya URL publik, tetapi ia menentukan BENTUK hierarki:
 * memindahkannya atau menonaktifkannya menyembunyikan seluruh Area di
 * bawahnya sekaligus. Karena itu pengelolaannya tetap dibatasi pada pemegang
 * manage_location_groups -- Operator tidak termasuk.
 *
 * Seluruh pemeriksaan berjalan di server. Akun nonaktif sudah ditolak lebih
 * dulu oleh Gate::before global.
 */
class LocationGroupPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PanelPermission::ViewLocations->value);
    }

    public function view(User $user, LocationGroup $group): bool
    {
        return $user->can(PanelPermission::ViewLocations->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PanelPermission::ManageLocationGroups->value);
    }

    public function update(User $user, LocationGroup $group): bool
    {
        return $user->can(PanelPermission::ManageLocationGroups->value);
    }

    /**
     * Kota/Grup tidak boleh dihapus selama masih memuat Area yang belum
     * dihapus.
     */
    public function delete(User $user, LocationGroup $group): bool
    {
        if (! $user->can(PanelPermission::DeleteLocations->value)) {
            return false;
        }

        return ! $group->areas()->exists();
    }

    public function restore(User $user, LocationGroup $group): bool
    {
        return $user->can(PanelPermission::DeleteLocations->value);
    }

    public function forceDelete(User $user, LocationGroup $group): bool
    {
        if (! $user->can(PanelPermission::DeleteLocations->value)) {
            return false;
        }

        return ! $group->areas()->withTrashed()->exists();
    }
}
