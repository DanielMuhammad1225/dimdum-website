<?php

namespace App\Filament\Resources\LocationPages;

use App\Enums\PanelPermission;
use App\Filament\Resources\LocationPages\Pages\CreateLocationPage;
use App\Filament\Resources\LocationPages\Pages\EditLocationPage;
use App\Filament\Resources\LocationPages\Pages\ListLocationPages;
use App\Filament\Resources\LocationPages\Schemas\LocationPageForm;
use App\Filament\Resources\LocationPages\Tables\LocationPagesTable;
use App\Models\LocationPage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * Halaman Slug Lokasi -- satu-satunya modul yang membuat URL publik lokasi.
 *
 * Berada di grup navigasi yang sama dengan master hierarki, tetapi paling
 * bawah: hierarki disiapkan dulu, halaman dibuat kemudian.
 *
 * Authorization sepenuhnya berasal dari LocationPagePolicy (server-side).
 * Filament memakai policy yang sama untuk menyembunyikan navigasi DAN untuk
 * menolak akses URL langsung, jadi keduanya tidak mungkin berbeda.
 */
class LocationPageResource extends Resource
{
    protected static ?string $model = LocationPage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Lokasi Gerobak';

    // Setelah Provinsi (10), Kota/Grup (20), Area (30), dan Gerobak (40).
    protected static ?int $navigationSort = 50;

    protected static ?string $slug = 'halaman-lokasi';

    protected static ?string $modelLabel = 'Halaman Slug Lokasi';

    protected static ?string $pluralModelLabel = 'Halaman Slug Lokasi';

    protected static ?string $navigationLabel = 'Halaman Slug Lokasi';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return LocationPageForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LocationPagesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLocationPages::route('/'),
            'create' => CreateLocationPage::route('/create'),
            'edit' => EditLocationPage::route('/{record}/edit'),
        ];
    }

    /**
     * Operator TIDAK punya view_location_pages, jadi menu ini tidak muncul
     * untuknya dan URL langsungnya ditolak 403.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->can(PanelPermission::ViewLocationPages->value) ?? false;
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
