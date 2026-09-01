<?php

namespace App\Filament\Resources\LocationGroups\Tables;

use App\Enums\LocationGroupType;
use App\Enums\PanelPermission;
use App\Filament\Support\ReorderGate;
use App\Models\LocationGroup;
use App\Services\LocationCatalogService;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LocationGroupsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            // Urutan Kota/Grup hanya bermakna DI DALAM satu provinsi.
            ->reorderable(
                'sort_order',
                condition: fn ($livewire): bool => ReorderGate::allows(
                    $livewire,
                    PanelPermission::ManageLocationGroups,
                    'province_id',
                ),
            )
            ->reorderRecordsTriggerAction(fn ($action) => $action->label('Atur Urutan'))
            /*
             | Filament menyimpan urutan baru lewat SATU query update, bukan
             | save() per model, jadi event model tidak berjalan dan cache
             | publik tidak ikut basi dengan sendirinya. Versi cache dinaikkan
             | di sini supaya halaman publik langsung memakai urutan baru.
             */
            ->afterReordering(fn () => LocationCatalogService::flushCache())
            /*
             | Filament merakit perintah update reorder dari Table::getQuery(),
             | dan getQuery() TIDAK menerapkan filter tabel. Jadi keanggotaan
             | induk diperiksa eksplisit di sini: id dari induk lain yang
             | disisipkan ke payload ditolak sebelum satu baris pun ditulis.
             */
            ->beforeReordering(fn (array $order, $livewire) => ReorderGate::assertBelongsToSelectedParent(
                $livewire,
                $order,
                LocationGroup::class,
                'province_id',
                'province_id',
            ))
            // Eager load + count: jumlah query tidak bertambah per baris.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('province')
                ->withCount('areas'))
            ->columns([
                TextColumn::make('name')
                    ->label('Kota/Grup')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('province.name')
                    ->label('Provinsi')
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Tipe')
                    ->badge()
                    ->state(fn (LocationGroup $record): string => $record->typeLabel())
                    ->color(fn (LocationGroup $record): string => $record->type === LocationGroupType::Administrative
                        ? 'info'
                        : 'warning'),

                TextColumn::make('areas_count')
                    ->label('Area')
                    ->badge()
                    ->color('gray')
                    ->alignCenter(),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean()
                    ->alignCenter(),

                TextColumn::make('visibility_status')
                    ->label('Tampil publik')
                    ->badge()
                    // Dihitung sendiri: grup tidak punya kolom status tunggal.
                    ->state(fn (LocationGroup $record): string => self::visibilityLabel($record))
                    ->color(fn (LocationGroup $record): string => $record->isEffectivelyVisible() ? 'success' : 'gray')
                    ->tooltip(fn (LocationGroup $record): ?string => $record->isActiveGroup() && ! $record->isEffectivelyVisible()
                        ? 'Grup ini aktif, tetapi provinsinya belum terbit sehingga areanya belum terlihat pengunjung.'
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
                SelectFilter::make('province_id')
                    ->label('Provinsi')
                    ->relationship('province', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('type')
                    ->label('Tipe')
                    ->options(LocationGroupType::options()),

                TernaryFilter::make('is_active')
                    ->label('Status aktif')
                    ->trueLabel('Aktif')
                    ->falseLabel('Nonaktif')
                    ->placeholder('Semua'),

                TrashedFilter::make()->label('Data terhapus'),
            ])
            ->recordActions([
                EditAction::make()->label('Ubah'),

                DeleteAction::make()
                    ->label('Hapus')
                    ->before(function (LocationGroup $record, DeleteAction $action): void {
                        if ($record->areas()->exists()) {
                            $action->failureNotificationTitle(
                                'Kota/Grup ini masih memiliki Area. Pindahkan atau hapus areanya lebih dulu.'
                            );
                            $action->failure();
                            $action->halt();
                        }
                    }),

                RestoreAction::make()->label('Pulihkan'),
                ForceDeleteAction::make()->label('Hapus permanen'),
            ])
            // Tidak ada bulk delete: satu grup menaungi banyak URL publik.
            ->toolbarActions([])
            ->description(fn ($livewire): ?string => ReorderGate::permits(PanelPermission::ManageLocationGroups)
                && ! ReorderGate::showsOneCompleteScope($livewire, 'province_id')
                    ? ReorderGate::hint('provinsi')
                    : null)
            ->emptyStateHeading('Belum ada Kota/Grup')
            ->emptyStateDescription('Tambahkan Kota/Grup di bawah sebuah provinsi, lalu masukkan Area-nya.');
    }

    protected static function visibilityLabel(LocationGroup $record): string
    {
        if (! $record->isActiveGroup()) {
            return 'Nonaktif';
        }

        return $record->isEffectivelyVisible() ? 'Tampil' : 'Provinsi belum terbit';
    }
}
