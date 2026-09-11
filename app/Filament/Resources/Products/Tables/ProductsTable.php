<?php

namespace App\Filament\Resources\Products\Tables;

use App\Enums\PanelPermission;
use App\Enums\ProductCategory;
use App\Enums\ProductType;
use App\Filament\Support\ReorderGate;
use App\Filament\Support\UploadedImage;
use App\Models\Product;
use App\Services\LocationPageCatalogService;
use App\Services\ProductCatalogService;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            /*
             | Produk berurutan GLOBAL -- urutannya langsung menentukan enam
             | kartu mana yang tampil di homepage -- jadi tidak ada filter
             | induk yang perlu dipilih lebih dulu. Reorder tetap butuh
             | permission dan tetap ditutup saat pencarian aktif, karena baris
             | tersaring tidak mewakili keseluruhan urutan.
             */
            ->reorderable(
                'sort_order',
                condition: fn ($livewire): bool => ReorderGate::allows(
                    $livewire,
                    PanelPermission::UpdateProducts,
                    null,
                ),
            )
            ->reorderRecordsTriggerAction(fn ($action) => $action->label('Atur Urutan'))
            /*
             | Filament menyimpan urutan baru lewat SATU query update, bukan
             | save() per model, jadi event model tidak berjalan dan cache
             | katalog tidak ikut basi dengan sendirinya.
             |
             | Dua versi dinaikkan: urutan produk juga menentukan susunan kartu
             | pada section produk Halaman Slug Lokasi, jadi cache halaman itu
             | ikut basi.
             */
            ->afterReordering(function (): void {
                ProductCatalogService::flushCache();
                LocationPageCatalogService::flushCache();
            })
            ->columns([
                ImageColumn::make('image_path')
                    ->label('Foto')
                    ->disk('public')
                    ->square()
                    // Produk tanpa foto menampilkan emoji-nya, bukan kotak
                    // gambar rusak.
                    ->defaultImageUrl(null)
                    ->toggleable(),

                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (Product $record): ?string => $record->fallbackEmoji() !== null && blank($record->image_path)
                        ? 'Kartu memakai emoji '.$record->fallbackEmoji()
                        : null),

                TextColumn::make('category')
                    ->label('Kategori')
                    ->badge()
                    ->color('info')
                    ->state(fn (Product $record): string => $record->categoryLabel())
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Tipe')
                    ->badge()
                    ->color('gray')
                    ->state(fn (Product $record): string => $record->typeLabel())
                    ->sortable(),

                TextColumn::make('price_label')
                    ->label('Harga')
                    // Dihitung sendiri: harga efektif berasal dari dua kolom,
                    // jadi tidak bisa dibaca langsung dari satu kolom.
                    ->state(fn (Product $record): string => $record->priceLabel() ?? '—')
                    ->color(fn (Product $record): string => $record->priceLabel() === null ? 'gray' : 'success'),

                IconColumn::make('show_on_homepage')
                    ->label('Homepage')
                    ->boolean()
                    ->alignCenter(),

                IconColumn::make('show_on_bio')
                    ->label('Bio')
                    ->boolean()
                    ->alignCenter()
                    ->toggleable(),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean()
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
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label('Kategori')
                    ->options(ProductCategory::options()),

                SelectFilter::make('type')
                    ->label('Tipe')
                    ->options(ProductType::options()),

                TernaryFilter::make('is_active')
                    ->label('Status aktif')
                    ->trueLabel('Aktif')
                    ->falseLabel('Nonaktif')
                    ->placeholder('Semua'),

                TernaryFilter::make('show_on_homepage')
                    ->label('Tampil di homepage')
                    ->trueLabel('Ditampilkan')
                    ->falseLabel('Tidak')
                    ->placeholder('Semua'),

                TernaryFilter::make('show_on_bio')
                    ->label('Tampil di Bio')
                    ->trueLabel('Ditampilkan')
                    ->falseLabel('Tidak')
                    ->placeholder('Semua'),

                TrashedFilter::make()->label('Data terhapus'),
            ])
            ->recordActions([
                EditAction::make()->label('Ubah'),
                DeleteAction::make()->label('Hapus'),
                RestoreAction::make()->label('Pulihkan'),
                ForceDeleteAction::make()
                    ->label('Hapus permanen')
                    // Foto dibuang SETELAH barisnya benar-benar hilang. Soft
                    // delete sengaja tidak menyentuh berkas, supaya pemulihan
                    // mengembalikan produk beserta fotonya.
                    ->after(fn (Product $record) => UploadedImage::deleteManagedFile($record->image_path)),
            ])
            /*
             | Bulk action penghapusan SENGAJA tidak disediakan: menghapus
             | banyak produk sekaligus berarti mengosongkan kartu homepage
             | dalam satu klik tanpa pemeriksaan per baris.
             */
            ->toolbarActions([])
            ->description(fn ($livewire): ?string => ReorderGate::permits(PanelPermission::UpdateProducts)
                && ! ReorderGate::showsOneCompleteScope($livewire, null)
                    ? 'Kosongkan pencarian untuk mengatur urutan dengan seret dan lepas.'
                    : null)
            ->emptyStateHeading('Belum ada produk')
            ->emptyStateDescription('Tambahkan produk untuk mengisi kartu di homepage dan halaman /produk.');
    }
}
