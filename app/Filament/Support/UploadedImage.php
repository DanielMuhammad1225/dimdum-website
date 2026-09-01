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
     * Akar direktori foto gerobak. Setiap gerobak mendapat subfolder sendiri
     * (locations/{ulid}/) supaya penghapusan tidak pernah menyentuh data
     * gerobak lain.
     */
    public const LOCATION_ROOT_DIRECTORY = 'locations';

    /** Poster Halaman Slug Lokasi. */
    public const LOCATION_PAGE_DIRECTORY = 'location-pages';

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
        self::LOCATION_ROOT_DIRECTORY,
        self::LOCATION_PAGE_DIRECTORY,
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
     * Poster Halaman Slug Lokasi.
     *
     * Aturannya sama dengan upload lain: allowlist MIME tanpa SVG, maksimal
     * 3 MB, nama file acak, dan tipe ditentukan dari isi berkas.
     */
    public static function locationPagePoster(string $name = 'poster_path'): FileUpload
    {
        return self::make($name, self::LOCATION_PAGE_DIRECTORY)
            ->label('Poster halaman')
            ->helperText('JPG, PNG, atau WebP. Maksimal 3 MB. Tampil sebagai gambar utama halaman dan pratinjau saat dibagikan.')
            ->imageEditor()
            ->imageEditorAspectRatios([null, '4:5', '1:1', '16:9']);
    }

    /**
     * Komponen upload untuk galeri satu gerobak.
     *
     * Direktori per gerobak dibuat dari ULID, bukan dari nama atau slug yang
     * bisa diubah admin, sehingga path tidak pernah berpindah.
     */
    public static function locationGallery(string $name, string $directory): FileUpload
    {
        return self::make($name, $directory)
            ->label('Foto gerobak')
            ->helperText('JPG, PNG, atau WebP. Maksimal 3 MB per foto.')
            // TIDAK memakai imageEditor: foto gerobak jangan dipotong.
            ->imagePreviewHeight('120');
    }

    /**
     * Direktori penyimpanan foto untuk satu gerobak.
     */
    public static function locationDirectory(string $token): string
    {
        $token = preg_replace('/[^A-Za-z0-9]/', '', $token) ?? '';

        if ($token === '') {
            $token = (string) Str::ulid();
        }

        return self::LOCATION_ROOT_DIRECTORY.'/'.$token;
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
        return Str::ulid().'.'.self::extensionFor($file);
    }

    /**
     * Ekstensi yang benar-benar cocok dengan ISI berkas.
     *
     * getimagesize() membaca byte header, sehingga tipe yang dilaporkan
     * browser tidak menentukan apa pun. Ini penting agar ekstensi file dan
     * kolom mime_type tidak pernah saling bertentangan: berkas PNG yang
     * dikirim dengan header "image/jpeg" tetap tersimpan sebagai .png.
     *
     * MIME kiriman hanya dipakai bila isi berkas tidak terbaca sama sekali,
     * dan bila keduanya gagal ekstensinya menjadi 'bin' -- tidak dapat
     * dieksekusi maupun dirender sebagai gambar.
     */
    protected static function extensionFor(TemporaryUploadedFile $file): string
    {
        $path = $file->getRealPath();

        if (is_string($path) && $path !== '') {
            $size = @getimagesize($path);
            $detected = is_array($size) ? strtolower((string) ($size['mime'] ?? '')) : '';

            if (isset(self::EXTENSION_BY_MIME[$detected])) {
                return self::EXTENSION_BY_MIME[$detected];
            }
        }

        $reported = strtolower((string) $file->getMimeType());

        return self::EXTENSION_BY_MIME[$reported] ?? 'bin';
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

    /**
     * Hapus satu file terkelola. Path di luar direktori modul diabaikan
     * diam-diam, sehingga aset brand di public/images/brand -- yang bahkan
     * tidak berada di disk ini -- tidak mungkin ikut terhapus.
     */
    public static function deleteManagedFile(?string $path): bool
    {
        $path = is_string($path) ? trim($path) : '';

        if ($path === '' || ! self::isManagedPath($path)) {
            return false;
        }

        $disk = Storage::disk(self::DISK);

        if (! $disk->exists($path)) {
            return false;
        }

        return $disk->delete($path);
    }

    /**
     * Hapus seluruh direktori milik satu gerobak. Dipakai hanya saat force
     * delete, dan hanya untuk direktori di bawah locations/.
     */
    public static function deleteManagedDirectory(?string $directory): bool
    {
        $directory = is_string($directory) ? trim(ltrim($directory, '/')) : '';

        if ($directory === '' || str_contains($directory, '..')) {
            return false;
        }

        // Wajib berupa subfolder gerobak, bukan akar locations/ itu sendiri.
        if (! str_starts_with($directory, self::LOCATION_ROOT_DIRECTORY.'/')) {
            return false;
        }

        $disk = Storage::disk(self::DISK);

        return $disk->directoryExists($directory) && $disk->deleteDirectory($directory);
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
