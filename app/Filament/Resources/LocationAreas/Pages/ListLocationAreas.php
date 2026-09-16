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
        return 'Area';
    }

    public function getSubheading(): ?string
    {
        return 'Satuan operasional di bawah Kota/Grup. Halaman Area menjadi tujuan iklan dan menampung banyak gerobak.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Tambah Area'),
        ];
    }
}
