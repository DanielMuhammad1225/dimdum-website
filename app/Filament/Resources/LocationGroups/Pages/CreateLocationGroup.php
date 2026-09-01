<?php

namespace App\Filament\Resources\LocationGroups\Pages;

use App\Filament\Resources\LocationGroups\LocationGroupResource;
use App\Services\LocationProvinceSlugService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateLocationGroup extends CreateRecord
{
    protected static string $resource = LocationGroupResource::class;

    public function getTitle(): string
    {
        return 'Tambah Kota/Grup';
    }

    /**
     * slug tidak fillable dan wajib unik DI DALAM provinsinya, jadi ditulis
     * di sini setelah provinsi tujuan diketahui.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $slugService = app(LocationProvinceSlugService::class);

        $provinceId = (int) ($data['province_id'] ?? 0);
        $desired = $data['slug'] ?? $data['name'] ?? '';

        unset($data['slug']);

        $record = new (static::getModel());

        $record->fill([
            ...$data,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        $record->slug = $slugService->uniqueGroupSlug($provinceId, (string) $desired);
        $record->save();

        return $record;
    }
}
