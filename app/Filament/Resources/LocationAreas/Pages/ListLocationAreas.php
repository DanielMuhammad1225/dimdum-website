<?php

namespace App\Filament\Resources\LocationAreas\Pages;

use App\Filament\Resources\LocationAreas\LocationAreaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLocationAreas extends ListRecords
{
    protected static string $resource = LocationAreaResource::class;

    public function getTitle(): string
    {
        return 'Wilayah Landing';
    }

    public function getSubheading(): ?string
    {
        return 'Wilayah pemasaran yang menjadi halaman tujuan iklan. Satu wilayah menampung banyak gerobak.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Tambah Wilayah'),
        ];
    }
}
