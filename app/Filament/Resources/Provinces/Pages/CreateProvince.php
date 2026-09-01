<?php

namespace App\Filament\Resources\Provinces\Pages;

use App\Filament\Resources\Provinces\ProvinceResource;
use App\Services\LocationOrderingService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProvince extends CreateRecord
{
    protected static string $resource = ProvinceResource::class;

    public function getTitle(): string
    {
        return 'Tambah Provinsi';
    }

    protected function handleRecordCreation(array $data): Model
    {
        $record = new (static::getModel());

        $record->fill([
            ...$data,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        /*
         | Urutan tidak pernah datang dari form: provinsi baru selalu
         | ditempatkan di posisi terakhir, dihitung server di dalam satu
         | transaction. Provinsi tidak punya induk, jadi scope-nya global.
         */
        app(LocationOrderingService::class)->assignLastPosition($record, []);

        return $record;
    }
}
