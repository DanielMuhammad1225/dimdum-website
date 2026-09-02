<?php

namespace App\Filament\Resources\LocationPages\Pages;

use App\Filament\Resources\LocationPages\LocationPageResource;
use App\Services\ImageMetadata;
use App\Services\LocationOrderingService;
use App\Services\LocationPageScopeService;
use App\Services\LocationPageSlugService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateLocationPage extends CreateRecord
{
    protected static string $resource = LocationPageResource::class;

    public function getTitle(): string
    {
        return 'Tambah Halaman Slug Lokasi';
    }

    /**
     * slug dan published_at tidak fillable, dan dua relasi pivot bukan kolom.
     * Semuanya ditulis di sini setelah form lolos validasi dan permission-nya
     * diperiksa.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $slugService = app(LocationPageSlugService::class);
        $scope = app(LocationPageScopeService::class);

        $groupIds = array_map('intval', (array) ($data['group_ids'] ?? []));
        $locationIds = array_map('intval', (array) ($data['location_ids'] ?? []));
        $publishedAt = $data['published_at'] ?? null;
        $slug = $slugService->uniqueSlug((string) ($data['slug'] ?? ''), $data['title'] ?? null);

        unset($data['group_ids'], $data['location_ids'], $data['slug'], $data['published_at']);

        // Lapis terakhir: id di luar cakupan ditolak sebelum apa pun ditulis.
        $scope->assertLocationsWithinGroups($locationIds, $groupIds);

        $data = [...$data, ...self::posterMetadata($data)];

        $record = new (static::getModel());

        $record->fill([
            ...$data,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        $record->slug = $slug;
        $record->published_at = $publishedAt;

        /*
         | Urutan tidak pernah datang dari form: halaman baru selalu
         | ditempatkan di posisi terakhir, dihitung server di dalam satu
         | transaction. Halaman tidak punya induk, jadi scope-nya global.
         */
        app(LocationOrderingService::class)->assignLastPosition($record, []);

        // Relasi disimpan dalam transaction yang sama dengan barisnya.
        DB::transaction(function () use ($record, $groupIds, $locationIds): void {
            $record->groups()->sync($groupIds);
            $record->locations()->sync($locationIds);
        });

        return $record;
    }

    /**
     * Halaman yang tersimpan tetapi belum bisa dibuka pengunjung harus
     * mengatakannya sendiri.
     *
     * Menyalakan "Aktif" tanpa mengisi "Waktu terbit" menghasilkan halaman
     * yang terasa selesai tetapi URL-nya 404. Tanpa pemberitahuan ini,
     * satu-satunya cara mengetahuinya adalah membuka URL-nya dan bingung.
     */
    protected function afterCreate(): void
    {
        $issue = $this->getRecord()->publicVisibilityIssue();

        if ($issue === null) {
            return;
        }

        Notification::make()
            ->warning()
            ->title('Halaman belum dapat dibuka pengunjung')
            ->body($issue)
            ->persistent()
            ->send();
    }

    /**
     * Dimensi, MIME, dan ukuran poster dibaca dari BERKAS yang benar-benar
     * tersimpan, bukan dari yang dilaporkan browser.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function posterMetadata(array $data): array
    {
        $metadata = ImageMetadata::inspect($data['poster_path'] ?? null);

        if ($metadata === null) {
            return [
                'poster_width' => null,
                'poster_height' => null,
                'poster_mime_type' => null,
                'poster_size_bytes' => null,
            ];
        }

        return [
            'poster_width' => $metadata['width'],
            'poster_height' => $metadata['height'],
            'poster_mime_type' => $metadata['mime_type'],
            'poster_size_bytes' => $metadata['size_bytes'],
        ];
    }
}
