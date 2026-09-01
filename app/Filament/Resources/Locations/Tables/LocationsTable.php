<?php

namespace App\Filament\Resources\Locations\Tables;

use App\Enums\PanelPermission;
use App\Filament\Support\ReorderGate;
use App\Models\Location;
use App\Services\LocationCatalogService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LocationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            /*
             | Gerobak boleh ditata Operator -- ia memang pengelola data harian
             | -- tetapi tetap hanya di dalam SATU Area yang sudah dipilih.
             */
            ->reorderable(
                'sort_order',
                condition: fn ($livewire): bool => ReorderGate::allows(
                    $livewire,
                    PanelPermission::UpdateLocations,
                    'location_area_id',
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
                Location::class,
                'location_area_id',
                'location_area_id',
            ))
            // Eager load + count: jumlah query tidak bertambah per baris.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('area.group.province')
                ->withCount('images'))
            ->columns([
                TextColumn::make('name')
                    ->label('Gerobak')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('area.name')
                    ->label('Area')
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('area.group.name')
                    ->label('Kota/Grup')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),

                TextColumn::make('area.group.province.name')
                    ->label('Provinsi')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),

                TextColumn::make('district')
                    ->label('Area administratif')
                    ->formatStateUsing(fn (Location $record): string => collect([
                        $record->village,
                        $record->district,
                        $record->city_regency,
                    ])->filter()->implode(', ') ?: '-')
                    ->searchable(['village', 'district', 'city_regency'])
                    ->wrap()
                    ->color('gray'),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean()
                    ->alignCenter(),

                TextColumn::make('publication_status')
                    ->label('Publikasi')
                    ->badge()
                    /*
                     | State dihitung sendiri, TIDAK dibaca dari kolom
                     | published_at: baris draft bernilai null dan Filament
                     | akan menampilkan placeholder kosong alih-alih memanggil
                     | formatStateUsing().
                     */
                    ->state(fn (Location $record): string => self::publicationLabel($record))
                    ->color(fn (Location $record): string => match (true) {
                        $record->isEffectivelyVisible() => 'success',
                        $record->published_at !== null => 'warning',
                        default => 'gray',
                    })
                    // Menjelaskan kenapa gerobak terbit tetap tidak tampil.
                    ->tooltip(fn (Location $record): ?string => $record->isPubliclyVisible() && ! $record->isEffectivelyVisible()
                        ? 'Gerobak ini sudah terbit, tetapi Area, Kota/Grup, atau provinsinya belum tampil sehingga belum terlihat pengunjung.'
                        : null),

                TextColumn::make('maps_status')
                    ->label('Peta')
                    ->badge()
                    ->state(fn (Location $record): string => $record->safeMapsUrl() !== null ? 'Tersedia' : 'Belum ada')
                    ->color(fn (Location $record): string => $record->safeMapsUrl() !== null ? 'success' : 'warning'),

                TextColumn::make('images_count')
                    ->label('Foto')
                    ->badge()
                    ->color('gray')
                    ->alignCenter(),

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
                SelectFilter::make('location_area_id')
                    ->label('Area')
                    ->relationship('area', 'name')
                    ->searchable()
                    ->preload(),

                TernaryFilter::make('is_active')
                    ->label('Status aktif')
                    ->trueLabel('Aktif')
                    ->falseLabel('Nonaktif')
                    ->placeholder('Semua'),

                Filter::make('published')
                    ->label('Sudah terbit')
                    ->query(fn (Builder $query): Builder => $query->published()),

                Filter::make('missing_maps')
                    ->label('Belum ada tautan peta')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereNull('latitude')
                        ->whereNull('google_maps_url')),

                TrashedFilter::make()->label('Data terhapus'),
            ])
            ->recordActions([
                Action::make('preview')
                    ->label('Lihat area')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->color('gray')
                    // Halaman detail gerobak belum ada, jadi preview mengarah
                    // ke halaman Area induknya.
                    ->visible(fn (Location $record): bool => $record->area?->isEffectivelyVisible() ?? false)
                    ->url(fn (Location $record): string => route(
                        'locations.area',
                        [$record->area->group->province->slug, $record->area->slug],
                    ), shouldOpenInNewTab: true),

                EditAction::make()->label('Ubah'),
                DeleteAction::make()->label('Hapus'),
                RestoreAction::make()->label('Pulihkan'),
                ForceDeleteAction::make()->label('Hapus permanen'),
            ])
            /*
             | Tidak ada bulk delete. Penghapusan gerobak diperiksa satu per
             | satu lewat policy, termasuk pembersihan berkasnya.
             */
            ->toolbarActions([])
            ->description(fn ($livewire): ?string => ReorderGate::permits(PanelPermission::UpdateLocations)
                && ! ReorderGate::showsOneCompleteScope($livewire, 'location_area_id')
                    ? ReorderGate::hint('Area')
                    : null)
            ->emptyStateHeading('Belum ada gerobak')
            ->emptyStateDescription('Tambahkan gerobak dan hubungkan ke Area yang sesuai.');
    }

    protected static function publicationLabel(Location $record): string
    {
        if ($record->published_at === null) {
            return 'Draft';
        }

        if ($record->published_at->isFuture()) {
            return 'Terjadwal';
        }

        if (! $record->is_active) {
            return 'Nonaktif';
        }

        return $record->isEffectivelyVisible() ? 'Tampil' : 'Induk belum tampil';
    }
}
