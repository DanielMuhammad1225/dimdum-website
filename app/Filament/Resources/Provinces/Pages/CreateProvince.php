<?php

namespace App\Filament\Resources\Provinces\Pages;

use App\Filament\Resources\Provinces\ProvinceResource;
use App\Services\LocationProvinceSlugService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProvince extends CreateRecord
{
    protected static string $resource = ProvinceResource::class;

    public function getTitle(): string
    {
        return 'Tambah Provinsi';
    }

    /**
     * slug dan published_at tidak fillable, jadi ditulis di sini setelah
     * form lolos validasi dan permission-nya diperiksa.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $slugService = app(LocationProvinceSlugService::class);

        $slug = $slugService->normalize($data['slug'] ?? null, $data['name'] ?? null);
        $publishedAt = $data['published_at'] ?? null;

        unset($data['slug'], $data['published_at']);

        /*
         | Model diisi dulu, baru disimpan SEKALI. Menyimpan lebih awal tanpa
         | slug akan menabrak constraint NOT NULL pada kolom slug.
         */
        $record = new (static::getModel());

        $record->fill([
            ...$data,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        $record->slug = $slug;
        $record->published_at = $publishedAt;
        $record->save();

        return $record;
    }
}
