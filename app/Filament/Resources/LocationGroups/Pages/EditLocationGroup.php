<?php

namespace App\Filament\Resources\LocationGroups\Pages;

use App\Filament\Resources\LocationGroups\LocationGroupResource;
use App\Models\LocationGroup;
use App\Services\LocationProvinceSlugService;
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
                }),

            RestoreAction::make()->label('Pulihkan'),
            ForceDeleteAction::make()->label('Hapus permanen'),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var LocationGroup $record */
        $slugService = app(LocationProvinceSlugService::class);

        $requestedSlug = $data['slug'] ?? null;
        unset($data['slug']);

        /*
         | Memindahkan grup ke provinsi lain memindahkan SELURUH area di
         | bawahnya sekaligus, sehingga URL-nya ikut berubah. Peringatannya
         | disampaikan setelah simpan; pencegahannya ada di tingkat Area,
         | tempat aturan "pernah terbit tidak boleh lintas provinsi" berlaku.
         */
        $previousProvinceId = (int) $record->province_id;

        $record->fill([...$data, 'updated_by' => auth()->id()]);
        $record->save();

        if (is_string($requestedSlug) && $requestedSlug !== '') {
            $slug = $slugService->uniqueGroupSlug(
                (int) $record->province_id,
                $requestedSlug,
                $record->getKey(),
            );

            if ($slug !== $record->slug) {
                $record->slug = $slug;
                $record->save();
            }
        }

        if ($previousProvinceId !== (int) $record->province_id && $record->areas()->exists()) {
            Notification::make()
                ->warning()
                ->title('Grup berpindah provinsi')
                ->body('Seluruh Area di bawah grup ini ikut berpindah, sehingga URL publiknya berubah. '
                    .'Periksa kembali tautan iklan yang sedang berjalan.')
                ->persistent()
                ->send();
        }

        return $record;
    }
}
