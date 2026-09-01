<?php

namespace App\Filament\Resources\Locations\Pages;

use App\Filament\Resources\Locations\LocationResource;
use App\Filament\Resources\Locations\Schemas\LocationForm;
use App\Models\Location;
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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('Lihat wilayah')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->visible(fn (Location $record): bool => $record->area?->isPubliclyVisible() ?? false)
                ->url(fn (Location $record): string => route('locations.area', $record->area->slug), shouldOpenInNewTab: true),

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

        return $record;
    }

    protected function afterSave(): void
    {
        /** @var Location $record */
        $record = $this->getRecord();

        LocationCoverNormalizer::normalize($record);
    }
}
