<?php

namespace App\Filament\Resources\Locations\Pages;

use App\Filament\Support\UploadedImage;
use App\Models\Location;
use App\Models\LocationImage;

/**
 * Pembersih berkas foto gerobak.
 *
 * Dipanggil HANYA setelah penghapusan permanen berhasil di database.
 * Urutannya disengaja: baris dulu, berkas kemudian. Kalau transaksi gagal,
 * data lama dan berkasnya tetap utuh; yang mungkin tersisa hanyalah berkas
 * yatim, bukan referensi rusak di halaman publik.
 *
 * Penghapusan dibatasi direktori modul lokasi. Aset brand di
 * public/images/brand berada di luar disk ini dan tidak mungkin tersentuh.
 */
class LocationMediaCleaner
{
    /**
     * Hapus seluruh berkas milik satu gerobak.
     *
     * @return int jumlah berkas/direktori yang benar-benar dihapus
     */
    public static function purge(Location $location): int
    {
        $removed = 0;

        $images = LocationImage::withTrashed()
            ->where('location_id', $location->getKey())
            ->get();

        foreach ($images as $image) {
            $removed += self::purgeImage($image) ? 1 : 0;
        }

        return $removed;
    }

    /**
     * Hapus berkas satu foto beserta folder ULID-nya bila sudah kosong.
     */
    public static function purgeImage(LocationImage $image): bool
    {
        $path = $image->image_path;

        if (! is_string($path) || $path === '') {
            return false;
        }

        $deleted = UploadedImage::deleteManagedFile($path);

        // Folder per foto ikut dibuang supaya tidak menyisakan direktori kosong.
        $directory = dirname(ltrim($path, '/'));
        UploadedImage::deleteManagedDirectory($directory);

        return $deleted;
    }
}
