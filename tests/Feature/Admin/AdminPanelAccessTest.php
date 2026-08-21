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

class AdminPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function userWithRole(UserRole $role, bool $active = true): User
    {
        $user = User::factory()->create(['is_active' => $active]);
        $user->assignRole($role->value);

        return $user->fresh();
    }

    // ---------------------------------------------------------------- guests

    public function test_guest_visiting_admin_is_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_login_page_is_reachable_for_guests(): void
    {
        $this->get('/admin/login')->assertOk();
    }

    // ----------------------------------------------------------- plain users

    public function test_user_without_any_role_is_denied(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->assertFalse($user->canAccessPanel($this->adminPanel()));

        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_user_without_panel_permission_is_denied(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(PanelPermission::ManageHomepage->value);

        $this->assertFalse($user->fresh()->canAccessPanel($this->adminPanel()));
    }

    // -------------------------------------------------------- inactive users

    public function test_inactive_operator_is_denied(): void
    {
        $user = $this->userWithRole(UserRole::Operator, active: false);

        $this->assertFalse($user->canAccessPanel($this->adminPanel()));

        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_inactive_super_admin_is_also_denied(): void
    {
        $user = $this->userWithRole(UserRole::SuperAdmin, active: false);

        $this->assertFalse(
            $user->canAccessPanel($this->adminPanel()),
            'Super Admin nonaktif tidak boleh bisa masuk panel.'
        );

        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_gate_before_only_fires_for_active_super_admins(): void
    {
        // Ability yang tidak punya record permission sama sekali: satu-satunya
        // cara lolos adalah lewat Gate::before, jadi ini menguji bypass-nya
        // secara langsung tanpa tercampur permission yang ter-assign.
        Gate::define('probe_future_ability', fn () => false);

        $active = $this->userWithRole(UserRole::SuperAdmin);
        $inactive = $this->userWithRole(UserRole::SuperAdmin, active: false);

        $this->assertTrue(
            $active->can('probe_future_ability'),
            'Super Admin aktif harus lolos lewat Gate::before.'
        );

        $this->assertFalse(
            $inactive->can('probe_future_ability'),
            'Gate::before tidak boleh memberi bypass kepada Super Admin nonaktif.'
        );
    }

    // -------------------------------------------------------------- operator

    public function test_active_operator_can_access_panel(): void
    {
        $user = $this->userWithRole(UserRole::Operator);

        $this->assertTrue($user->canAccessPanel($this->adminPanel()));

        $this->actingAs($user)->get('/admin')->assertOk();
    }

    public function test_operator_has_only_panel_access_permission(): void
    {
        $user = $this->userWithRole(UserRole::Operator);

        $this->assertTrue($user->can(PanelPermission::AccessAdminPanel->value));
        $this->assertFalse($user->can(PanelPermission::ManageHomepage->value));
        $this->assertFalse($user->can(PanelPermission::ManageSiteSettings->value));
        $this->assertFalse($user->can(PanelPermission::ManageUsers->value));
        $this->assertFalse($user->can(PanelPermission::ManageRoles->value));
    }

    // ----------------------------------------------------------------- admin

    public function test_active_admin_can_access_panel(): void
    {
        $user = $this->userWithRole(UserRole::Admin);

        $this->assertTrue($user->canAccessPanel($this->adminPanel()));

        $this->actingAs($user)->get('/admin')->assertOk();
    }

    public function test_admin_permissions_match_the_defined_split(): void
    {
        $user = $this->userWithRole(UserRole::Admin);

        $this->assertTrue($user->can(PanelPermission::AccessAdminPanel->value));
        $this->assertTrue($user->can(PanelPermission::ManageHomepage->value));
        $this->assertTrue($user->can(PanelPermission::ManageSiteSettings->value));

        $this->assertFalse(
            $user->can(PanelPermission::ManageRoles->value),
            'Admin belum boleh mengelola role.'
        );
        $this->assertFalse(
            $user->can(PanelPermission::ManageUsers->value),
            'manage_users belum diberikan ke Admin pada fase ini.'
        );
    }

    // ----------------------------------------------------------- super admin

    public function test_active_super_admin_can_access_panel(): void
    {
        $user = $this->userWithRole(UserRole::SuperAdmin);

        $this->assertTrue($user->canAccessPanel($this->adminPanel()));

        $this->actingAs($user)->get('/admin')->assertOk();
    }

    public function test_super_admin_has_every_permission(): void
    {
        $user = $this->userWithRole(UserRole::SuperAdmin);

        foreach (PanelPermission::cases() as $permission) {
            $this->assertTrue(
                $user->can($permission->value),
                "Super Admin seharusnya punya {$permission->value}."
            );
        }
    }

    public function test_super_admin_gate_is_role_based_not_email_based(): void
    {
        // Email apa pun, yang menentukan hanya role.
        $viaRole = User::factory()->create(['email' => 'siapa.saja@example.test', 'is_active' => true]);
        $viaRole->assignRole(UserRole::SuperAdmin->value);

        $this->assertTrue($viaRole->fresh()->can(PanelPermission::ManageRoles->value));

        // Tanpa role super_admin, email yang "terlihat penting" tetap ditolak.
        $lookalike = User::factory()->create(['email' => 'superadmin@dimdum.test', 'is_active' => true]);

        $this->assertFalse($lookalike->can(PanelPermission::ManageRoles->value));
        $this->assertFalse($lookalike->canAccessPanel($this->adminPanel()));
    }

    public function test_super_admin_bypass_does_not_leak_to_other_users(): void
    {
        $this->userWithRole(UserRole::SuperAdmin);

        $operator = $this->userWithRole(UserRole::Operator);

        $this->assertFalse($operator->can(PanelPermission::ManageRoles->value));
    }

    // ---------------------------------------------------------------- panel

    protected function adminPanel(): Panel
    {
        return Filament::getPanel('admin');
    }
}
