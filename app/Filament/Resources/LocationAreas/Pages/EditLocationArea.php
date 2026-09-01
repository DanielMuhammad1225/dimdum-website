<?php

namespace App\Filament\Resources\LocationAreas\Pages;

use App\Filament\Resources\LocationAreas\LocationAreaResource;
use App\Models\LocationArea;
use App\Services\LocationOrderingService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditLocationArea extends EditRecord
{
    protected static string $resource = LocationAreaResource::class;

    public function getTitle(): string
    {
        return 'Ubah Area';
    }

    /**
     * Field bantu province_id tidak tersimpan di tabel, jadi diisi ulang dari
     * induk sebenarnya setiap kali form dibuka.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var LocationArea $record */
        $record = $this->getRecord();
        $record->loadMissing('group');

        $data['province_id'] = $record->group?->province_id;

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Hapus')
                ->before(function (LocationArea $record, DeleteAction $action): void {
                    if ($record->locations()->exists()) {
                        $action->failureNotificationTitle(
                            'Area ini masih memiliki gerobak. Pindahkan atau hapus gerobaknya lebih dulu.'
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
        /** @var LocationArea $record */
        $previousGroupId = (int) $record->location_group_id;

        $record->fill([...$data, 'updated_by' => auth()->id()]);
        $record->save();

        // Pindah Kota/Grup = pindah scope urutan.
        if ($previousGroupId !== (int) $record->location_group_id) {
            app(LocationOrderingService::class)->moveToScope(
                $record,
                ['location_group_id' => $previousGroupId],
                ['location_group_id' => (int) $record->location_group_id],
            );
        }

        return $record;
    }

    protected function afterSave(): void
    {
        /** @var LocationArea $record */
        $record = $this->getRecord();

        if (! $record->is_active) {
            Notification::make()
                ->warning()
                ->title('Area ini nonaktif')
                ->body('Gerobak di bawahnya berhenti tampil pada Halaman Slug Lokasi mana pun sampai area diaktifkan kembali.')
                ->persistent()
                ->send();
        }
    }
}
