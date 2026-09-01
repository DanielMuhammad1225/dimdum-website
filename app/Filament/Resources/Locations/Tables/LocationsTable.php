<?php

namespace App\Filament\Resources\Locations\Tables;

use App\Models\Location;
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
            ->reorderable('sort_order')
            // Eager load + count: jumlah query tidak bertambah per baris.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('area')
                ->withCount('images'))
            ->columns([
                TextColumn::make('name')
                    ->label('Gerobak')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('area.name')
                    ->label('Wilayah')
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('district')
                    ->label('Area administratif')
                    ->formatStateUsing(fn (Location $record): string => collect([
                        $record->district,
                        $record->city_regency,
                        $record->province,
                    ])->filter()->implode(', ') ?: '-')
                    ->searchable(['district', 'city_regency', 'province'])
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
                        ? 'Gerobak ini sudah terbit, tetapi wilayahnya belum tampil publik sehingga belum terlihat pengunjung.'
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
                    ->label('Wilayah')
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
                    ->label('Lihat wilayah')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->color('gray')
                    // Halaman detail gerobak belum ada, jadi preview mengarah
                    // ke landing wilayahnya.
                    ->visible(fn (Location $record): bool => $record->area?->isPubliclyVisible() ?? false)
                    ->url(fn (Location $record): string => route('locations.area', $record->area->slug), shouldOpenInNewTab: true),

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
            ->emptyStateHeading('Belum ada gerobak')
            ->emptyStateDescription('Tambahkan gerobak dan hubungkan ke wilayah landing yang sesuai.');
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

        return $record->isEffectivelyVisible() ? 'Tampil' : 'Wilayah belum terbit';
    }
}
