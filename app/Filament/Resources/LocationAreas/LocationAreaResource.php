<?php

namespace App\Filament\Resources\LocationAreas;

use App\Enums\PanelPermission;
use App\Filament\Resources\LocationAreas\Pages\CreateLocationArea;
use App\Filament\Resources\LocationAreas\Pages\EditLocationArea;
use App\Filament\Resources\LocationAreas\Pages\ListLocationAreas;
use App\Filament\Resources\LocationAreas\Schemas\LocationAreaForm;
use App\Filament\Resources\LocationAreas\Tables\LocationAreasTable;
use App\Models\LocationArea;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * Wilayah landing -- area pemasaran publik yang menjadi destination iklan.
 *
 * Authorization sepenuhnya berasal dari LocationAreaPolicy (server-side).
 * Filament memakai policy yang sama untuk menyembunyikan navigasi DAN untuk
 * menolak akses URL langsung, jadi keduanya tidak mungkin berbeda.
 */
class LocationAreaResource extends Resource
{
    protected static ?string $model = LocationArea::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static string|UnitEnum|null $navigationGroup = 'Lokasi Gerobak';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'wilayah-landing';

    protected static ?string $modelLabel = 'Wilayah Landing';

    protected static ?string $pluralModelLabel = 'Wilayah Landing';

    protected static ?string $navigationLabel = 'Wilayah Landing';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return LocationAreaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LocationAreasTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLocationAreas::route('/'),
            'create' => CreateLocationArea::route('/create'),
            'edit' => EditLocationArea::route('/{record}/edit'),
        ];
    }

    /**
     * Navigasi dan akses memakai sumber yang sama: permission view_locations
     * lewat policy. Operator tetap melihat daftar wilayah (read-only) karena
     * ia butuh memilih wilayah saat membuat gerobak.
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
