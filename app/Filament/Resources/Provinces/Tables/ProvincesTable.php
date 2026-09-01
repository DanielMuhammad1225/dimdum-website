<?php

namespace App\Filament\Resources\Provinces\Tables;

use App\Models\Province;
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

class ProvincesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
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

                TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Slug disalin')
                    ->color('gray'),

                TextColumn::make('groups_count')
                    ->label('Kota/Grup')
                    ->badge()
                    ->color('gray')
                    ->alignCenter(),

                TextColumn::make('active_groups_count')
                    ->label('Grup aktif')
                    ->badge()
                    ->alignCenter()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'warning')
                    // Provinsi terbit tanpa grup aktif = halaman kosong.
                    ->tooltip(fn (int $state, Province $record): ?string => $state === 0 && $record->isPubliclyVisible()
                        ? 'Provinsi ini terbit tetapi belum punya Kota/Grup aktif, sehingga halamannya masih kosong.'
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
                    ->state(fn (Province $record): string => self::publicationLabel($record))
                    ->color(fn (Province $record): string => match (true) {
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

                TrashedFilter::make()->label('Data terhapus'),
            ])
            ->recordActions([
                Action::make('preview')
                    ->label('Lihat halaman')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->color('gray')
                    ->visible(fn (Province $record): bool => $record->isPubliclyVisible())
                    ->url(fn (Province $record): string => route('locations.province', $record->slug), shouldOpenInNewTab: true),

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
             | banyak provinsi sekaligus berarti mematikan seluruh URL iklan
             | di bawahnya dalam satu klik tanpa pemeriksaan per baris.
             */
            ->toolbarActions([])
            ->emptyStateHeading('Belum ada provinsi')
            ->emptyStateDescription('Tambahkan provinsi lebih dulu, lalu Kota/Grup, Area, dan gerobaknya.');
    }

    protected static function publicationLabel(Province $record): string
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
