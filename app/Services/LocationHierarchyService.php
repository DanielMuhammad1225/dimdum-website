<?php

namespace App\Services;

use App\Models\LocationArea;
use App\Models\LocationGroup;

/**
 * Penjaga konsistensi hierarki lokasi.
 *
 * Cascading select di admin hanyalah kenyamanan tampilan. Nilai yang benar
 * tetap harus dipastikan DI SERVER, karena form apa pun bisa dikirim ulang
 * dengan kombinasi parent yang tidak sah tanpa menyentuh antarmuka.
 *
 * Dua aturan yang ditegakkan di sini:
 *
 *  1. Kota/Grup yang dipilih harus benar-benar berada di Provinsi yang
 *     dipilih.
 *
 *  2. Area yang PERNAH terbit tidak boleh berpindah ke Provinsi lain.
 *     Alasannya URL: /lokasi/{province}/{area}. Berpindah provinsi mengubah
 *     segmen pertama URL, sehingga tautan iklan yang sudah beredar akan
 *     menunjuk kombinasi yang tidak lagi sah. Perpindahan antar-Kota/Grup di
 *     dalam provinsi yang sama TIDAK mengubah URL dan karenanya diizinkan.
 */
class LocationHierarchyService
{
    /**
     * Apakah Kota/Grup ini benar-benar milik provinsi tersebut?
     */
    public function groupBelongsToProvince(?int $groupId, ?int $provinceId): bool
    {
        if ($groupId === null || $provinceId === null) {
            return false;
        }

        return LocationGroup::query()
            ->whereKey($groupId)
            ->where('province_id', $provinceId)
            ->exists();
    }

    /**
     * Provinsi induk sebuah Kota/Grup, tanpa memuat seluruh model.
     */
    public function provinceIdForGroup(?int $groupId): ?int
    {
        if ($groupId === null) {
            return null;
        }

        $provinceId = LocationGroup::query()->whereKey($groupId)->value('province_id');

        return $provinceId === null ? null : (int) $provinceId;
    }

    /**
     * Bolehkah Area dipindahkan ke Kota/Grup tujuan?
     *
     * Mengembalikan null bila boleh, atau kalimat penjelasan bila ditolak.
     */
    public function rejectionReasonForAreaMove(LocationArea $area, ?int $targetGroupId): ?string
    {
        if ($targetGroupId === null) {
            return 'Area wajib berada di bawah satu Kota/Grup.';
        }

        $targetProvinceId = $this->provinceIdForGroup($targetGroupId);

        if ($targetProvinceId === null) {
            return 'Kota/Grup yang dipilih tidak ditemukan.';
        }

        // Area baru (belum tersimpan) bebas ditempatkan di mana pun.
        if (! $area->exists) {
            return null;
        }

        $currentGroupId = $area->location_group_id === null ? null : (int) $area->location_group_id;

        if ($currentGroupId === $targetGroupId) {
            return null;
        }

        $currentProvinceId = $this->provinceIdForGroup($currentGroupId);

        // Pindah di dalam provinsi yang sama: URL tidak berubah, aman.
        if ($currentProvinceId === $targetProvinceId) {
            return null;
        }

        if ($area->hasEverBeenPublished()) {
            return 'Area ini sudah pernah terbit, jadi tidak boleh dipindahkan ke provinsi lain. '
                .'URL halaman memuat nama provinsi, sehingga perpindahan akan mematikan tautan '
                .'iklan yang sedang berjalan. Pindahkan ke Kota/Grup lain di provinsi yang sama, '
                .'atau buat Area baru di provinsi tujuan.';
        }

        // Area draft belum punya URL publik -- perpindahan lintas provinsi aman.
        return null;
    }
}
