<?php

namespace App\Filament\Resources\LocationGroups\Pages;

use App\Filament\Resources\LocationGroups\LocationGroupResource;
use App\Services\LocationOrderingService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateLocationGroup extends CreateRecord
{
    protected static string $resource = LocationGroupResource::class;

    public function getTitle(): string
    {
        return 'Tambah Kota/Grup';
    }

    protected function handleRecordCreation(array $data): Model
    {
        $provinceId = (int) ($data['province_id'] ?? 0);

        $record = new (static::getModel());

        $record->fill([
            ...$data,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        // Urutan terakhir DI DALAM provinsi terpilih, bukan urutan global.
        app(LocationOrderingService::class)
            ->assignLastPosition($record, ['province_id' => $provinceId]);

        return $record;
    }
}
