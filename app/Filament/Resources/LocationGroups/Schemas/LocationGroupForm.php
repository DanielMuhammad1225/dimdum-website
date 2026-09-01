<?php

namespace App\Filament\Resources\LocationGroups\Schemas;

use App\Enums\LocationGroupType;
use App\Enums\PanelPermission;
use App\Models\Province;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Form Kota/Grup -- MASTER DATA saja.
 *
 * Tanpa slug, deskripsi, maupun SEO: Kota/Grup tidak punya halaman.
 *
 * Perannya justru menjadi lebih penting: Kota/Grup adalah SATUAN CAKUPAN yang
 * dipilih Halaman Slug Lokasi. Memilih satu Kota/Grup pada sebuah halaman
 * membuka seluruh gerobak di SEMUA Area di bawahnya sebagai kandidat.
 */
class LocationGroupForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identitas Kota/Grup')
                    ->description('Kota/Grup adalah satuan cakupan yang dipilih Halaman Slug Lokasi.')
                    ->schema([
                        Select::make('province_id')
                            ->label('Provinsi')
                            ->helperText('Satu Kota/Grup wajib berada di tepat satu provinsi dan tidak boleh lintas provinsi.')
                            ->options(fn (): array => Province::query()
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->required()
                            ->native(false)
                            // Validasi server-side: id provinsi harus benar ada.
                            ->exists('provinces', 'id'),

                        TextInput::make('name')
                            ->label('Nama Kota/Grup')
                            ->placeholder('Contoh: Kabupaten Cianjur atau Bandung Raya')
                            ->required()
                            ->maxLength(120),

                        Select::make('type')
                            ->label('Tipe')
                            ->helperText(self::typeHelper())
                            ->options(LocationGroupType::options())
                            ->default(LocationGroupType::Administrative->value)
                            ->required()
                            ->native(false)
                            // Nilai di luar enum ditolak server-side.
                            ->in(LocationGroupType::values()),

                        Toggle::make('is_active')
                            ->label('Aktif')
                            ->helperText('Status operasional. Menonaktifkan grup membuat seluruh gerobak di bawahnya berhenti tampil di halaman slug mana pun.')
                            ->disabled(fn (): bool => ! self::canManage())
                            ->dehydrated(fn (): bool => self::canManage()),
                    ]),
            ]);
    }

    protected static function typeHelper(): string
    {
        return collect(LocationGroupType::cases())
            ->map(fn (LocationGroupType $type): string => $type->label().': '.$type->description())
            ->implode(' ');
    }

    protected static function canManage(): bool
    {
        return auth()->user()?->can(PanelPermission::ManageLocationGroups->value) ?? false;
    }
}
