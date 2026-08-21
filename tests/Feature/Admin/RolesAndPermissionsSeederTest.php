<?php

namespace Tests\Feature\Admin;

use App\Enums\PanelPermission;
use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolesAndPermissionsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_every_role_and_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        foreach (UserRole::cases() as $role) {
            $this->assertDatabaseHas('roles', [
                'name' => $role->value,
                'guard_name' => config('auth.defaults.guard'),
            ]);
        }

        foreach (PanelPermission::cases() as $permission) {
            $this->assertDatabaseHas('permissions', [
                'name' => $permission->value,
                'guard_name' => config('auth.defaults.guard'),
            ]);
        }

        $this->assertSame(count(UserRole::cases()), Role::count());
        $this->assertSame(count(PanelPermission::cases()), Permission::count());
    }

    public function test_it_is_idempotent(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(count(UserRole::cases()), Role::count(), 'Role tidak boleh terduplikasi.');
        $this->assertSame(count(PanelPermission::cases()), Permission::count(), 'Permission tidak boleh terduplikasi.');

        $admin = Role::findByName(UserRole::Admin->value);
        $this->assertCount(3, $admin->permissions, 'Permission role tidak boleh terduplikasi.');
    }

    public function test_it_never_removes_existing_role_assignments(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create();
        $user->assignRole(UserRole::Operator->value);

        // Permission tambahan yang diberikan manual di luar seeder.
        $operator = Role::findByName(UserRole::Operator->value);
        $operator->givePermissionTo(PanelPermission::ManageHomepage->value);

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertTrue(
            $user->fresh()->hasRole(UserRole::Operator->value),
            'Assignment role user tidak boleh hilang setelah re-seed.'
        );

        $this->assertTrue(
            Role::findByName(UserRole::Operator->value)->hasPermissionTo(PanelPermission::ManageHomepage->value),
            'Permission yang diberikan manual tidak boleh dihapus seeder.'
        );
    }

    public function test_it_does_not_create_any_user(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(0, User::count(), 'Seeder tidak boleh membuat user/akun default.');
    }

    public function test_role_permission_split_matches_the_enum_definition(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        foreach (UserRole::cases() as $role) {
            $expected = collect($role->defaultPermissions())
                ->map(fn (PanelPermission $p): string => $p->value)
                ->sort()
                ->values()
                ->all();

            $actual = Role::findByName($role->value)
                ->permissions
                ->pluck('name')
                ->sort()
                ->values()
                ->all();

            $this->assertSame($expected, $actual, "Permission role {$role->value} tidak sesuai definisi enum.");
        }
    }
}
