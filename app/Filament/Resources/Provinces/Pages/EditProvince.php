<?php

namespace App\Filament\Resources\Provinces\Pages;

use App\Filament\Resources\Provinces\ProvinceResource;
use App\Models\Province;
use App\Services\LocationProvinceSlugService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
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
            Action::make('preview')
                ->label('Lihat halaman')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->visible(fn (Province $record): bool => $record->isPubliclyVisible())
                ->url(fn (Province $record): string => route('locations.province', $record->slug), shouldOpenInNewTab: true),

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

    /**
     * Slug diperlakukan terpisah dari field biasa: penggantiannya berjalan
     * dalam transaction dan mencatat redirect 301 bila halaman pernah terbit.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Province $record */
        $slugService = app(LocationProvinceSlugService::class);

        $requestedSlug = $data['slug'] ?? null;
        $hasPublishedField = array_key_exists('published_at', $data);
        $publishedAt = $data['published_at'] ?? null;

        unset($data['slug'], $data['published_at']);

        $record->fill([...$data, 'updated_by' => auth()->id()]);

        if ($hasPublishedField) {
            $record->published_at = $publishedAt;
        }

        $record->save();

        if (is_string($requestedSlug) && $requestedSlug !== '') {
            $wasPublished = $record->hasEverBeenPublished();
            $previousSlug = $record->slug;

            if ($slugService->apply($record, $requestedSlug, auth()->id()) && $wasPublished) {
                Notification::make()
                    ->warning()
                    ->title('URL provinsi berubah')
                    ->body("Alamat lama /lokasi/{$previousSlug} kini dialihkan permanen ke /lokasi/{$record->slug}. "
                        .'URL seluruh area di bawahnya ikut berubah. Perbarui tautan di iklan yang sedang berjalan.')
                    ->persistent()
                    ->send();
            }
        }

        return $record;
    }

    protected function afterSave(): void
    {
        /** @var Province $record */
        $record = $this->getRecord();

        // Provinsi terbit tanpa grup aktif bukan tujuan iklan yang baik.
        if ($record->isPubliclyVisible() && ! $record->groups()->active()->exists()) {
            Notification::make()
                ->warning()
                ->title('Provinsi ini belum punya Kota/Grup aktif')
                ->body('Halaman tetap dapat diakses, tetapi pengunjung hanya melihat pesan "sedang disiapkan". Sebaiknya belum dipakai sebagai tujuan iklan.')
                ->persistent()
                ->send();
        }
    }
}
