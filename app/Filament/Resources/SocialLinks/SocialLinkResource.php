<?php

namespace App\Filament\Resources\SocialLinks;

use App\Enums\PanelPermission;
use App\Filament\Resources\SocialLinks\Pages\CreateSocialLink;
use App\Filament\Resources\SocialLinks\Pages\EditSocialLink;
use App\Filament\Resources\SocialLinks\Pages\ListSocialLinks;
use App\Filament\Resources\SocialLinks\Schemas\SocialLinkForm;
use App\Filament\Resources\SocialLinks\Tables\SocialLinksTable;
use App\Models\SocialLink;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Tautan social media yang tampil di halaman Bio.
 *
 * Authorization sepenuhnya berasal dari SocialLinkPolicy (server-side).
 * Filament memakai policy yang sama untuk menyembunyikan navigasi DAN untuk
 * menolak akses URL langsung, jadi keduanya tidak mungkin berbeda.
 */
class SocialLinkResource extends Resource
{
    protected static ?string $model = SocialLink::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShare;

    protected static string|UnitEnum|null $navigationGroup = 'Bio';

    // Setelah Pengaturan Bio (10).
    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'social-media';

    protected static ?string $modelLabel = 'Social Media';

    protected static ?string $pluralModelLabel = 'Social Media';

    protected static ?string $navigationLabel = 'Social Media';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return SocialLinkForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SocialLinksTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSocialLinks::route('/'),
            'create' => CreateSocialLink::route('/create'),
            'edit' => EditSocialLink::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(PanelPermission::ManageSocialLinks->value) ?? false;
    }
}
