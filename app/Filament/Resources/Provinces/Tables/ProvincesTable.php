<?php

namespace App\Filament\Resources\Provinces\Tables;

use App\Enums\PanelPermission;
use App\Filament\Support\ReorderGate;
use App\Models\Province;
use App\Services\LocationPageCatalogService;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProvincesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            /*
             | Provinsi berurutan global, jadi tidak perlu filter induk --
             | tetapi tetap butuh permission dan tetap ditutup saat pencarian
             | aktif (baris tersaring tidak mewakili seluruh urutan).
             */
            ->reorderable(
                'sort_order',
                condition: fn ($livewire): bool => ReorderGate::allows(
                    $livewire,
                    PanelPermission::ManageLocationProvinces,
                    null,
                ),
            )
            ->reorderRecordsTriggerAction(fn ($action) => $action->label('Atur Urutan'))
            /*
             | Filament menyimpan urutan baru lewat SATU query update, bukan
             | save() per model, jadi event model tidak berjalan dan cache
             | halaman tidak ikut basi dengan sendirinya.
             */
            ->afterReordering(fn () => LocationPageCatalogService::flushCache())
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount([
                'groups',
                'groups as active_groups_count' => fn (Builder $inner) => $inner->active(),
            ]))
            ->columns([
                TextColumn::make('name')
                    ->label('Provinsi')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('groups_count')
                    ->label('Kota/Grup')
                    ->badge()
                    ->color('gray')
                    ->alignCenter(),

                TextColumn::make('active_groups_count')
                    ->label('Grup aktif')
                    ->badge()
                    ->alignCenter()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'warning'),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean()
                    ->alignCenter()
                    ->tooltip(fn (Province $record): ?string => $record->is_active
                        ? null
                        : 'Nonaktif: seluruh gerobak di bawahnya berhenti tampil di halaman slug.'),

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
                    ->before(function (Province $record, DeleteAction $action): void {
                        if ($record->groups()->exists()) {
                            $action->failureNotificationTitle(
                                'Provinsi ini masih memiliki Kota/Grup. Pindahkan atau hapus grupnya lebih dulu.'
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
             | banyak provinsi sekaligus berarti mematikan seluruh turunannya
             | dalam satu klik tanpa pemeriksaan per baris.
             */
            ->toolbarActions([])
            ->description(fn ($livewire): ?string => ReorderGate::permits(PanelPermission::ManageLocationProvinces)
                && ! ReorderGate::showsOneCompleteScope($livewire, null)
                    ? 'Kosongkan pencarian untuk mengatur urutan dengan seret dan lepas.'
                    : null)
            ->emptyStateHeading('Belum ada provinsi')
            ->emptyStateDescription('Tambahkan provinsi lebih dulu, lalu Kota/Grup, Area, dan gerobaknya.');
    }
}
