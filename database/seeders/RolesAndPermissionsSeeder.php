<?php

namespace Database\Seeders;

use App\Enums\PanelPermission;
use App\Enums\UserRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeder role & permission DIMDUM.
 *
 * Sifat: idempotent dan aditif.
 *  - Aman dijalankan berulang kali.
 *  - Tidak membuat user, password, atau data contoh.
 *  - Tidak menghapus role, permission, maupun assignment yang sudah ada.
 *
 * Pemberian permission memakai givePermissionTo(), bukan syncPermissions(),
 * supaya permission yang sudah diberikan manual ke sebuah role tidak ikut
 * terhapus saat seeder dijalankan ulang.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = config('auth.defaults.guard');

        foreach (PanelPermission::cases() as $permission) {
            Permission::findOrCreate($permission->value, $guard);
        }

        foreach (UserRole::cases() as $role) {
            $model = Role::findOrCreate($role->value, $guard);

            foreach ($role->defaultPermissions() as $permission) {
                if (! $model->hasPermissionTo($permission->value)) {
                    $model->givePermissionTo($permission->value);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
