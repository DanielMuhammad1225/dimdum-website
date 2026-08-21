<?php

namespace Tests\Feature\Admin;

use App\Enums\PanelPermission;
use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Regression: akun nonaktif harus ditolak oleh SELURUH authorization.
 *
 * Celah yang ditutup: spatie/laravel-permission mendaftarkan Gate::before
 * miliknya lebih dulu dan mengembalikan true untuk permission yang masih
 * ter-assign, sehingga Gate berhenti sebelum sampai ke aturan aplikasi.
 * Akibatnya Super Admin nonaktif tetap lolos Gate::allows().
 */
class InactiveUserAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function userWithRole(UserRole $role, bool $active): User
    {
        $user = User::factory()->create(['is_active' => $active]);
        $user->assignRole($role->value);

        return $user->fresh();
    }

    protected function adminPanel(): Panel
    {
        return Filament::getPanel('admin');
    }

    // ------------------------------------------------ inactive: super admin

    public function test_inactive_super_admin_still_holds_permissions_in_the_database(): void
    {
        $user = $this->userWithRole(UserRole::SuperAdmin, active: false);

        // Assignment TIDAK dihapus -- yang berubah hanya hasil authorization.
        $this->assertCount(
            count(PanelPermission::cases()),
            $user->getAllPermissions(),
            'Permission eksplisit Super Admin tidak boleh dihapus.'
        );
    }

    public function test_inactive_super_admin_is_denied_every_ability(): void
    {
        $user = $this->userWithRole(UserRole::SuperAdmin, active: false);

        $this->assertFalse(Gate::forUser($user)->allows(PanelPermission::AccessAdminPanel->value));
        $this->assertFalse(Gate::forUser($user)->allows(PanelPermission::ManageHomepage->value));
        $this->assertFalse(Gate::forUser($user)->allows(PanelPermission::ManageSiteSettings->value));
        $this->assertFalse(Gate::forUser($user)->allows(PanelPermission::ManageUsers->value));
        $this->assertFalse(Gate::forUser($user)->allows(PanelPermission::ManageRoles->value));
    }

    public function test_inactive_super_admin_is_denied_via_user_can_as_well(): void
    {
        $user = $this->userWithRole(UserRole::SuperAdmin, active: false);

        foreach (PanelPermission::cases() as $permission) {
            $this->assertFalse(
                $user->can($permission->value),
                "Super Admin nonaktif tidak boleh lolos {$permission->value}."
            );
            $this->assertFalse(
                $user->hasPermissionTo($permission->value),
                "hasPermissionTo() harus false untuk akun nonaktif: {$permission->value}."
            );
        }
    }

    public function test_inactive_super_admin_is_denied_an_arbitrary_future_ability(): void
    {
        Gate::define('some_future_module', fn (): bool => true);

        $user = $this->userWithRole(UserRole::SuperAdmin, active: false);

        $this->assertFalse(
            Gate::forUser($user)->allows('some_future_module'),
            'Akun nonaktif harus ditolak bahkan untuk ability yang gate-nya mengizinkan.'
        );
    }

    // ------------------------------------------------------ inactive: admin

    public function test_inactive_admin_is_denied_despite_role_permissions(): void
    {
        $user = $this->userWithRole(UserRole::Admin, active: false);

        $this->assertFalse(Gate::forUser($user)->allows(PanelPermission::AccessAdminPanel->value));
        $this->assertFalse(Gate::forUser($user)->allows(PanelPermission::ManageHomepage->value));
        $this->assertFalse(Gate::forUser($user)->allows(PanelPermission::ManageSiteSettings->value));

        $this->assertFalse($user->canAccessPanel($this->adminPanel()));
        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    // --------------------------------------------------- inactive: operator

    public function test_inactive_operator_is_denied_despite_panel_permission(): void
    {
        $user = $this->userWithRole(UserRole::Operator, active: false);

        $this->assertFalse(Gate::forUser($user)->allows(PanelPermission::AccessAdminPanel->value));

        $this->assertFalse($user->canAccessPanel($this->adminPanel()));
        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_every_role_is_fully_denied_while_inactive(): void
    {
        foreach (UserRole::cases() as $role) {
            $user = $this->userWithRole($role, active: false);

            foreach (PanelPermission::cases() as $permission) {
                $this->assertFalse(
                    Gate::forUser($user)->allows($permission->value),
                    "Role {$role->value} nonaktif tidak boleh lolos {$permission->value}."
                );
            }

            $this->assertFalse(
                $user->canAccessPanel($this->adminPanel()),
                "Role {$role->value} nonaktif tidak boleh mengakses panel."
            );
        }
    }

    // ------------------------------------------------- no regression: active

    public function test_active_super_admin_keeps_every_ability(): void
    {
        $user = $this->userWithRole(UserRole::SuperAdmin, active: true);

        foreach (PanelPermission::cases() as $permission) {
            $this->assertTrue(Gate::forUser($user)->allows($permission->value));
        }

        Gate::define('another_future_module', fn (): bool => false);
        $this->assertTrue(
            Gate::forUser($user)->allows('another_future_module'),
            'Super Admin aktif tetap mendapat bypass penuh.'
        );

        $this->assertTrue($user->canAccessPanel($this->adminPanel()));
    }

    public function test_active_admin_keeps_exactly_its_permissions(): void
    {
        $user = $this->userWithRole(UserRole::Admin, active: true);

        $this->assertTrue(Gate::forUser($user)->allows(PanelPermission::AccessAdminPanel->value));
        $this->assertTrue(Gate::forUser($user)->allows(PanelPermission::ManageHomepage->value));
        $this->assertTrue(Gate::forUser($user)->allows(PanelPermission::ManageSiteSettings->value));

        $this->assertFalse(Gate::forUser($user)->allows(PanelPermission::ManageRoles->value));
        $this->assertFalse(Gate::forUser($user)->allows(PanelPermission::ManageUsers->value));

        $this->assertTrue($user->canAccessPanel($this->adminPanel()));
    }

    public function test_active_operator_keeps_only_panel_access(): void
    {
        $user = $this->userWithRole(UserRole::Operator, active: true);

        $this->assertTrue(Gate::forUser($user)->allows(PanelPermission::AccessAdminPanel->value));

        foreach ([
            PanelPermission::ManageHomepage,
            PanelPermission::ManageSiteSettings,
            PanelPermission::ManageUsers,
            PanelPermission::ManageRoles,
        ] as $permission) {
            $this->assertFalse(Gate::forUser($user)->allows($permission->value));
        }

        $this->assertTrue($user->canAccessPanel($this->adminPanel()));
    }

    public function test_active_user_without_permissions_is_still_denied(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach (PanelPermission::cases() as $permission) {
            $this->assertFalse(Gate::forUser($user)->allows($permission->value));
        }

        $this->assertFalse($user->canAccessPanel($this->adminPanel()));
    }

    // ------------------------------------- reactivation restores everything

    public function test_reactivating_an_account_restores_its_permissions(): void
    {
        $user = $this->userWithRole(UserRole::Admin, active: false);

        $this->assertFalse(Gate::forUser($user)->allows(PanelPermission::ManageHomepage->value));

        $user->is_active = true;
        $user->save();

        $this->assertTrue(
            Gate::forUser($user->fresh())->allows(PanelPermission::ManageHomepage->value),
            'Permission harus langsung berlaku lagi setelah akun diaktifkan, tanpa re-seed.'
        );
    }

    // ---------------------------------------------- public surface untouched

    public function test_homepage_and_guest_flow_are_unaffected(): void
    {
        $this->get('/')->assertOk();
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/login')->assertOk();
    }
}
