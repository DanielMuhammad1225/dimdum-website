<?php

namespace App\Filament\Resources\LocationPages\Tables;

use App\Enums\PanelPermission;
use App\Filament\Support\ReorderGate;
use App\Models\LocationPage;
use App\Services\LocationPageCatalogService;
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

class LocationPagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            /*
             | Halaman berurutan global (urutannya menentukan tampilan di
             | homepage), jadi tidak perlu filter induk -- tetapi tetap butuh
             | permission dan tetap ditutup saat pencarian aktif, karena baris
             | tersaring tidak mewakili seluruh urutan.
             */
            ->reorderable(
                'sort_order',
                condition: fn ($livewire): bool => ReorderGate::allows(
                    $livewire,
                    PanelPermission::UpdateLocationPages,
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
            // Count, bukan eager load penuh: query tidak bertambah per baris.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount([
                'groups',
                'locations',
                'locations as visible_locations_count' => fn (Builder $inner) => $inner->effectivelyVisible(),
            ]))
            ->columns([
                TextColumn::make('title')
                    ->label('Judul')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('slug')
                    ->label('Slug')
                    ->prefix('/alamat/')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Slug disalin')
                    ->color('gray'),

                TextColumn::make('groups_count')
                    ->label('Kota/Grup')
                    ->badge()
                    ->color('gray')
                    ->alignCenter(),

                TextColumn::make('visible_locations_count')
                    ->label('Gerobak tampil')
                    ->badge()
                    ->alignCenter()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'warning')
                    // Halaman aktif tanpa gerobak tampil = tujuan iklan yang
                    // buruk. Diberi tanda jelas di daftar.
                    ->tooltip(fn (int $state, LocationPage $record): ?string => $state === 0 && $record->isPubliclyVisible()
                        ? 'Halaman ini aktif tetapi tidak punya gerobak yang tampil. Belum ideal sebagai tujuan iklan.'
                        : null),

                IconColumn::make('is_featured')
                    ->label('Homepage')
                    ->boolean()
                    ->alignCenter()
                    ->toggleable(),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean()
                    ->alignCenter(),

                TextColumn::make('publication_status')
                    ->label('Status')
                    ->badge()
                    // Dihitung sendiri: status efektif tidak tersimpan sebagai
                    // satu kolom, jadi tidak bisa dibaca langsung.
                    ->state(fn (LocationPage $record): string => $record->statusLabel())
                    ->color(fn (LocationPage $record): string => match ($record->statusLabel()) {
                        'AKTIF' => 'success',
                        'DI LUAR PERIODE' => 'warning',
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

                TernaryFilter::make('is_featured')
                    ->label('Tampil di homepage')
                    ->trueLabel('Ditampilkan')
                    ->falseLabel('Tidak')
                    ->placeholder('Semua'),

                Filter::make('published')
                    ->label('Tampil publik')
                    ->query(fn (Builder $query): Builder => $query->publiclyVisible()),

                Filter::make('needs_attention')
                    ->label('Aktif tanpa gerobak tampil')
                    ->query(fn (Builder $query): Builder => $query
                        ->publiclyVisible()
                        ->whereDoesntHave('locations', fn (Builder $inner) => $inner->effectivelyVisible())),

                SelectFilter::make('groups')
                    ->label('Kota/Grup')
                    ->relationship('groups', 'name')
                    ->searchable()
                    ->preload(),

                TrashedFilter::make()->label('Data terhapus'),
            ])
            ->recordActions([
                Action::make('preview')
                    ->label('Lihat halaman')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->color('gray')
                    ->visible(fn (LocationPage $record): bool => $record->isPubliclyVisible())
                    ->url(fn (LocationPage $record): string => route('location-pages.show', $record->slug), shouldOpenInNewTab: true),

                EditAction::make()->label('Ubah'),
                DeleteAction::make()->label('Hapus'),
                RestoreAction::make()->label('Pulihkan'),
                ForceDeleteAction::make()->label('Hapus permanen'),
            ])
            /*
             | Bulk action penghapusan SENGAJA tidak disediakan. Menghapus
             | banyak halaman sekaligus berarti mematikan beberapa URL iklan
             | dalam satu klik tanpa pemeriksaan per baris.
             */
            ->toolbarActions([])
            ->description(fn ($livewire): ?string => ReorderGate::permits(PanelPermission::UpdateLocationPages)
                && ! ReorderGate::showsOneCompleteScope($livewire, null)
                    ? 'Kosongkan pencarian untuk mengatur urutan dengan seret dan lepas.'
                    : null)
            ->emptyStateHeading('Belum ada halaman lokasi')
            ->emptyStateDescription('Siapkan Provinsi, Kota/Grup, Area, dan gerobaknya lebih dulu, lalu buat halaman di sini.');
    }
}
