<?php

namespace Tests\Feature\Locations;

use App\Enums\PanelPermission;
use App\Enums\UserRole;
use App\Filament\Resources\LocationAreas\LocationAreaResource;
use App\Filament\Resources\Locations\LocationResource;
use App\Models\Location;
use App\Models\LocationArea;
use App\Models\LocationImage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Authorization modul lokasi.
 *
 * Yang diuji bukan hanya "menu tidak terlihat", tetapi juga bahwa membuka URL
 * admin secara langsung tetap ditolak.
 */
class LocationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function user(?UserRole $role, bool $active = true): User
    {
        $user = User::factory()->create(['is_active' => $active]);

        if ($role !== null) {
            $user->assignRole($role->value);
        }

        return $user->fresh();
    }

    // ------------------------------------------------------- pembagian role

    public function test_admin_holds_every_location_permission(): void
    {
        $admin = $this->user(UserRole::Admin);

        foreach (PanelPermission::locationCases() as $permission) {
            $this->assertTrue(
                Gate::forUser($admin)->allows($permission->value),
                "Admin seharusnya punya {$permission->value}."
            );
        }
    }

    public function test_super_admin_holds_every_location_permission(): void
    {
        $superAdmin = $this->user(UserRole::SuperAdmin);

        foreach (PanelPermission::locationCases() as $permission) {
            $this->assertTrue(Gate::forUser($superAdmin)->allows($permission->value));
        }
    }

    public function test_operator_holds_only_day_to_day_permissions(): void
    {
        $operator = $this->user(UserRole::Operator);

        foreach ([
            PanelPermission::ViewLocations,
            PanelPermission::CreateLocations,
            PanelPermission::UpdateLocations,
            PanelPermission::ManageLocationMedia,
        ] as $permission) {
            $this->assertTrue(
                Gate::forUser($operator)->allows($permission->value),
                "Operator seharusnya punya {$permission->value}."
            );
        }

        // Yang mengubah URL publik atau menghapus data tetap tertutup.
        foreach ([
            PanelPermission::ManageLocationAreas,
            PanelPermission::PublishLocations,
            PanelPermission::ChangeLocationSlugs,
            PanelPermission::DeleteLocations,
        ] as $permission) {
            $this->assertFalse(
                Gate::forUser($operator)->allows($permission->value),
                "Operator TIDAK boleh punya {$permission->value}."
            );
        }
    }

    public function test_a_user_without_a_role_holds_nothing(): void
    {
        $user = $this->user(null);

        foreach (PanelPermission::locationCases() as $permission) {
            $this->assertFalse(Gate::forUser($user)->allows($permission->value));
        }
    }

    /**
     * Regression fase 2A.1: akun nonaktif ditolak seluruh authorization,
     * termasuk permission lokasi yang masih ter-assign di database.
     */
    public function test_inactive_users_are_denied_for_every_role(): void
    {
        foreach (UserRole::cases() as $role) {
            $user = $this->user($role, active: false);

            foreach (PanelPermission::locationCases() as $permission) {
                $this->assertFalse(
                    Gate::forUser($user)->allows($permission->value),
                    "Role {$role->value} nonaktif tidak boleh lolos {$permission->value}."
                );
            }
        }
    }

    // ------------------------------------------------------- policy langsung

    public function test_an_area_cannot_be_deleted_while_it_has_locations(): void
    {
        $admin = $this->user(UserRole::Admin);

        $area = LocationArea::factory()->create();
        Location::factory()->for($area, 'area')->create();

        $this->assertFalse(
            Gate::forUser($admin)->allows('delete', $area),
            'Wilayah dengan gerobak tidak boleh dihapus.'
        );

        $area->locations()->forceDelete();

        $this->assertTrue(Gate::forUser($admin->fresh())->allows('delete', $area->fresh()));
    }

    public function test_operator_cannot_delete_publish_or_change_slugs(): void
    {
        $operator = $this->user(UserRole::Operator);

        $area = LocationArea::factory()->create();
        $location = Location::factory()->for($area, 'area')->create();
        $image = LocationImage::factory()->for($location)->create();

        $this->assertFalse(Gate::forUser($operator)->allows('delete', $area));
        $this->assertFalse(Gate::forUser($operator)->allows('create', LocationArea::class));
        $this->assertFalse(Gate::forUser($operator)->allows('update', $area));
        $this->assertFalse(Gate::forUser($operator)->allows('delete', $location));
        $this->assertFalse(Gate::forUser($operator)->allows('restore', $location));
        $this->assertFalse(Gate::forUser($operator)->allows('forceDelete', $location));
        $this->assertFalse(Gate::forUser($operator)->allows('publish', $location));
        $this->assertFalse(Gate::forUser($operator)->allows('changeSlug', $location));
        $this->assertFalse(Gate::forUser($operator)->allows('forceDelete', $image));

        // Tetapi pekerjaan hariannya tetap bisa dijalankan.
        $this->assertTrue(Gate::forUser($operator)->allows('view', $location));
        $this->assertTrue(Gate::forUser($operator)->allows('create', Location::class));
        $this->assertTrue(Gate::forUser($operator)->allows('update', $location));
        $this->assertTrue(Gate::forUser($operator)->allows('update', $image));
    }

    // ------------------------------------------------------------ URL admin

    /**
     * @return array<string, array{string}>
     */
    public static function areaUrlProvider(): array
    {
        return [
            'daftar wilayah' => ['/admin/wilayah-landing'],
            'tambah wilayah' => ['/admin/wilayah-landing/create'],
        ];
    }

    #[DataProvider('areaUrlProvider')]
    public function test_guests_are_redirected_from_admin_urls(string $url): void
    {
        $this->get($url)->assertRedirect('/admin/login');
    }

    public function test_admin_and_super_admin_can_open_both_resources(): void
    {
        foreach ([UserRole::SuperAdmin, UserRole::Admin] as $role) {
            $user = $this->user($role);

            $this->actingAs($user)->get('/admin/wilayah-landing')->assertOk();
            $this->actingAs($user)->get('/admin/gerobak')->assertOk();
            $this->actingAs($user)->get('/admin/gerobak/create')->assertOk();
        }
    }

    public function test_operator_can_list_and_create_gerobak_but_not_areas(): void
    {
        $operator = $this->user(UserRole::Operator);

        // Operator memang perlu melihat daftar wilayah untuk memilih induk.
        $this->actingAs($operator)->get('/admin/wilayah-landing')->assertOk();
        $this->actingAs($operator)->get('/admin/gerobak')->assertOk();
        $this->actingAs($operator)->get('/admin/gerobak/create')->assertOk();

        // Tetapi tidak boleh membuat atau mengubah wilayah.
        $this->actingAs($operator)->get('/admin/wilayah-landing/create')->assertForbidden();

        $area = LocationArea::factory()->create();
        $this->actingAs($operator)->get("/admin/wilayah-landing/{$area->id}/edit")->assertForbidden();
    }

    public function test_a_user_without_a_role_is_forbidden_everywhere(): void
    {
        $user = $this->user(null);

        foreach (['/admin/wilayah-landing', '/admin/gerobak', '/admin/gerobak/create'] as $url) {
            $this->actingAs($user)->get($url)->assertForbidden();
        }
    }

    public function test_inactive_users_are_forbidden_on_direct_admin_urls(): void
    {
        foreach (UserRole::cases() as $role) {
            $user = $this->user($role, active: false);

            foreach (['/admin/wilayah-landing', '/admin/gerobak', '/admin/gerobak/create'] as $url) {
                $this->actingAs($user)->get($url)->assertForbidden();
            }
        }
    }

    public function test_navigation_visibility_uses_the_same_check_as_access(): void
    {
        $operator = $this->user(UserRole::Operator);
        $this->actingAs($operator);

        // canAccess() dipakai Filament untuk navigasi DAN untuk penjaga 403,
        // jadi keduanya tidak mungkin berbeda.
        $this->assertTrue(LocationAreaResource::canAccess());
        $this->assertTrue(LocationResource::canAccess());

        $noRole = $this->user(null);
        $this->actingAs($noRole);

        $this->assertFalse(LocationAreaResource::canAccess());
        $this->assertFalse(LocationResource::canAccess());
    }

    public function test_seeding_permissions_again_is_idempotent(): void
    {
        $before = [
            'permissions' => Permission::count(),
            'roles' => Role::count(),
            'users' => User::count(),
        ];

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame($before['permissions'], Permission::count());
        $this->assertSame($before['roles'], Role::count());
        $this->assertSame($before['users'], User::count());
    }

    public function test_the_seeder_keeps_manually_granted_permissions(): void
    {
        $role = Role::findByName(UserRole::Operator->value);

        // Permission tambahan yang diberikan manual harus bertahan.
        $role->givePermissionTo(PanelPermission::PublishLocations->value);

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertTrue(
            $role->fresh()->hasPermissionTo(PanelPermission::PublishLocations->value),
            'Seeder tidak boleh mencabut permission yang diberikan manual.'
        );
    }
}
