<?php

namespace App\Filament\Resources\Provinces\Pages;

use App\Filament\Resources\Provinces\ProvinceResource;
use App\Models\Province;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditProvince extends EditRecord
{
    protected static string $resource = ProvinceResource::class;

    public function getTitle(): string
    {
        return 'Ubah Provinsi';
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Hapus')
                ->before(function (Province $record, DeleteAction $action): void {
                    if ($record->groups()->exists()) {
                        $action->failureNotificationTitle(
                            'Provinsi ini masih memiliki Kota/Grup. Pindahkan atau hapus grupnya lebih dulu.'
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
        $record->fill([...$data, 'updated_by' => auth()->id()]);
        $record->save();

        return $record;
    }

    protected function afterSave(): void
    {
        /** @var Province $record */
        $record = $this->getRecord();

        /*
         | Menonaktifkan provinsi memutus rantai visibilitas seluruh gerobak di
         | bawahnya. Halaman slug yang memakainya bisa mendadak kosong, jadi
         | admin diberi tahu -- bukan dibiarkan menemukannya sendiri.
         */
        if (! $record->is_active) {
            Notification::make()
                ->warning()
                ->title('Provinsi ini nonaktif')
                ->body('Seluruh gerobak di bawahnya berhenti tampil pada Halaman Slug Lokasi mana pun sampai provinsi diaktifkan kembali.')
                ->persistent()
                ->send();
        }
    }
}
