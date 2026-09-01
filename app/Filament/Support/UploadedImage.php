<?php

namespace App\Filament\Support;

use Filament\Forms\Components\FileUpload;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Pabrik komponen upload gambar untuk admin panel DIMDUM.
 *
 * Aturan keamanan dipusatkan di sini supaya setiap halaman admin memakai
 * pembatasan yang sama:
 *
 *   - Hanya JPG/JPEG/PNG/WebP. SVG DITOLAK karena bisa memuat <script>.
 *   - Maksimal 3 MB.
 *   - Nama file akhir SELALU dibuat ulang (ULID) dan ekstensinya diturunkan
 *     dari MIME type hasil deteksi server, bukan dari nama file kiriman.
 *     Nama asli tidak pernah dipakai sebagai path.
 *   - Disimpan pada disk 'public' sebagai path RELATIF, bukan URL absolut
 *     dan bukan path filesystem.
 *   - storage/app/private tidak pernah disentuh.
 */
class UploadedImage
{
    public const MAX_SIZE_KB = 3072;

    public const DISK = 'public';

    public const HERO_DIRECTORY = 'homepage/hero';

    public const OG_DIRECTORY = 'site/og';

    /**
     * MIME type yang diterima, sekaligus ekstensi resmi untuk masing-masing.
     */
    protected const EXTENSION_BY_MIME = [
        'image/jpeg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * Direktori yang boleh dikelola (dibuat dan dibersihkan) oleh admin panel.
     *
     * @var list<string>
     */
    protected const MANAGED_DIRECTORIES = [
        self::HERO_DIRECTORY,
        self::OG_DIRECTORY,
    ];

    public static function make(string $name, string $directory): FileUpload
    {
        return FileUpload::make($name)
            ->disk(self::DISK)
            ->directory($directory)
            ->visibility('public')
            ->image()
            // Allowlist MIME. SVG sengaja tidak ada di daftar ini.
            ->acceptedFileTypes(array_keys(self::EXTENSION_BY_MIME))
            ->maxSize(self::MAX_SIZE_KB)
            // preserveFilenames() TIDAK dipakai: nama kiriman tidak pernah
            // menjadi nama file di disk.
            ->getUploadedFileNameForStorageUsing(
                static fn (TemporaryUploadedFile $file): string => self::safeFileName($file),
            )
            ->openable()
            ->downloadable(false);
    }

    public static function hero(): FileUpload
    {
        return self::make('image.path', self::HERO_DIRECTORY)
            ->label('Foto hero')
            ->helperText('JPG, PNG, atau WebP. Maksimal 3 MB. Kosongkan untuk memakai lockup logo DIMDUM.')
            ->imageEditor()
            ->imageEditorAspectRatios(['4:3', '3:2', '16:9']);
    }

    public static function ogImage(): FileUpload
    {
        return self::make('default_og_image_path', self::OG_DIRECTORY)
            ->label('OG image default')
            ->helperText('Gambar yang tampil saat tautan dibagikan. Ukuran ideal 1200x630 px. JPG, PNG, atau WebP, maksimal 3 MB.')
            ->imageEditor()
            ->imageEditorAspectRatios(['1200:630']);
    }

    /**
     * Nama file akhir: ULID + ekstensi dari MIME hasil deteksi server.
     *
     * Bila MIME-nya di luar allowlist, ekstensi 'bin' dipakai supaya file
     * tersebut tidak pernah bisa dieksekusi maupun dirender sebagai gambar.
     * Validasi acceptedFileTypes() sudah menolak lebih dulu; ini lapis kedua.
     */
    public static function safeFileName(TemporaryUploadedFile $file): string
    {
        $mime = strtolower((string) $file->getMimeType());

        return Str::ulid().'.'.(self::EXTENSION_BY_MIME[$mime] ?? 'bin');
    }

    /**
     * Hapus gambar lama SETELAH gambar baru dan barisnya berhasil disimpan.
     *
     * Penjagaan:
     *   - Tidak melakukan apa pun bila path lama kosong atau tidak berubah.
     *   - Hanya menghapus file di dalam direktori yang dikelola admin panel,
     *     sehingga aset fallback di public/images/brand -- yang berada di luar
     *     disk 'public' -- tidak pernah bisa ikut terhapus.
     */
    public static function deleteReplaced(?string $previousPath, ?string $currentPath): void
    {
        $previousPath = is_string($previousPath) ? trim($previousPath) : '';

        if ($previousPath === '' || $previousPath === $currentPath) {
            return;
        }

        if (! self::isManagedPath($previousPath)) {
            return;
        }

        $disk = Storage::disk(self::DISK);

        if ($disk->exists($previousPath)) {
            $disk->delete($previousPath);
        }
    }

    public static function isManagedPath(string $path): bool
    {
        $path = ltrim($path, '/');

        if ($path === '' || str_contains($path, '..')) {
            return false;
        }

        foreach (self::MANAGED_DIRECTORIES as $directory) {
            if (str_starts_with($path, $directory.'/')) {
                return true;
            }
        }

        return false;
    }
}
