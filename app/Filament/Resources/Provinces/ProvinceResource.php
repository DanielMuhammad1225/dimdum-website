<?php

namespace App\Filament\Resources\Provinces;

use App\Enums\PanelPermission;
use App\Filament\Resources\Provinces\Pages\CreateProvince;
use App\Filament\Resources\Provinces\Pages\EditProvince;
use App\Filament\Resources\Provinces\Pages\ListProvinces;
use App\Filament\Resources\Provinces\Schemas\ProvinceForm;
use App\Filament\Resources\Provinces\Tables\ProvincesTable;
use App\Models\Province;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * Provinsi -- tingkat teratas hierarki lokasi.
 *
 * Authorization sepenuhnya berasal dari ProvincePolicy (server-side).
 * Filament memakai policy yang sama untuk menyembunyikan navigasi DAN untuk
 * menolak akses URL langsung, jadi keduanya tidak mungkin berbeda.
 */
class ProvinceResource extends Resource
{
    protected static ?string $model = Province::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAsiaAustralia;

    protected static string|UnitEnum|null $navigationGroup = 'Lokasi Gerobak';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'provinsi';

    protected static ?string $modelLabel = 'Provinsi';

    protected static ?string $pluralModelLabel = 'Provinsi';

    protected static ?string $navigationLabel = 'Provinsi';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ProvinceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProvincesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProvinces::route('/'),
            'create' => CreateProvince::route('/create'),
            'edit' => EditProvince::route('/{record}/edit'),
        ];
    }

    /**
     * Operator tetap melihat daftar provinsi (read-only) karena ia butuh
     * memilih provinsi saat membuat gerobak. Kemampuan mengubahnya dijaga
     * terpisah oleh ProvincePolicy.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->can(PanelPermission::ViewLocations->value) ?? false;
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
