<?php

namespace App\Filament\Resources\SocialLinks\Pages;

use App\Filament\Resources\SocialLinks\SocialLinkResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSocialLinks extends ListRecords
{
    protected static string $resource = SocialLinkResource::class;

    public function getTitle(): string
    {
        return 'Social Media';
    }

    public function getSubheading(): ?string
    {
        return 'Tautan yang tampil di halaman Bio, sesuai urutan di tabel ini.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Tambah Social Media'),
        ];
    }
}
