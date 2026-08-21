<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Http\Middleware\SetAdminPanelLocale;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * Locale admin panel memakai terjemahan resmi bawaan Filament dan hanya
 * berlaku pada request panel -- halaman publik tidak ikut berubah.
 */
class AdminPanelLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_locale_middleware_is_registered_on_the_panel(): void
    {
        $this->assertContains(
            SetAdminPanelLocale::class,
            Filament::getPanel('admin')->getMiddleware(),
            'Middleware locale harus terpasang di panel admin.'
        );
    }

    public function test_login_page_is_rendered_in_indonesian(): void
    {
        $response = $this->get('/admin/login');

        $response->assertOk();
        $response->assertSee('Masuk', false);
        $response->assertSee('Ingat saya', false);
        $response->assertSee('Kata sandi', false);
        $response->assertSee('Alamat email', false);
    }

    public function test_login_page_no_longer_shows_english_labels(): void
    {
        $content = $this->get('/admin/login')->getContent();

        foreach (['Sign in', 'Remember me', 'Email address'] as $english) {
            $this->assertStringNotContainsString(
                $english,
                $content,
                "Label bahasa Inggris masih muncul: {$english}"
            );
        }
    }

    public function test_panel_request_sets_the_indonesian_locale(): void
    {
        $this->get('/admin/login');

        $this->assertSame('id', App::getLocale());
    }

    public function test_public_homepage_keeps_the_application_locale(): void
    {
        $appLocale = config('app.locale');

        $response = $this->get('/');

        $response->assertOk();

        $this->assertSame(
            $appLocale,
            App::getLocale(),
            'Locale aplikasi tidak boleh diubah oleh request halaman publik.'
        );
    }

    public function test_homepage_copy_is_unchanged(): void
    {
        $this->get('/')
            ->assertSee('Bebas Pilih, Jajan Sesukamu.', false)
            ->assertSee('Dimsum mulai Rp1.000, bikin ketagihan.', false)
            ->assertSee('Pilih yang Kamu Suka');
    }

    public function test_dashboard_is_rendered_in_indonesian_for_an_admin(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(UserRole::SuperAdmin->value);

        $response = $this->actingAs($user->fresh())->get('/admin');

        $response->assertOk();
        // Label logout bawaan Filament dalam Bahasa Indonesia.
        $response->assertSee('Keluar', false);
    }

    public function test_locale_is_configurable_and_not_hardcoded(): void
    {
        $this->assertSame('id', config('dimdum.admin.locale'));

        config(['dimdum.admin.locale' => null]);

        // Bernilai null berarti mengikuti APP_LOCALE, bukan memaksa 'id'.
        $this->get('/admin/login')->assertOk();

        $this->assertSame(config('app.locale'), App::getLocale());
    }
}
