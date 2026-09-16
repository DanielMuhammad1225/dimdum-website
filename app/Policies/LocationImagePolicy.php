<?php

namespace App\Policies;

use App\Enums\PanelPermission;
use App\Models\LocationImage;
use App\Models\User;

/**
 * Authorization foto gerobak.
 *
 * Operator boleh mengelola foto karena itu bagian pekerjaan lapangan,
 * tetapi tetap tidak boleh menghapus gerobak atau mengubah URL publik.
 */
class LocationImagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PanelPermission::ViewLocations->value);
    }

    public function view(User $user, LocationImage $image): bool
    {
        return $user->can(PanelPermission::ViewLocations->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PanelPermission::ManageLocationMedia->value);
    }

    public function update(User $user, LocationImage $image): bool
    {
        return $user->can(PanelPermission::ManageLocationMedia->value);
    }

    public function delete(User $user, LocationImage $image): bool
    {
        return $user->can(PanelPermission::ManageLocationMedia->value);
    }

    public function restore(User $user, LocationImage $image): bool
    {
        return $user->can(PanelPermission::ManageLocationMedia->value);
    }

    public function forceDelete(User $user, LocationImage $image): bool
    {
        return $user->can(PanelPermission::DeleteLocations->value);
    }
}
