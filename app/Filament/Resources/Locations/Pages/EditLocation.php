<?php

namespace App\Filament\Resources\Locations\Pages;

use App\Filament\Resources\Locations\LocationResource;
use App\Models\Location;
use App\Services\LocationOrderingService;
use App\Support\WhatsAppNumber;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
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

    protected function getHeaderActions(): array
    {
        return [
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
        // Dicatat SEBELUM fill(), supaya perpindahan Area masih terdeteksi.
        $previousAreaId = (int) $record->location_area_id;

        $record->fill([
            ...$data,
            'whatsapp_number' => WhatsAppNumber::normalize($data['whatsapp_number'] ?? null),
            'updated_by' => auth()->id(),
        ]);

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
