<?php

namespace App\Filament\Resources\Locations\Pages;

use App\Filament\Resources\Locations\LocationResource;
use App\Filament\Resources\Locations\Schemas\LocationForm;
use App\Services\LocationOrderingService;
use App\Support\WhatsAppNumber;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateLocation extends CreateRecord
{
    protected static string $resource = LocationResource::class;

    public function getTitle(): string
    {
        return 'Tambah Gerobak';
    }

    protected function handleRecordCreation(array $data): Model
    {
        $areaId = (int) ($data['location_area_id'] ?? 0);

        $slug = LocationForm::resolveSlug($data['slug'] ?? null, $data['name'] ?? null, $areaId);
        $publishedAt = $data['published_at'] ?? null;

        unset($data['slug'], $data['published_at']);

        // Diisi dulu, disimpan sekali: kolom slug NOT NULL.
        $record = new (static::getModel());

        $record->fill([
            ...$data,
            // Nomor dirapikan sekali di sini supaya bentuk simpanannya tunggal.
            'whatsapp_number' => WhatsAppNumber::normalize($data['whatsapp_number'] ?? null),
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        $record->slug = $slug;
        $record->published_at = $publishedAt;

        // Urutan terakhir DI DALAM Area terpilih.
        app(LocationOrderingService::class)
            ->assignLastPosition($record, ['location_area_id' => $areaId]);

        return $record;
    }

    protected function afterCreate(): void
    {
        LocationCoverNormalizer::normalize($this->getRecord());
    }
}
