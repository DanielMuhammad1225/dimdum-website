<?php

namespace App\Filament\Resources\LocationAreas\Pages;

use App\Filament\Resources\LocationAreas\LocationAreaResource;
use App\Models\LocationArea;
use App\Services\LocationAreaSlugService;
use App\Services\LocationOrderingService;
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

    /**
     * URL publik area, atau null bila rantai induknya belum tampil.
     */
    protected static function publicUrl(LocationArea $record): ?string
    {
        if (! $record->isPubliclyVisible()) {
            return null;
        }

        $record->loadMissing('group.province');
        $province = $record->group?->province;

        return $province === null
            ? null
            : route('locations.area', [$province->slug, $record->slug]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('Lihat halaman')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->visible(fn (LocationArea $record): bool => self::publicUrl($record) !== null)
                ->url(fn (LocationArea $record): string => (string) self::publicUrl($record), shouldOpenInNewTab: true),

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

        // Dicatat SEBELUM fill(), supaya perpindahan grup masih terdeteksi.
        $previousGroupId = (int) $record->location_group_id;

        $record->fill([...$data, 'updated_by' => auth()->id()]);

        if ($hasPublishedField) {
            $record->published_at = $publishedAt;
        }

        $record->save();

        // Pindah Kota/Grup = pindah scope urutan.
        if ($previousGroupId !== (int) $record->location_group_id) {
            app(LocationOrderingService::class)->moveToScope(
                $record,
                ['location_group_id' => $previousGroupId],
                ['location_group_id' => (int) $record->location_group_id],
            );
        }

        if (is_string($requestedSlug) && $requestedSlug !== '') {
            $wasPublished = $record->hasEverBeenPublished();
            $previousSlug = $record->slug;

            if ($slugService->apply($record, $requestedSlug, auth()->id()) && $wasPublished) {
                Notification::make()
                    ->warning()
                    ->title('URL area berubah')
                    ->body("Alamat lama .../{$previousSlug} kini dialihkan permanen ke .../{$record->slug}. Perbarui tautan di iklan yang sedang berjalan.")
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
                ->title('Area ini belum punya gerobak yang tampil')
                ->body('Halaman tetap dapat diakses, tetapi pengunjung hanya melihat pesan "sedang diperbarui". Sebaiknya belum dipakai sebagai tujuan iklan.')
                ->persistent()
                ->send();
        }
    }
}
