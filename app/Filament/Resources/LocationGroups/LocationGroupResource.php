<?php

namespace App\Filament\Resources\LocationGroups;

use App\Enums\PanelPermission;
use App\Filament\Resources\LocationGroups\Pages\CreateLocationGroup;
use App\Filament\Resources\LocationGroups\Pages\EditLocationGroup;
use App\Filament\Resources\LocationGroups\Pages\ListLocationGroups;
use App\Filament\Resources\LocationGroups\Schemas\LocationGroupForm;
use App\Filament\Resources\LocationGroups\Tables\LocationGroupsTable;
use App\Models\LocationGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * Kota/Grup -- pengelompokan Area di dalam satu provinsi.
 *
 * Tidak punya halaman publik: ia hanya menjadi heading di halaman provinsi.
 * Authorization sepenuhnya berasal dari LocationGroupPolicy (server-side).
 */
class LocationGroupResource extends Resource
{
    protected static ?string $model = LocationGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Lokasi Gerobak';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'kota-grup';

    protected static ?string $modelLabel = 'Kota / Grup';

    protected static ?string $pluralModelLabel = 'Kota / Grup';

    protected static ?string $navigationLabel = 'Kota / Grup';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return LocationGroupForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LocationGroupsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLocationGroups::route('/'),
            'create' => CreateLocationGroup::route('/create'),
            'edit' => EditLocationGroup::route('/{record}/edit'),
        ];
    }

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
