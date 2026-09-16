<?php

namespace App\Filament\Resources\Provinces\Pages;

use App\Filament\Resources\Provinces\ProvinceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProvinces extends ListRecords
{
    protected static string $resource = ProvinceResource::class;

    public function getTitle(): string
    {
        return 'Provinsi';
    }

    public function getSubheading(): ?string
    {
        return 'Tingkat teratas hierarki lokasi. Provinsi menjadi segmen pertama URL halaman area.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Tambah Provinsi'),
        ];
    }
}
