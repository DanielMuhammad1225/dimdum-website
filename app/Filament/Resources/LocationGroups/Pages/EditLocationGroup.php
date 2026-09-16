<?php

namespace App\Filament\Resources\LocationGroups\Pages;

use App\Filament\Resources\LocationGroups\LocationGroupResource;
use App\Models\LocationGroup;
use App\Services\LocationOrderingService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditLocationGroup extends EditRecord
{
    protected static string $resource = LocationGroupResource::class;

    public function getTitle(): string
    {
        return 'Ubah Kota/Grup';
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Hapus')
                ->before(function (LocationGroup $record, DeleteAction $action): void {
                    if ($record->areas()->exists()) {
                        $action->failureNotificationTitle(
                            'Kota/Grup ini masih memiliki Area. Pindahkan atau hapus areanya lebih dulu.'
                        );
                        $action->failure();
                        $action->halt();
                    }

                    if ($record->pages()->exists()) {
                        $action->failureNotificationTitle(
                            'Kota/Grup ini masih dipakai sebagai cakupan Halaman Slug Lokasi. Lepas dulu dari halamannya.'
                        );
                        $action->failure();
                        $action->halt();
                    }
                }),

            RestoreAction::make()->label('Pulihkan'),
            ForceDeleteAction::make()->label('Hapus permanen'),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var LocationGroup $record */
        $previousProvinceId = (int) $record->province_id;

        $record->fill([...$data, 'updated_by' => auth()->id()]);
        $record->save();

        // Pindah provinsi = pindah scope urutan.
        if ($previousProvinceId !== (int) $record->province_id) {
            app(LocationOrderingService::class)->moveToScope(
                $record,
                ['province_id' => $previousProvinceId],
                ['province_id' => (int) $record->province_id],
            );
        }

        return $record;
    }

    protected function afterSave(): void
    {
        /** @var LocationGroup $record */
        $record = $this->getRecord();

        /*
         | Grup nonaktif memutus rantai visibilitas gerobak di bawahnya, dan
         | halaman slug yang memakainya bisa mendadak kosong. Admin diberi
         | tahu -- bukan dibiarkan menemukannya sendiri.
         */
        if (! $record->is_active && $record->pages()->exists()) {
            Notification::make()
                ->warning()
                ->title('Kota/Grup ini nonaktif')
                ->body('Gerobak di bawahnya berhenti tampil pada Halaman Slug Lokasi yang memakainya sampai grup diaktifkan kembali.')
                ->persistent()
                ->send();
        }
    }
}
