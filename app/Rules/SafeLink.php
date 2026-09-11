<?php

namespace App\Rules;

use App\Support\SafeUrl;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Aturan validasi link yang boleh diisi admin.
 *
 * Mode 'any'      : link internal ('/', '/path', '#anchor') atau https://
 * Mode 'external' : hanya https:// (Pengaturan Website)
 * Mode 'web'      : http:// atau https:// (tautan social media Bio)
 *
 * Validasi ini berjalan di server. Filament juga membatasi input di browser,
 * tetapi pembatasan itu tidak dianggap sebagai lapisan keamanan.
 */
class SafeLink implements ValidationRule
{
    protected const MODE_ANY = 'any';

    protected const MODE_EXTERNAL = 'external';

    protected const MODE_WEB = 'web';

    protected string $mode;

    public function __construct(
        bool $externalOnly = false,
        ?string $mode = null,
    ) {
        $this->mode = $mode ?? ($externalOnly ? self::MODE_EXTERNAL : self::MODE_ANY);
    }

    public static function externalOnly(): self
    {
        return new self(externalOnly: true);
    }

    public static function web(): self
    {
        return new self(mode: self::MODE_WEB);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            $fail('Tautan tidak valid.');

            return;
        }

        $isValid = match ($this->mode) {
            self::MODE_EXTERNAL => SafeUrl::isSafeExternal($value),
            self::MODE_WEB => SafeUrl::isSafeWeb($value),
            default => SafeUrl::isSafe($value),
        };

        if ($isValid) {
            return;
        }

        $fail(match ($this->mode) {
            self::MODE_EXTERNAL => 'Tautan harus berupa URL lengkap yang diawali https://',
            self::MODE_WEB => 'Tautan harus berupa URL lengkap yang diawali http:// atau https://, tanpa nama pengguna, spasi, maupun garis miring terbalik.',
            default => 'Tautan harus berupa anchor (#lokasi), path internal (/halaman), atau URL https://',
        });
    }
}
