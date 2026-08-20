<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Membuat (atau memperbarui) akun Super Admin DIMDUM secara interaktif.
 *
 * Password hanya diminta lewat prompt tersembunyi -- tidak pernah menjadi
 * argument command, tidak ditulis di source, seeder, maupun log.
 */
class CreateSuperAdminCommand extends Command
{
    protected $signature = 'dimdum:create-super-admin';

    protected $description = 'Membuat akun Super Admin DIMDUM secara interaktif';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->components->error(
                'Command ini harus dijalankan interaktif. Jalankan langsung di terminal, tanpa --no-interaction.'
            );

            return self::FAILURE;
        }

        if (! $this->superAdminRoleExists()) {
            $this->components->error(
                'Role [super_admin] belum ada. Jalankan dulu: php artisan db:seed --class=RolesAndPermissionsSeeder'
            );

            return self::FAILURE;
        }

        $email = $this->askEmail();

        if ($email === null) {
            return self::FAILURE;
        }

        $existing = User::where('email', $email)->first();

        return $existing
            ? $this->handleExistingUser($existing)
            : $this->handleNewUser($email);
    }

    protected function superAdminRoleExists(): bool
    {
        return Role::where('name', UserRole::SuperAdmin->value)
            ->where('guard_name', config('auth.defaults.guard'))
            ->exists();
    }

    /**
     * Minta email, validasi, dan normalisasi (trim + lowercase).
     */
    protected function askEmail(): ?string
    {
        $raw = text(
            label: 'Email Super Admin',
            placeholder: 'nama@domain.com',
            required: true,
        );

        $email = strtolower(trim($raw));

        $validator = Validator::make(
            ['email' => $email],
            ['email' => ['required', 'string', 'email:rfc', 'max:255']]
        );

        if ($validator->fails()) {
            $this->components->error($validator->errors()->first('email'));

            return null;
        }

        return $email;
    }

    /**
     * Minta password dua kali lewat prompt tersembunyi.
     */
    protected function askPassword(): ?string
    {
        $password = password(
            label: 'Password',
            required: true,
            validate: function (string $value): ?string {
                $validator = Validator::make(
                    ['password' => $value],
                    ['password' => ['required', 'string', Password::min(12)->letters()->numbers()->symbols()]]
                );

                return $validator->fails() ? $validator->errors()->first('password') : null;
            },
        );

        $confirmation = password(label: 'Konfirmasi password', required: true);

        if (! hash_equals($password, $confirmation)) {
            $this->components->error('Konfirmasi password tidak cocok. Tidak ada perubahan yang disimpan.');

            return null;
        }

        return $password;
    }

    protected function handleNewUser(string $email): int
    {
        $name = text(label: 'Nama lengkap', required: true);

        $password = $this->askPassword();

        if ($password === null) {
            return self::FAILURE;
        }

        $user = new User;
        $user->name = trim($name);
        $user->email = $email;
        $user->password = Hash::make($password);
        $user->is_active = true;
        $user->email_verified_at = now();
        $user->save();

        $user->assignRole(UserRole::SuperAdmin->value);

        $this->components->info("Super Admin dibuat: {$user->email}");
        $this->components->warn('Simpan password di password manager. Password tidak ditampilkan lagi.');

        return self::SUCCESS;
    }

    /**
     * Email sudah terdaftar: jangan duplikasi, tawarkan opsi yang aman.
     */
    protected function handleExistingUser(User $user): int
    {
        $this->components->warn("Email [{$user->email}] sudah terdaftar (nama: {$user->name}).");

        if (! confirm(label: 'Perbarui akun ini menjadi Super Admin aktif?', default: false)) {
            $this->components->info('Dibatalkan. Tidak ada perubahan.');

            return self::SUCCESS;
        }

        if (! $user->hasRole(UserRole::SuperAdmin->value)) {
            $user->assignRole(UserRole::SuperAdmin->value);
            $this->components->info('Role super_admin ditambahkan.');
        }

        if (! $user->is_active) {
            $user->is_active = true;
            $user->save();
            $this->components->info('Akun diaktifkan kembali.');
        }

        // Password lama tidak pernah diubah tanpa konfirmasi eksplisit.
        if (confirm(label: 'Ganti password akun ini juga?', default: false)) {
            $password = $this->askPassword();

            if ($password === null) {
                return self::FAILURE;
            }

            $user->password = Hash::make($password);
            $user->save();

            $this->components->info('Password diperbarui.');
        }

        $this->components->info("Selesai: {$user->email} sekarang Super Admin aktif.");

        return self::SUCCESS;
    }
}
