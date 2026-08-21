<?php

namespace Tests\Feature\Cms;

use App\Enums\UserRole;
use App\Filament\Pages\ManageHomepage;
use App\Filament\Pages\ManageSiteSettings;
use App\Models\User;
use Database\Seeders\HomepageContentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Authorization kedua halaman CMS.
 *
 * Yang diuji bukan hanya "menu tidak terlihat", tetapi juga bahwa membuka
 * URL-nya secara langsung tetap ditolak 403.
 */
class CmsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected const HOMEPAGE_URL = '/admin/homepage';

    protected const SETTINGS_URL = '/admin/pengaturan-website';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(HomepageContentSeeder::class);
    }

    protected function user(?UserRole $role, bool $active = true): User
    {
        $user = User::factory()->create(['is_active' => $active]);

        if ($role !== null) {
            $user->assignRole($role->value);
        }

        return $user->fresh();
    }

    // ------------------------------------------------------------- guest

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(self::HOMEPAGE_URL)->assertRedirect('/admin/login');
        $this->get(self::SETTINGS_URL)->assertRedirect('/admin/login');
    }

    // ------------------------------------------------------- diizinkan

    public function test_active_super_admin_can_open_both_pages(): void
    {
        $user = $this->user(UserRole::SuperAdmin);

        $this->actingAs($user)->get(self::HOMEPAGE_URL)->assertOk();
        $this->actingAs($user)->get(self::SETTINGS_URL)->assertOk();

        $this->assertTrue(ManageHomepage::canAccess());
        $this->assertTrue(ManageSiteSettings::canAccess());
    }

    public function test_active_admin_can_open_both_pages(): void
    {
        $user = $this->user(UserRole::Admin);

        $this->actingAs($user)->get(self::HOMEPAGE_URL)->assertOk();
        $this->actingAs($user)->get(self::SETTINGS_URL)->assertOk();
    }

    // ---------------------------------------------------------- ditolak

    public function test_active_operator_is_forbidden_on_both_pages(): void
    {
        $user = $this->user(UserRole::Operator);

        // Operator tetap boleh masuk panel, tetapi bukan ke halaman konten.
        $this->actingAs($user)->get('/admin')->assertOk();

        $this->actingAs($user)->get(self::HOMEPAGE_URL)->assertForbidden();
        $this->actingAs($user)->get(self::SETTINGS_URL)->assertForbidden();
    }

    public function test_operator_does_not_see_the_navigation_items(): void
    {
        $user = $this->user(UserRole::Operator);

        $this->actingAs($user);

        $this->assertFalse(ManageHomepage::canAccess());
        $this->assertFalse(ManageSiteSettings::canAccess());

        $content = $this->actingAs($user)->get('/admin')->getContent();

        $this->assertStringNotContainsString(self::HOMEPAGE_URL, $content);
        $this->assertStringNotContainsString(self::SETTINGS_URL, $content);
        $this->assertStringNotContainsString('Konten Website', $content);
    }

    public function test_user_without_any_role_is_forbidden(): void
    {
        $user = $this->user(null);

        $this->actingAs($user)->get(self::HOMEPAGE_URL)->assertForbidden();
        $this->actingAs($user)->get(self::SETTINGS_URL)->assertForbidden();
    }

    /**
     * Regression fase 2A.1: akun nonaktif ditolak seluruh authorization,
     * termasuk halaman CMS, walaupun permission-nya masih ter-assign.
     */
    public function test_inactive_users_are_denied_for_every_role(): void
    {
        foreach (UserRole::cases() as $role) {
            $user = $this->user($role, active: false);

            $this->actingAs($user)->get(self::HOMEPAGE_URL)->assertForbidden();
            $this->actingAs($user)->get(self::SETTINGS_URL)->assertForbidden();

            $this->actingAs($user);

            $this->assertFalse(
                ManageHomepage::canAccess(),
                "Role {$role->value} nonaktif tidak boleh mengakses Homepage CMS."
            );
            $this->assertFalse(
                ManageSiteSettings::canAccess(),
                "Role {$role->value} nonaktif tidak boleh mengakses Pengaturan Website."
            );
        }
    }

    /**
     * Permission dicek pada halaman, bukan sekadar disembunyikan dari menu.
     */
    public function test_permission_is_enforced_on_the_page_itself(): void
    {
        $user = $this->user(UserRole::Operator);

        $this->actingAs($user);

        // Navigasi memakai canAccess() yang sama dengan penjaga 403,
        // sehingga tidak mungkin menu tersembunyi tapi URL-nya terbuka.
        $this->assertFalse(ManageHomepage::canAccess());
        $this->assertFalse(ManageHomepage::shouldRegisterNavigation() && ManageHomepage::canAccess());

        $this->actingAs($user)->get(self::HOMEPAGE_URL)->assertForbidden();
    }

    public function test_public_homepage_never_depends_on_an_admin_account(): void
    {
        $this->get('/')->assertOk();
        $this->assertGuest();
    }
}
