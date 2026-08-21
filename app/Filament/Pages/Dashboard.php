<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;

/**
 * Dashboard admin DIMDUM.
 *
 * Sengaja hanya menampilkan informasi yang benar-benar ada. Tidak ada kartu
 * statistik (produk/outlet/campaign) karena tabelnya memang belum dibuat.
 */
class Dashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?string $title = 'Dashboard';

    protected static ?int $navigationSort = -1;

    protected string $view = 'filament.pages.dashboard';

    public static function getSlug(?Panel $panel = null): string
    {
        return '/';
    }

    /**
     * @return array<string, string>
     */
    public function getViewData(): array
    {
        $user = auth()->user();

        return [
            'userName' => $user?->name ?? '',
            'roleLabel' => $user?->primaryRoleLabel() ?? 'Tanpa Role',
        ];
    }
}
