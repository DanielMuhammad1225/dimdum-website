<?php

namespace App\Filament\Resources\LocationAreas\Pages;

use App\Filament\Resources\LocationAreas\LocationAreaResource;
use App\Services\LocationOrderingService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateLocationArea extends CreateRecord
{
    protected static string $resource = LocationAreaResource::class;

    public function getTitle(): string
    {
        return 'Tambah Area';
    }

    protected function handleRecordCreation(array $data): Model
    {
        $record = new (static::getModel());

        $record->fill([
            ...$data,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        // Urutan terakhir DI DALAM Kota/Grup terpilih.
        app(LocationOrderingService::class)->assignLastPosition($record, [
            'location_group_id' => (int) ($data['location_group_id'] ?? 0),
        ]);

        return $record;
    }
}
