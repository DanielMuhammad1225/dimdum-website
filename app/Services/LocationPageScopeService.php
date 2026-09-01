<?php

namespace App\Services;

use App\Models\Location;
use App\Models\LocationGroup;
use App\Models\LocationPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Aturan cakupan Halaman Slug Lokasi.
 *
 * SATU rumus kelayakan, dipakai di mana-mana:
 *
 *     location.area.location_group_id  HARUS termasuk dalam
 *     Kota/Grup yang dipilih halaman
 *
 * Rumus itu tidak boleh hanya hidup di form. Payload yang dikirim langsung
 * lewat request atau Livewire menempuh jalur yang sama, jadi seluruh
 * pemeriksaan di kelas ini berjalan server-side dan melempar ValidationException
 * -- bukan sekadar menyembunyikan pilihan di UI.
 *
 * Area SENGAJA bukan input. Ia hanya konteks pengelompokan saat menampilkan
 * kandidat, sehingga memilih satu Kota/Grup otomatis membuka gerobak dari
 * SELURUH Area di bawahnya.
 */
class LocationPageScopeService
{
    /**
     * Query kandidat gerobak untuk sekumpulan Kota/Grup.
     *
     * Diurutkan mengikuti urutan master: Provinsi -> Kota/Grup -> Area ->
     * Gerobak. Tidak ada sistem urutan kedua di pivot.
     *
     * @param  list<int>  $groupIds
     * @return Builder<Location>
     */
    public function candidateQuery(array $groupIds): Builder
    {
        $query = Location::query()
            ->join('location_areas', 'location_areas.id', '=', 'locations.location_area_id')
            ->join('location_groups', 'location_groups.id', '=', 'location_areas.location_group_id')
            ->join('provinces', 'provinces.id', '=', 'location_groups.province_id')
            ->select('locations.*')
            ->orderBy('provinces.sort_order')
            ->orderBy('provinces.name')
            ->orderBy('location_groups.sort_order')
            ->orderBy('location_groups.name')
            ->orderBy('location_areas.sort_order')
            ->orderBy('location_areas.name')
            ->orderBy('locations.sort_order')
            ->orderBy('locations.name')
            ->orderBy('locations.id');

        if ($groupIds === []) {
            // Tanpa Kota/Grup, kandidatnya kosong -- BUKAN seluruh gerobak.
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('location_areas.location_group_id', $groupIds);
    }

    /**
     * Kandidat gerobak sebagai opsi terkelompok untuk form admin.
     *
     * Bentuknya: ['Provinsi — Kota/Grup · Area' => [id => 'Nama — alamat']].
     * Nama Area ikut disebut supaya gerobak dengan nama mirip tetap bisa
     * dibedakan, dan Provinsi ikut disebut supaya nama Kota/Grup yang berulang
     * antar provinsi tidak ambigu.
     *
     * @param  list<int>  $groupIds
     * @param  list<int>  $alwaysInclude  id yang tetap ditampilkan meski di
     *                                    luar cakupan (pilihan lama yang sah)
     * @return array<string, array<int, string>>
     */
    public function candidateOptions(array $groupIds, array $alwaysInclude = []): array
    {
        if ($groupIds === [] && $alwaysInclude === []) {
            return [];
        }

        $query = Location::query()
            ->join('location_areas', 'location_areas.id', '=', 'locations.location_area_id')
            ->join('location_groups', 'location_groups.id', '=', 'location_areas.location_group_id')
            ->join('provinces', 'provinces.id', '=', 'location_groups.province_id')
            ->select([
                'locations.id',
                'locations.name',
                'locations.full_address',
                'locations.is_active',
                'location_areas.name as area_name',
                'location_groups.name as group_name',
                'provinces.name as province_name',
            ])
            ->orderBy('provinces.sort_order')
            ->orderBy('provinces.name')
            ->orderBy('location_groups.sort_order')
            ->orderBy('location_groups.name')
            ->orderBy('location_areas.sort_order')
            ->orderBy('location_areas.name')
            ->orderBy('locations.sort_order')
            ->orderBy('locations.name')
            ->orderBy('locations.id')
            ->where(function (Builder $inner) use ($groupIds, $alwaysInclude): void {
                if ($groupIds !== []) {
                    $inner->whereIn('location_areas.location_group_id', $groupIds);
                }

                if ($alwaysInclude !== []) {
                    $inner->orWhereIn('locations.id', $alwaysInclude);
                }

                if ($groupIds === [] && $alwaysInclude === []) {
                    $inner->whereRaw('1 = 0');
                }
            });

        $options = [];

        foreach ($query->get() as $row) {
            $heading = $row->province_name.' — '.$row->group_name.' · '.$row->area_name;

            $address = is_string($row->full_address) ? trim($row->full_address) : '';
            $label = $row->name;

            if ($address !== '') {
                $label .= ' — '.Str::limit($address, 60);
            }

            if (! $row->is_active) {
                $label .= ' (nonaktif)';
            }

            $options[$heading][$row->id] = $label;
        }

        return $options;
    }

    /**
     * Kota/Grup yang boleh dipilih, dengan label yang tidak ambigu.
     *
     * @return array<int, string>
     */
    public function groupOptions(): array
    {
        return LocationGroup::query()
            ->with('province')
            ->join('provinces', 'provinces.id', '=', 'location_groups.province_id')
            ->select('location_groups.*')
            ->orderBy('provinces.sort_order')
            ->orderBy('provinces.name')
            ->orderBy('location_groups.sort_order')
            ->orderBy('location_groups.name')
            ->get()
            ->mapWithKeys(fn (LocationGroup $group): array => [
                $group->getKey() => $group->qualifiedName(),
            ])
            ->all();
    }

    /**
     * Pastikan seluruh gerobak yang dipilih berada dalam cakupan halaman.
     *
     * @param  list<int>  $locationIds
     * @param  list<int>  $groupIds
     *
     * @throws ValidationException
     */
    public function assertLocationsWithinGroups(array $locationIds, array $groupIds, string $field = 'location_ids'): void
    {
        $locationIds = array_values(array_unique(array_map('intval', $locationIds)));

        if ($locationIds === []) {
            return;
        }

        if ($groupIds === []) {
            throw ValidationException::withMessages([
                $field => 'Pilih Kota/Grup lebih dulu sebelum memilih gerobak.',
            ]);
        }

        $outside = $this->locationsOutsideGroups($locationIds, $groupIds);

        if ($outside->isEmpty()) {
            return;
        }

        throw ValidationException::withMessages([
            $field => 'Gerobak berikut berada di luar Kota/Grup yang dipilih dan ditolak: '
                .$outside->implode(', ').'.',
        ]);
    }

    /**
     * Nama gerobak yang berada DI LUAR Kota/Grup yang diberikan.
     *
     * @param  list<int>  $locationIds
     * @param  list<int>  $groupIds
     * @return Collection<int, string>
     */
    public function locationsOutsideGroups(array $locationIds, array $groupIds): Collection
    {
        if ($locationIds === []) {
            return collect();
        }

        return Location::query()
            ->withTrashed()
            ->whereIn('locations.id', $locationIds)
            ->where(function (Builder $query) use ($groupIds): void {
                if ($groupIds === []) {
                    return;
                }

                $query->whereDoesntHave(
                    'area',
                    fn (Builder $area) => $area->whereIn($area->qualifyColumn('location_group_id'), $groupIds)
                );
            })
            ->when($groupIds === [], fn (Builder $query) => $query)
            ->orderBy('locations.name')
            ->pluck('locations.name');
    }

    /**
     * Kota/Grup yang tidak boleh dilepas karena masih menyumbang gerobak
     * terpilih.
     *
     * Melepasnya diam-diam akan mengosongkan isi landing page tanpa disadari
     * admin, jadi kasus ini ditolak dengan pesan yang menyebut nama grupnya.
     *
     * @param  list<int>  $newGroupIds
     * @return Collection<int, string>
     */
    public function groupsStillInUse(LocationPage $page, array $newGroupIds): Collection
    {
        $selectedIds = $page->locations()->pluck('locations.id')->all();

        if ($selectedIds === []) {
            return collect();
        }

        $currentGroupIds = $page->groups()->pluck('location_groups.id')->all();
        $removed = array_values(array_diff($currentGroupIds, $newGroupIds));

        if ($removed === []) {
            return collect();
        }

        return LocationGroup::query()
            ->with('province')
            ->whereIn('location_groups.id', $removed)
            ->whereHas('areas', fn (Builder $areas) => $areas->whereHas(
                'locations',
                fn (Builder $locations) => $locations->whereIn($locations->qualifyColumn('id'), $selectedIds)
            ))
            ->get()
            ->map(fn (LocationGroup $group): string => $group->qualifiedName())
            ->values();
    }

    /**
     * @param  list<int>  $newGroupIds
     *
     * @throws ValidationException
     */
    public function assertGroupsCanBeDetached(LocationPage $page, array $newGroupIds, string $field = 'group_ids'): void
    {
        $blocking = $this->groupsStillInUse($page, $newGroupIds);

        if ($blocking->isEmpty()) {
            return;
        }

        throw ValidationException::withMessages([
            $field => 'Kota/Grup berikut masih memiliki gerobak yang dipilih pada halaman ini: '
                .$blocking->implode(', ').'. Hapus dulu pilihan gerobaknya sebelum melepas Kota/Grup.',
        ]);
    }

    /**
     * Simpan cakupan dan isi halaman dalam satu transaction.
     *
     * @param  list<int>  $groupIds
     * @param  list<int>  $locationIds
     */
    public function sync(LocationPage $page, array $groupIds, array $locationIds): void
    {
        $groupIds = array_values(array_unique(array_map('intval', $groupIds)));
        $locationIds = array_values(array_unique(array_map('intval', $locationIds)));

        $this->assertLocationsWithinGroups($locationIds, $groupIds);

        DB::transaction(function () use ($page, $groupIds, $locationIds): void {
            $page->groups()->sync($groupIds);
            $page->locations()->sync($locationIds);
        });

        LocationPageCatalogService::flushCache();
    }

    /**
     * Halaman yang akan RUSAK bila sebuah Area pindah ke Kota/Grup lain.
     *
     * Perpindahan membuat gerobak di bawah Area itu keluar dari cakupan
     * halaman yang memilihnya. Daripada mengeluarkannya diam-diam, kasusnya
     * diblokir dan namanya disebut.
     *
     * @return Collection<int, string>
     */
    public function pagesBrokenByAreaMove(int $areaId, int $newGroupId): Collection
    {
        return LocationPage::query()
            ->whereHas('locations', fn (Builder $locations) => $locations
                ->where($locations->qualifyColumn('location_area_id'), $areaId))
            ->whereDoesntHave('groups', fn (Builder $groups) => $groups
                ->where($groups->qualifyColumn('id'), $newGroupId))
            ->orderBy('title')
            ->pluck('title');
    }

    /**
     * Halaman yang akan RUSAK bila sebuah gerobak pindah ke Area lain.
     *
     * @return Collection<int, string>
     */
    public function pagesBrokenByLocationMove(int $locationId, int $newAreaId): Collection
    {
        $newGroupId = DB::table('location_areas')->where('id', $newAreaId)->value('location_group_id');

        if ($newGroupId === null) {
            return collect();
        }

        return LocationPage::query()
            ->whereHas('locations', fn (Builder $locations) => $locations
                ->where($locations->qualifyColumn('id'), $locationId))
            ->whereDoesntHave('groups', fn (Builder $groups) => $groups
                ->where($groups->qualifyColumn('id'), $newGroupId))
            ->orderBy('title')
            ->pluck('title');
    }
}
