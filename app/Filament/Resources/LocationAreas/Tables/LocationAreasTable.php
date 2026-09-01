<?php

namespace App\Filament\Resources\LocationAreas\Tables;

use App\Enums\PanelPermission;
use App\Filament\Support\ReorderGate;
use App\Models\LocationArea;
use App\Services\LocationPageCatalogService;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LocationAreasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            // Urutan Area hanya bermakna DI DALAM satu Kota/Grup.
            ->reorderable(
                'sort_order',
                condition: fn ($livewire): bool => ReorderGate::allows(
                    $livewire,
                    PanelPermission::ManageLocationAreas,
                    'location_group_id',
                ),
            )
            ->reorderRecordsTriggerAction(fn ($action) => $action->label('Atur Urutan'))
            /*
             | Filament menyimpan urutan baru lewat SATU query update, bukan
             | save() per model, jadi event model tidak berjalan dan cache
             | publik tidak ikut basi dengan sendirinya. Versi cache dinaikkan
             | di sini supaya halaman publik langsung memakai urutan baru.
             */
            ->afterReordering(fn () => LocationPageCatalogService::flushCache())
            /*
             | Filament merakit perintah update reorder dari Table::getQuery(),
             | dan getQuery() TIDAK menerapkan filter tabel. Jadi keanggotaan
             | induk diperiksa eksplisit di sini: id dari induk lain yang
             | disisipkan ke payload ditolak sebelum satu baris pun ditulis.
             */
            ->beforeReordering(fn (array $order, $livewire) => ReorderGate::assertBelongsToSelectedParent(
                $livewire,
                $order,
                LocationArea::class,
                'location_group_id',
                'location_group_id',
            ))
            // Eager load induk + count: query tidak bertambah per baris.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('group.province')
                ->withCount([
                    'locations',
                    'locations as visible_locations_count' => fn (Builder $inner) => $inner->active(),
                ]))
            ->columns([
                TextColumn::make('name')
                    ->label('Area')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('group.name')
                    ->label('Kota/Grup')
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('group.province.name')
                    ->label('Provinsi')
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('locations_count')
                    ->label('Gerobak')
                    ->badge()
                    ->color('gray')
                    ->alignCenter(),

                TextColumn::make('visible_locations_count')
                    ->label('Gerobak aktif')
                    ->badge()
                    ->alignCenter()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'warning'),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean()
                    ->alignCenter(),

                TextColumn::make('visibility_status')
                    ->label('Menyumbang gerobak')
                    ->badge()
                    // Dihitung sendiri: rantai induk tidak tersimpan sebagai
                    // satu kolom status.
                    ->state(fn (LocationArea $record): string => self::visibilityLabel($record))
                    ->color(fn (LocationArea $record): string => $record->isEffectivelyVisible() ? 'success' : 'gray')
                    ->tooltip(fn (LocationArea $record): ?string => $record->isActiveArea() && ! $record->isEffectivelyVisible()
                        ? 'Area ini aktif, tetapi Kota/Grup atau provinsinya nonaktif sehingga gerobaknya tidak tampil di halaman slug mana pun.'
                        : null),

                TextColumn::make('sort_order')
                    ->label('Urutan')
                    ->sortable()
                    ->alignCenter()
                    ->color('gray'),

                TextColumn::make('updated_at')
                    ->label('Diperbarui')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('location_group_id')
                    ->label('Kota/Grup')
                    ->relationship('group', 'name')
                    ->searchable()
                    ->preload(),

                TernaryFilter::make('is_active')
                    ->label('Status aktif')
                    ->trueLabel('Aktif')
                    ->falseLabel('Nonaktif')
                    ->placeholder('Semua'),

                Filter::make('needs_attention')
                    ->label('Aktif tanpa gerobak aktif')
                    ->query(fn (Builder $query): Builder => $query
                        ->effectivelyVisible()
                        ->whereDoesntHave('locations', fn (Builder $inner) => $inner->active())),

                TrashedFilter::make()->label('Data terhapus'),
            ])
            ->recordActions([
                EditAction::make()->label('Ubah'),

                DeleteAction::make()
                    ->label('Hapus')
                    // Pesan Indonesia yang menjelaskan sebabnya, bukan sekadar
                    // tombol yang hilang tanpa alasan.
                    ->before(function (LocationArea $record, DeleteAction $action): void {
                        if ($record->locations()->exists()) {
                            $action->failureNotificationTitle(
                                'Area ini masih memiliki gerobak. Pindahkan atau hapus gerobaknya lebih dulu.'
                            );
                            $action->failure();
                            $action->halt();
                        }
                    }),

                RestoreAction::make()->label('Pulihkan'),
                ForceDeleteAction::make()->label('Hapus permanen'),
            ])
            /*
             | Bulk action penghapusan SENGAJA tidak disediakan. Menghapus
             | banyak wilayah sekaligus berarti mematikan banyak URL iklan
             | dalam satu klik tanpa pemeriksaan per baris.
             */
            ->toolbarActions([])
            ->description(fn ($livewire): ?string => ReorderGate::permits(PanelPermission::ManageLocationAreas)
                && ! ReorderGate::showsOneCompleteScope($livewire, 'location_group_id')
                    ? ReorderGate::hint('Kota/Grup')
                    : null)
            ->emptyStateHeading('Belum ada area')
            ->emptyStateDescription('Tambahkan Kota/Grup lebih dulu, lalu buat Area di bawahnya.');
    }

    protected static function visibilityLabel(LocationArea $record): string
    {
        if (! $record->isActiveArea()) {
            return 'Nonaktif';
        }

        return $record->isEffectivelyVisible() ? 'Ya' : 'Induk nonaktif';
    }
}
