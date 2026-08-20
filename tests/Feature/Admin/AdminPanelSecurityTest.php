<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\HomeController;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminPanelSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    // ------------------------------------------------- homepage regression

    public function test_homepage_still_returns_200_and_is_not_a_filament_page(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Bebas Pilih, Jajan Sesukamu.', false);
        $response->assertSee('Dimsum mulai Rp1.000, bikin ketagihan.', false);

        $this->assertStringNotContainsStringIgnoringCase(
            'filament',
            $response->getContent(),
            'Homepage publik tidak boleh memuat aset atau markup Filament.'
        );
    }

    public function test_homepage_route_still_uses_the_home_controller(): void
    {
        $route = Route::getRoutes()->getByName('home');

        $this->assertNotNull($route);
        $this->assertSame('/', $route->uri());
        $this->assertStringContainsString(
            HomeController::class,
            $route->getActionName()
        );
    }

    public function test_homepage_is_reachable_without_authentication(): void
    {
        $this->get('/')->assertOk();
    }

    // ------------------------------------------------------- no registration

    public function test_there_is_no_public_admin_registration_route(): void
    {
        foreach (Route::getRoutes() as $route) {
            $this->assertStringNotContainsStringIgnoringCase(
                'register',
                $route->uri(),
                "Route registrasi publik tidak boleh ada: {$route->uri()}"
            );
        }
    }

    public function test_registration_url_returns_404(): void
    {
        $this->get('/admin/register')->assertNotFound();
    }

    public function test_password_reset_is_not_exposed_while_mail_is_unconfigured(): void
    {
        foreach (Route::getRoutes() as $route) {
            $this->assertStringNotContainsStringIgnoringCase('password-reset', $route->uri());
        }
    }

    public function test_no_default_or_seeded_accounts_exist(): void
    {
        $this->assertSame(
            0,
            User::count(),
            'Tidak boleh ada akun default/demo yang dibuat otomatis.'
        );
    }

    // -------------------------------------------------------------- noindex

    public function test_admin_login_page_is_not_indexable(): void
    {
        $response = $this->get('/admin/login');

        $response->assertOk();
        $response->assertSee('name="robots"', false);
        $response->assertSee('noindex, nofollow', false);
    }

    public function test_admin_dashboard_is_not_indexable(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(UserRole::SuperAdmin->value);

        $response = $this->actingAs($user->fresh())->get('/admin');

        $response->assertOk();
        $response->assertSee('noindex, nofollow', false);
    }

    public function test_homepage_is_still_indexable(): void
    {
        $this->assertStringNotContainsString(
            'noindex',
            $this->get('/')->getContent(),
            'Homepage publik tidak boleh ikut ter-noindex.'
        );
    }

    // --------------------------------------------------------------- logout

    public function test_logout_route_exists_and_requires_post(): void
    {
        $route = Route::getRoutes()->getByName('filament.admin.auth.logout');

        $this->assertNotNull($route, 'Route logout panel harus tersedia.');
        $this->assertContains('POST', $route->methods());
    }

    public function test_logout_invalidates_the_session(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(UserRole::SuperAdmin->value);

        $this->actingAs($user->fresh())->get('/admin')->assertOk();

        $this->post('/admin/logout');

        $this->assertGuest();
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    // ------------------------------------------------------- panel branding

    public function test_panel_uses_dimdum_branding_and_the_tagline_free_logo(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertSame('DIMDUM', $panel->getBrandName());
        $this->assertSame('admin', $panel->getId());
        $this->assertSame('admin', $panel->getPath());

        $response = $this->get('/admin/login');
        $response->assertSee('dimdum-logo-horizontal', false);
    }

    public function test_login_page_has_csrf_protection(): void
    {
        $this->get('/admin/login')->assertOk();

        $middleware = Filament::getPanel('admin')->getMiddleware();

        $this->assertContains(
            PreventRequestForgery::class,
            $middleware,
            'Panel harus memakai middleware CSRF.'
        );
    }
}
