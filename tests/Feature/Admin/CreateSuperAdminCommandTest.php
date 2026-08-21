<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class CreateSuperAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_it_refuses_to_run_non_interactively(): void
    {
        // $this->artisan() selalu interaktif, jadi command dijalankan langsung
        // dengan input non-interaktif supaya guard-nya benar-benar teruji.
        $command = $this->app->make(Kernel::class)
            ->all()['dimdum:create-super-admin'];

        $input = new ArrayInput([]);
        $input->setInteractive(false);

        $output = new BufferedOutput;

        $status = $command->run($input, $output);

        $this->assertSame(
            Command::FAILURE,
            $status,
            'Command harus gagal bila dijalankan tanpa mode interaktif.'
        );

        $this->assertSame(0, User::count(), 'Tidak boleh ada user yang dibuat.');
    }

    public function test_it_does_not_accept_a_password_as_an_argument(): void
    {
        $definition = $this->app->make(Kernel::class)
            ->all()['dimdum:create-super-admin']
            ->getDefinition();

        $this->assertSame(
            [],
            array_keys($definition->getArguments()),
            'Command tidak boleh menerima argument apa pun (termasuk password).'
        );

        foreach (array_keys($definition->getOptions()) as $option) {
            $this->assertStringNotContainsStringIgnoringCase('password', $option);
        }
    }

    public function test_the_command_source_contains_no_hardcoded_credentials(): void
    {
        $source = file_get_contents(app_path('Console/Commands/CreateSuperAdminCommand.php'));

        foreach (['password123', 'secret', 'admin@dimdum', 'Hash::make(\''] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $source,
                "Command tidak boleh memuat credential hardcoded: {$needle}"
            );
        }
    }

    public function test_it_creates_an_active_super_admin_with_a_hashed_password(): void
    {
        $plain = 'Str0ng-Passw0rd!x';

        $this->artisan('dimdum:create-super-admin')
            ->expectsQuestion('Email Super Admin', '  New.Admin@DIMDUM.test  ')
            ->expectsQuestion('Nama lengkap', 'Admin Utama')
            ->expectsQuestion('Password', $plain)
            ->expectsQuestion('Konfirmasi password', $plain)
            ->assertSuccessful();

        // Email dinormalisasi: trim + lowercase.
        $user = User::where('email', 'new.admin@dimdum.test')->first();

        $this->assertNotNull($user, 'User Super Admin harus dibuat.');
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->hasRole(UserRole::SuperAdmin->value));

        $this->assertNotSame($plain, $user->password, 'Password tidak boleh disimpan sebagai plain text.');
        $this->assertTrue(Hash::check($plain, $user->password), 'Password harus tersimpan sebagai hash.');
    }

    public function test_it_rejects_an_invalid_email(): void
    {
        $this->artisan('dimdum:create-super-admin')
            ->expectsQuestion('Email Super Admin', 'bukan-email')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_it_rejects_mismatched_password_confirmation(): void
    {
        $this->artisan('dimdum:create-super-admin')
            ->expectsQuestion('Email Super Admin', 'admin@dimdum.test')
            ->expectsQuestion('Nama lengkap', 'Admin Utama')
            ->expectsQuestion('Password', 'Str0ng-Passw0rd!x')
            ->expectsQuestion('Konfirmasi password', 'Beda-Passw0rd!x')
            ->assertFailed();

        $this->assertSame(0, User::count(), 'User tidak boleh dibuat jika konfirmasi gagal.');
    }

    public function test_it_does_not_duplicate_an_existing_email(): void
    {
        $existing = User::factory()->create(['email' => 'sudah.ada@dimdum.test']);
        $originalPassword = $existing->password;

        $this->artisan('dimdum:create-super-admin')
            ->expectsQuestion('Email Super Admin', 'sudah.ada@dimdum.test')
            ->expectsConfirmation('Perbarui akun ini menjadi Super Admin aktif?', 'yes')
            ->expectsConfirmation('Ganti password akun ini juga?', 'no')
            ->assertSuccessful();

        $this->assertSame(1, User::where('email', 'sudah.ada@dimdum.test')->count(), 'Email tidak boleh terduplikasi.');

        $existing->refresh();
        $this->assertTrue($existing->hasRole(UserRole::SuperAdmin->value));
        $this->assertTrue($existing->is_active);
        $this->assertSame($originalPassword, $existing->password, 'Password lama tidak boleh berubah tanpa konfirmasi.');
    }

    public function test_it_can_be_cancelled_for_an_existing_email(): void
    {
        $existing = User::factory()->create(['email' => 'sudah.ada@dimdum.test']);

        $this->artisan('dimdum:create-super-admin')
            ->expectsQuestion('Email Super Admin', 'sudah.ada@dimdum.test')
            ->expectsConfirmation('Perbarui akun ini menjadi Super Admin aktif?', 'no')
            ->assertSuccessful();

        $this->assertFalse($existing->fresh()->hasRole(UserRole::SuperAdmin->value));
    }

    public function test_it_is_safe_to_run_twice(): void
    {
        $plain = 'Str0ng-Passw0rd!x';

        $this->artisan('dimdum:create-super-admin')
            ->expectsQuestion('Email Super Admin', 'admin@dimdum.test')
            ->expectsQuestion('Nama lengkap', 'Admin Utama')
            ->expectsQuestion('Password', $plain)
            ->expectsQuestion('Konfirmasi password', $plain)
            ->assertSuccessful();

        $this->artisan('dimdum:create-super-admin')
            ->expectsQuestion('Email Super Admin', 'admin@dimdum.test')
            ->expectsConfirmation('Perbarui akun ini menjadi Super Admin aktif?', 'yes')
            ->expectsConfirmation('Ganti password akun ini juga?', 'no')
            ->assertSuccessful();

        $this->assertSame(1, User::count());
        $this->assertSame(1, User::where('email', 'admin@dimdum.test')->count());
    }
}
