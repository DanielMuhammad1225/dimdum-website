<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Session admin harus bertahan melintasi request.
 *
 * PENTING: test ini sengaja memakai driver session DATABASE, bukan `array`
 * seperti sisa suite. phpunit.xml menyetel SESSION_DRIVER=array, sehingga
 * session hidup di memori satu request dan seluruh masalah persistensi
 * session menjadi tidak terlihat -- persis celah yang membuat bug logout
 * lolos dari 461 test sebelumnya.
 *
 * Aplikasi nyata memakai driver `database`, jadi di sinilah perilakunya
 * diuji.
 */
class AdminSessionPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function superAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(UserRole::SuperAdmin->value);

        return $user;
    }

    public function test_the_session_survives_a_sequence_of_admin_page_loads(): void
    {
        $user = $this->superAdmin();

        $this->actingAs($user);

        foreach ([
            '/admin',
            '/admin/provinsi',
            '/admin/provinsi/create',
            '/admin/provinsi',
            '/admin/kota-grup',
            '/admin/area',
            '/admin/gerobak',
        ] as $url) {
            $response = $this->get($url);

            $this->assertNotSame(
                302,
                $response->getStatusCode(),
                "Request ke {$url} dialihkan -- kemungkinan session hilang."
            );

            $this->assertTrue(
                auth()->check(),
                "User kehilangan autentikasi setelah membuka {$url}."
            );
        }
    }

    /**
     * Logout hanya boleh terjadi lewat POST yang disengaja.
     *
     * Selama route logout tidak menerima GET, tidak ada navigasi biasa --
     * termasuk redirect yang salah arah -- yang bisa mengakhiri session.
     */
    public function test_the_logout_route_cannot_be_triggered_by_navigation(): void
    {
        $route = Route::getRoutes()->getByName('filament.admin.auth.logout');

        $this->assertNotNull($route, 'Route logout panel tidak ditemukan.');
        $this->assertSame(['POST'], array_values(array_diff($route->methods(), ['HEAD'])));

        $this->actingAs($this->superAdmin())->get('/admin/provinsi/create')->assertOk();

        $this->assertTrue(auth()->check());
    }
}
