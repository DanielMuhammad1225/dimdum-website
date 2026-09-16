<?php

namespace App\Services;

use App\Models\Location;
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
 *  2. Perpindahan parent tidak boleh MERUSAK hubungan Halaman Slug Lokasi.
 *     Sejak slug dipisahkan, hierarki tidak lagi menentukan URL -- jadi
 *     perpindahan pada dirinya sendiri aman. Yang berbahaya adalah efeknya:
 *     gerobak yang ikut pindah bisa keluar dari cakupan Kota/Grup halaman
 *     yang memilihnya, sehingga isi landing page berkurang diam-diam.
 *     Kasus itu diblokir dan nama halamannya disebut.
 */
class LocationHierarchyService
{
    public function __construct(protected LocationPageScopeService $scope) {}

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

        if ($this->provinceIdForGroup($targetGroupId) === null) {
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

        $broken = $this->scope->pagesBrokenByAreaMove($area->getKey(), $targetGroupId);

        if ($broken->isNotEmpty()) {
            return 'Area ini tidak bisa dipindahkan: gerobaknya masih dipakai halaman '
                .$broken->implode(', ').', dan Kota/Grup tujuan berada di luar cakupan halaman tersebut. '
                .'Tambahkan Kota/Grup tujuan ke halaman itu, atau lepas dulu gerobaknya dari halaman.';
        }

        return null;
    }

    /**
     * Bolehkah gerobak dipindahkan ke Area tujuan?
     *
     * Mengembalikan null bila boleh, atau kalimat penjelasan bila ditolak.
     */
    public function rejectionReasonForLocationMove(Location $location, ?int $targetAreaId): ?string
    {
        if ($targetAreaId === null) {
            return 'Gerobak wajib berada di bawah satu Area.';
        }

        if (! LocationArea::query()->whereKey($targetAreaId)->exists()) {
            return 'Area yang dipilih tidak ditemukan.';
        }

        if (! $location->exists) {
            return null;
        }

        $currentAreaId = $location->location_area_id === null ? null : (int) $location->location_area_id;

        if ($currentAreaId === $targetAreaId) {
            return null;
        }

        $broken = $this->scope->pagesBrokenByLocationMove($location->getKey(), $targetAreaId);

        if ($broken->isNotEmpty()) {
            return 'Gerobak ini tidak bisa dipindahkan: ia masih dipakai halaman '
                .$broken->implode(', ').', dan Area tujuan berada di luar cakupan halaman tersebut. '
                .'Tambahkan Kota/Grup Area tujuan ke halaman itu, atau lepas dulu gerobaknya dari halaman.';
        }

        return null;
    }
}
