<?php

namespace App\Filament\Resources\Locations\Pages;

use App\Filament\Resources\Locations\LocationResource;
use App\Filament\Resources\Locations\Schemas\LocationForm;
use App\Models\Location;
use App\Services\LocationOrderingService;
use App\Support\WhatsAppNumber;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class EditLocation extends EditRecord
{
    protected static string $resource = LocationResource::class;

    public function getTitle(): string
    {
        return 'Ubah Gerobak';
    }

    /**
     * Dua select bantu (provinsi & Kota/Grup) tidak tersimpan di tabel, jadi
     * diisi ulang dari rantai induk sebenarnya setiap kali form dibuka.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Location $record */
        $record = $this->getRecord();
        $record->loadMissing('area.group');

        $group = $record->area?->group;

        $data['location_group_id'] = $group?->getKey();
        $data['province_id'] = $group?->province_id;

        return $data;
    }

    /**
     * URL halaman Area induk, atau null bila rantainya belum tampil.
     */
    protected static function areaUrl(Location $record): ?string
    {
        $record->loadMissing('area.group.province');
        $area = $record->area;

        if ($area === null || ! $area->isEffectivelyVisible()) {
            return null;
        }

        $province = $area->group?->province;

        return $province === null
            ? null
            : route('locations.area', [$province->slug, $area->slug]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('Lihat halaman area')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                // Halaman detail gerobak belum ada, jadi preview mengarah ke
                // halaman Area induknya.
                ->visible(fn (Location $record): bool => self::areaUrl($record) !== null)
                ->url(fn (Location $record): string => (string) self::areaUrl($record), shouldOpenInNewTab: true),

            DeleteAction::make()->label('Hapus'),
            RestoreAction::make()->label('Pulihkan'),

            /*
             | Force delete membersihkan berkas SETELAH baris benar-benar
             | terhapus. Soft delete sengaja tidak menyentuh file sama sekali,
             | supaya pemulihan mengembalikan data beserta fotonya.
             */
            ForceDeleteAction::make()
                ->label('Hapus permanen')
                ->after(function (Location $record): void {
                    LocationMediaCleaner::purge($record);
                }),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Location $record */
        $areaId = (int) ($data['location_area_id'] ?? $record->location_area_id);

        // Dicatat SEBELUM fill(), supaya perpindahan Area masih terdeteksi.
        $previousAreaId = (int) $record->location_area_id;

        $requestedSlug = $data['slug'] ?? null;
        $hasPublishedField = array_key_exists('published_at', $data);
        $publishedAt = $data['published_at'] ?? null;

        unset($data['slug'], $data['published_at']);

        $record->fill([
            ...$data,
            'whatsapp_number' => WhatsAppNumber::normalize($data['whatsapp_number'] ?? null),
            'updated_by' => auth()->id(),
        ]);

        if ($hasPublishedField) {
            $record->published_at = $publishedAt;
        }

        // Slug hanya ditulis ulang bila pengubahnya memang berwenang; field-nya
        // tidak ter-dehydrate untuk Operator sehingga nilainya tidak pernah ada.
        if (is_string($requestedSlug) && $requestedSlug !== '') {
            $record->slug = LocationForm::resolveSlug($requestedSlug, $record->name, $areaId, $record);
        }

        $record->save();

        /*
         | Pindah Area = pindah scope urutan. Record ditempatkan di akhir Area
         | tujuan, lalu urutan Area lama DAN Area baru dirapikan kembali dalam
         | satu transaction. Bila Area-nya tidak berubah, sort_order dibiarkan
         | apa adanya.
         */
        if ($previousAreaId !== (int) $record->location_area_id) {
            app(LocationOrderingService::class)->moveToScope(
                $record,
                ['location_area_id' => $previousAreaId],
                ['location_area_id' => (int) $record->location_area_id],
            );
        }

        return $record;
    }

    protected function afterSave(): void
    {
        /** @var Location $record */
        $record = $this->getRecord();

        LocationCoverNormalizer::normalize($record);
    }
}
