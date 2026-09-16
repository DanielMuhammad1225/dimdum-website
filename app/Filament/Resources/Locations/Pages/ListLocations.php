<?php

namespace App\Filament\Resources\Locations\Pages;

use App\Filament\Resources\Locations\LocationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLocations extends ListRecords
{
    protected static string $resource = LocationResource::class;

    public function getTitle(): string
    {
        return 'Gerobak';
    }

    public function getSubheading(): ?string
    {
        return 'Titik gerobak DIMDUM. Setiap gerobak tampil di halaman wilayah landing-nya.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Tambah Gerobak'),
        ];
    }
}
