<?php

namespace App\Filament\Resources\SocialLinks\Tables;

use App\Enums\PanelPermission;
use App\Enums\SocialIcon;
use App\Filament\Support\ReorderGate;
use App\Models\SocialLink;
use App\Services\BioCatalogService;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class SocialLinksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            /*
             | Tautan berurutan GLOBAL -- satu daftar di halaman Bio -- jadi
             | tidak ada induk yang perlu dipilih. Reorder tetap butuh
             | permission dan tetap ditutup saat pencarian aktif, karena baris
             | tersaring tidak mewakili keseluruhan urutan.
             */
            ->reorderable(
                'sort_order',
                condition: fn ($livewire): bool => ReorderGate::allows(
                    $livewire,
                    PanelPermission::ManageSocialLinks,
                    null,
                ),
            )
            ->reorderRecordsTriggerAction(fn ($action) => $action->label('Atur Urutan'))
            /*
             | Filament menyimpan urutan baru lewat SATU query update tanpa
             | event model, jadi versi cache Bio dinaikkan sendiri di sini.
             */
            ->afterReordering(fn () => BioCatalogService::flushCache())
            ->columns([
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->weight('bold'),

                TextColumn::make('icon')
                    ->label('Ikon')
                    ->badge()
                    ->color('gray')
                    ->state(fn (SocialLink $record): string => SocialIcon::resolve($record->icon)->label()),

                TextColumn::make('url')
                    ->label('URL')
                    ->limit(48)
                    ->tooltip(fn (SocialLink $record): string => $record->url)
                    ->color('gray')
                    ->searchable(),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean()
                    ->alignCenter(),

                TextColumn::make('sort_order')
                    ->label('Urutan')
                    ->alignCenter()
                    ->color('gray'),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Status aktif')
                    ->trueLabel('Aktif')
                    ->falseLabel('Nonaktif')
                    ->placeholder('Semua'),
            ])
            ->recordActions([
                EditAction::make()->label('Ubah'),
                DeleteAction::make()->label('Hapus'),
            ])
            ->toolbarActions([])
            ->description(fn ($livewire): ?string => ReorderGate::permits(PanelPermission::ManageSocialLinks)
                && ! ReorderGate::showsOneCompleteScope($livewire, null)
                    ? 'Kosongkan pencarian untuk mengatur urutan dengan seret dan lepas.'
                    : null)
            ->emptyStateHeading('Belum ada social media')
            ->emptyStateDescription('Tambahkan tautan social media untuk ditampilkan di halaman Bio.');
    }
}
