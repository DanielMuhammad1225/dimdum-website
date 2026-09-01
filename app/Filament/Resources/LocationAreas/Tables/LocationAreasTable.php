<?php

namespace App\Filament\Resources\LocationAreas\Tables;

use App\Models\LocationArea;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
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
            ->reorderable('sort_order')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount([
                'locations',
                'locations as visible_locations_count' => fn (Builder $inner) => $inner->publiclyVisible(),
            ]))
            ->columns([
                TextColumn::make('name')
                    ->label('Wilayah')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Slug disalin')
                    ->color('gray'),

                TextColumn::make('locations_count')
                    ->label('Gerobak')
                    ->badge()
                    ->color('gray')
                    ->alignCenter(),

                TextColumn::make('visible_locations_count')
                    ->label('Tampil publik')
                    ->badge()
                    ->alignCenter()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'warning')
                    // Wilayah terbit tanpa gerobak tampil = destination iklan
                    // yang buruk. Diberi tanda jelas di daftar.
                    ->tooltip(fn (int $state, LocationArea $record): ?string => $state === 0 && $record->isPubliclyVisible()
                        ? 'Halaman ini terbit tetapi belum punya gerobak yang tampil. Belum ideal sebagai tujuan iklan.'
                        : null),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean()
                    ->alignCenter(),

                TextColumn::make('publication_status')
                    ->label('Publikasi')
                    ->badge()
                    // Dihitung sendiri: published_at null pada draft akan
                    // membuat Filament menampilkan sel kosong.
                    ->state(fn (LocationArea $record): string => self::publicationLabel($record))
                    ->color(fn (LocationArea $record): string => match (true) {
                        $record->isPubliclyVisible() => 'success',
                        $record->published_at !== null => 'warning',
                        default => 'gray',
                    }),

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

                Filter::make('published')
                    ->label('Sudah terbit')
                    ->query(fn (Builder $query): Builder => $query->published()),

                Filter::make('needs_attention')
                    ->label('Terbit tanpa gerobak tampil')
                    ->query(fn (Builder $query): Builder => $query
                        ->publiclyVisible()
                        ->whereDoesntHave('locations', fn (Builder $inner) => $inner->publiclyVisible())),

                TrashedFilter::make()->label('Data terhapus'),
            ])
            ->recordActions([
                Action::make('preview')
                    ->label('Lihat halaman')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->color('gray')
                    // Preview hanya untuk halaman yang benar-benar hidup.
                    ->visible(fn (LocationArea $record): bool => $record->isPubliclyVisible())
                    ->url(fn (LocationArea $record): string => route('locations.area', $record->slug), shouldOpenInNewTab: true),

                EditAction::make()->label('Ubah'),

                DeleteAction::make()
                    ->label('Hapus')
                    // Pesan Indonesia yang menjelaskan sebabnya, bukan sekadar
                    // tombol yang hilang tanpa alasan.
                    ->before(function (LocationArea $record, DeleteAction $action): void {
                        if ($record->locations()->exists()) {
                            $action->failureNotificationTitle(
                                'Wilayah ini masih memiliki gerobak. Pindahkan atau hapus gerobaknya lebih dulu.'
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
            ->emptyStateHeading('Belum ada wilayah landing')
            ->emptyStateDescription('Tambahkan wilayah pemasaran lebih dulu, lalu masukkan gerobaknya.');
    }

    protected static function publicationLabel(LocationArea $record): string
    {
        if ($record->published_at === null) {
            return 'Draft';
        }

        if ($record->published_at->isFuture()) {
            return 'Terjadwal';
        }

        return $record->is_active ? 'Terbit' : 'Nonaktif';
    }
}
