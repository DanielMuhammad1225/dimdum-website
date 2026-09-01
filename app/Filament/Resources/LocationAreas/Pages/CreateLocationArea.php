<?php

namespace App\Filament\Resources\LocationAreas\Pages;

use App\Filament\Resources\LocationAreas\LocationAreaResource;
use App\Services\LocationAreaSlugService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateLocationArea extends CreateRecord
{
    protected static string $resource = LocationAreaResource::class;

    public function getTitle(): string
    {
        return 'Tambah Area';
    }

    /**
     * slug dan published_at tidak fillable, jadi ditulis di sini setelah
     * form lolos validasi dan permission-nya diperiksa.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $slugService = app(LocationAreaSlugService::class);

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
