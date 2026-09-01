<?php

namespace App\Filament\Resources\LocationGroups\Pages;

use App\Filament\Resources\LocationGroups\LocationGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLocationGroups extends ListRecords
{
    protected static string $resource = LocationGroupResource::class;

    public function getTitle(): string
    {
        return 'Kota / Grup';
    }

    public function getSubheading(): ?string
    {
        return 'Pengelompokan Area di dalam satu provinsi. Tidak punya halaman publik sendiri.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Tambah Kota/Grup'),
        ];
    }
}
