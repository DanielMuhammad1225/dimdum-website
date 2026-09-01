<?php

namespace App\Rules;

use App\Support\SafeUrl;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Aturan validasi link yang boleh diisi admin.
 *
 * Mode 'any'      : link internal ('/', '/path', '#anchor') atau https://
 * Mode 'external' : hanya https:// (dipakai untuk social media)
 *
 * Validasi ini berjalan di server. Filament juga membatasi input di browser,
 * tetapi pembatasan itu tidak dianggap sebagai lapisan keamanan.
 */
class SafeLink implements ValidationRule
{
    public function __construct(
        protected bool $externalOnly = false,
    ) {}

    public static function externalOnly(): self
    {
        return new self(externalOnly: true);
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

        $isValid = $this->externalOnly
            ? SafeUrl::isSafeExternal($value)
            : SafeUrl::isSafe($value);

        if ($isValid) {
            return;
        }

        $fail($this->externalOnly
            ? 'Tautan harus berupa URL lengkap yang diawali https://'
            : 'Tautan harus berupa anchor (#lokasi), path internal (/halaman), atau URL https://');
    }
}
