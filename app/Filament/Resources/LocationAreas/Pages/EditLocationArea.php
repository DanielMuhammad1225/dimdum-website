<?php

namespace App\Filament\Resources\LocationAreas\Pages;

use App\Filament\Resources\LocationAreas\LocationAreaResource;
use App\Models\LocationArea;
use App\Services\LocationAreaSlugService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class EditLocationArea extends EditRecord
{
    protected static string $resource = LocationAreaResource::class;

    public function getTitle(): string
    {
        return 'Ubah Wilayah Landing';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('Lihat halaman')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->visible(fn (LocationArea $record): bool => $record->isPubliclyVisible())
                ->url(fn (LocationArea $record): string => route('locations.area', $record->slug), shouldOpenInNewTab: true),

            DeleteAction::make()
                ->label('Hapus')
                ->before(function (LocationArea $record, DeleteAction $action): void {
                    if ($record->locations()->exists()) {
                        $action->failureNotificationTitle(
                            'Wilayah ini masih memiliki gerobak. Pindahkan atau hapus gerobaknya lebih dulu.'
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
        /** @var LocationArea $record */
        $slugService = app(LocationAreaSlugService::class);

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
                    ->title('URL wilayah berubah')
                    ->body("Alamat lama /lokasi/{$previousSlug} kini dialihkan permanen ke /lokasi/{$record->slug}. Perbarui tautan di iklan yang sedang berjalan.")
                    ->persistent()
                    ->send();
            }
        }

        return $record;
    }

    protected function afterSave(): void
    {
        /** @var LocationArea $record */
        $record = $this->getRecord();

        // Halaman terbit tanpa gerobak tampil bukan tujuan iklan yang baik.
        if ($record->isPubliclyVisible() && ! $record->locations()->publiclyVisible()->exists()) {
            Notification::make()
                ->warning()
                ->title('Wilayah ini belum punya gerobak yang tampil')
                ->body('Halaman tetap dapat diakses, tetapi pengunjung hanya melihat pesan "sedang diperbarui". Sebaiknya belum dipakai sebagai tujuan iklan.')
                ->persistent()
                ->send();
        }
    }
}
