<?php

namespace App\Filament\Resources\Provinces\Schemas;

use App\Enums\PanelPermission;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Form Provinsi -- MASTER DATA saja.
 *
 * Tidak ada slug, deskripsi landing, SEO, maupun waktu terbit di sini:
 * seluruhnya milik Halaman Slug Lokasi. Urutan juga tidak diminta -- provinsi
 * baru ditempatkan otomatis di posisi terakhir dan diurutkan lewat seret dan
 * lepas pada daftarnya.
 */
class ProvinceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identitas Provinsi')
                    ->description('Provinsi adalah data induk. Halaman publiknya dibuat terpisah di menu Halaman Slug Lokasi.')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nama provinsi')
                            ->placeholder('Contoh: Jawa Barat')
                            ->required()
                            ->maxLength(120),

                        Toggle::make('is_active')
                            ->label('Aktif')
                            ->helperText('Status operasional, bukan status publikasi. Menonaktifkan provinsi membuat SELURUH gerobak di bawahnya berhenti tampil di halaman slug mana pun.')
                            ->disabled(fn (): bool => ! self::canManage())
                            ->dehydrated(fn (): bool => self::canManage()),
                    ]),
            ]);
    }

    protected static function canManage(): bool
    {
        return auth()->user()?->can(PanelPermission::ManageLocationProvinces->value) ?? false;
    }
}
