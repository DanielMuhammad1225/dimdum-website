<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Pembaca singleton pengaturan dengan cache dan fallback terkontrol.
 *
 * Kebijakan fallback -- sengaja sempit:
 *
 *   - Baris belum ada (seeder belum dijalankan) -> pakai config. Ini kondisi
 *     normal, bukan error.
 *   - Tabel belum ada (migration belum dijalankan) -> pakai config, TAPI
 *     dicatat sebagai warning supaya tidak diam-diam.
 *   - Error database lain (koneksi putus, kredensial salah, deadlock)
 *     -> exception DILEMPAR ULANG. Menelan error semacam ini akan membuat
 *        production tampak sehat padahal databasenya mati.
 */
abstract class SingletonSettingsRepository
{
    /**
     * SQLSTATE untuk "base table or view not found".
     */
    protected const MISSING_TABLE_SQLSTATE = '42S02';

    /**
     * Memo per-instance, supaya satu request tidak pernah mengulang query
     * yang sama -- termasuk ketika barisnya belum ada (hasil null).
     *
     * @var array<string, array<string, mixed>|null>
     */
    protected array $memo = [];

    /**
     * Atribut singleton (casts sudah diterapkan) atau null bila belum ada.
     *
     * @return array<string, mixed>|null
     */
    protected function cachedAttributes(string $cacheKey, string $modelClass, string $singletonKey): ?array
    {
        if (array_key_exists($cacheKey, $this->memo)) {
            return $this->memo[$cacheKey];
        }

        /*
         | Nilai null tidak pernah benar-benar tersimpan di cache, sehingga
         | begitu seeder dijalankan datanya langsung terbaca tanpa perlu
         | membersihkan cache secara manual. Memo di atas yang memastikan
         | kondisi itu tidak berubah jadi query berulang dalam satu request.
         */
        return $this->memo[$cacheKey] = Cache::rememberForever($cacheKey, function () use ($modelClass, $singletonKey): ?array {
            $record = $this->findRecord($modelClass, $singletonKey);

            // Model Eloquent mentah tidak disimpan di cache: hanya array biasa,
            // supaya tidak ada state/relasi tak terduga yang ikut ter-serialize.
            return $record?->toArray();
        });
    }

    /**
     * Baris singleton dari database, tanpa cache. Dipakai halaman admin.
     */
    protected function findRecord(string $modelClass, string $singletonKey): ?Model
    {
        try {
            /** @var class-string<Model> $modelClass */
            return $modelClass::query()->where('key', $singletonKey)->first();
        } catch (QueryException $exception) {
            if (! $this->isMissingTable($exception)) {
                throw $exception;
            }

            Log::warning('Tabel pengaturan belum tersedia, konten memakai fallback config.', [
                'model' => $modelClass,
                'key' => $singletonKey,
            ]);

            return null;
        }
    }

    protected function isMissingTable(QueryException $exception): bool
    {
        if (($exception->errorInfo[0] ?? null) === self::MISSING_TABLE_SQLSTATE) {
            return true;
        }

        // SQLite tidak memakai SQLSTATE 42S02 dan hanya menyebutkannya di pesan.
        return str_contains($exception->getMessage(), 'no such table');
    }

    /**
     * Ambil string non-kosong dari atribut database, atau null.
     */
    protected function stringValue(?array $attributes, string $key): ?string
    {
        $value = $attributes[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Array section dari kolom JSON, atau null bila kolomnya belum diisi.
     *
     * @return array<string, mixed>|null
     */
    protected function arrayValue(?array $attributes, string $key): ?array
    {
        $value = $attributes[$key] ?? null;

        return is_array($value) ? $value : null;
    }

    /**
     * Gabungkan nilai database di atas default config.
     *
     * Penggabungan sengaja DANGKAL (bukan array_replace_recursive): daftar
     * seperti 'items' atau 'steps' harus tergantikan utuh. Kalau digabung
     * rekursif, item yang dihapus admin akan muncul lagi dari config.
     *
     * @param  array<string, mixed>  $default
     * @param  array<string, mixed>|null  $stored
     * @param  list<string>  $keys  Key yang boleh diambil dari database.
     * @return array<string, mixed>
     */
    protected function mergeSection(array $default, ?array $stored, array $keys): array
    {
        if ($stored === null) {
            return $default;
        }

        $merged = $default;

        foreach ($keys as $key) {
            if (! array_key_exists($key, $stored)) {
                continue;
            }

            $value = $stored[$key];

            // null / string kosong berarti "belum diisi" -> pertahankan default.
            if ($value === null || (is_string($value) && trim($value) === '')) {
                continue;
            }

            $merged[$key] = is_string($value) ? trim($value) : $value;
        }

        return $merged;
    }
}
