<?php

namespace App\Policies;

use App\Enums\PanelPermission;
use App\Models\Location;
use App\Models\User;

/**
 * Authorization gerobak.
 *
 * Operator adalah pengguna harian modul ini: boleh melihat, menambah draft,
 * dan memperbarui informasi. Yang mengubah URL publik atau menghapus data
 * tetap menjadi kewenangan Admin dan Super Admin.
 */
class LocationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PanelPermission::ViewLocations->value);
    }

    public function view(User $user, Location $location): bool
    {
        return $user->can(PanelPermission::ViewLocations->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PanelPermission::CreateLocations->value);
    }

    public function update(User $user, Location $location): bool
    {
        return $user->can(PanelPermission::UpdateLocations->value);
    }

    public function delete(User $user, Location $location): bool
    {
        return $user->can(PanelPermission::DeleteLocations->value);
    }

    public function restore(User $user, Location $location): bool
    {
        return $user->can(PanelPermission::DeleteLocations->value);
    }

    public function forceDelete(User $user, Location $location): bool
    {
        return $user->can(PanelPermission::DeleteLocations->value);
    }

    public function manageMedia(User $user, Location $location): bool
    {
        return $user->can(PanelPermission::ManageLocationMedia->value);
    }
}
