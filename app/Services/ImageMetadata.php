<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

/**
 * Membaca dimensi nyata gambar yang di-upload admin.
 *
 * Dimensi TIDAK disimpan di database: ia dibaca dari file lalu ikut tersimpan
 * di cache konten. Dengan begitu width/height pada <img> selalu cocok dengan
 * file yang benar-benar ada, sehingga tidak ada layout shift dan tidak ada
 * angka basi ketika file diganti.
 */
class ImageMetadata
{
    protected const MIME_BY_EXTENSION = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    /**
     * Ubah path relatif pada disk 'public' menjadi kontrak gambar yang
     * dipahami Blade: ['src', 'width', 'height', 'type', 'alt'].
     *
     * Mengembalikan null bila file tidak ada, bukan gambar, atau formatnya
     * di luar allowlist -- supaya homepage tidak pernah merujuk aset 404.
     *
     * @return array{src: string, width: int, height: int, type: string, alt: string}|null
     */
    public static function resolve(mixed $path, string $alt = ''): ?array
    {
        if (! is_string($path)) {
            return null;
        }

        $path = ltrim(trim($path), '/');

        if ($path === '' || str_contains($path, '..')) {
            return null;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (! isset(self::MIME_BY_EXTENSION[$extension])) {
            return null;
        }

        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            return null;
        }

        $size = @getimagesize($disk->path($path));

        if ($size === false || ! isset($size[0], $size[1]) || $size[0] < 1 || $size[1] < 1) {
            return null;
        }

        return [
            // Path publik relatif; asset() di Blade yang menambahkan host.
            'src' => 'storage/'.$path,
            'width' => (int) $size[0],
            'height' => (int) $size[1],
            'type' => self::MIME_BY_EXTENSION[$extension],
            'alt' => $alt,
        ];
    }
}
