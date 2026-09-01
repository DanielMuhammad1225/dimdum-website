<?php

namespace App\Filament\Resources\LocationPages\Pages;

use App\Filament\Resources\LocationPages\LocationPageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLocationPages extends ListRecords
{
    protected static string $resource = LocationPageResource::class;

    public function getTitle(): string
    {
        return 'Halaman Slug Lokasi';
    }

    public function getSubheading(): ?string
    {
        return 'Landing page publik /alamat/{slug}. Pilih Kota/Grup sebagai cakupan, lalu pilih gerobak yang ditampilkan.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Tambah Halaman'),
        ];
    }
}
