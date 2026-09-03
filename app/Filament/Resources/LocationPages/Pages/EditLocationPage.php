<?php

namespace App\Filament\Resources\LocationPages\Pages;

use App\Filament\Resources\LocationPages\LocationPageResource;
use App\Filament\Support\UploadedImage;
use App\Models\LocationPage;
use App\Services\LocationPageProductService;
use App\Services\LocationPageScopeService;
use App\Services\LocationPageSlugService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditLocationPage extends EditRecord
{
    protected static string $resource = LocationPageResource::class;

    public function getTitle(): string
    {
        return 'Ubah Halaman Slug Lokasi';
    }

    /**
     * group_ids dan location_ids bukan kolom, jadi diisi ulang dari relasi
     * setiap kali form dibuka.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var LocationPage $record */
        $record = $this->getRecord();

        $data['group_ids'] = $record->groups()->pluck('location_groups.id')->all();
        $data['location_ids'] = $record->locations()->pluck('locations.id')->all();

        /*
         | Diisi ulang APA PUN status saklarnya. Pilihan yang tersimpan harus
         | sudah terpasang di komponennya sebelum saklar dinyalakan, supaya
         | menyalakannya memunculkan pilihan lama -- bukan daftar kosong.
         */
        $data['product_ids'] = $record->products()->pluck('products.id')->all();

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('Lihat halaman')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->visible(fn (LocationPage $record): bool => $record->isPubliclyVisible())
                ->url(fn (LocationPage $record): string => route('location-pages.show', $record->slug), shouldOpenInNewTab: true),

            DeleteAction::make()->label('Hapus'),
            RestoreAction::make()->label('Pulihkan'),

            /*
             | Force delete membersihkan poster SETELAH baris benar-benar
             | terhapus. Soft delete sengaja tidak menyentuh berkas sama sekali,
             | supaya pemulihan mengembalikan halaman beserta posternya.
             */
            ForceDeleteAction::make()
                ->label('Hapus permanen')
                ->before(function (LocationPage $record): void {
                    // Pivot dilepas dulu: FK gerobak dan Kota/Grup memakai
                    // restrictOnDelete, jadi barisnya harus bersih lebih dulu.
                    DB::transaction(function () use ($record): void {
                        $record->groups()->detach();
                        $record->locations()->detach();
                        // Pivot produk sudah cascade di level database; dilepas
                        // di sini juga supaya seluruh pelepasan relasi halaman
                        // terbaca di satu tempat.
                        $record->products()->detach();
                    });
                })
                ->after(function (LocationPage $record): void {
                    UploadedImage::deleteManagedFile($record->poster_path);
                }),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var LocationPage $record */
        $slugService = app(LocationPageSlugService::class);
        $scope = app(LocationPageScopeService::class);

        $products = app(LocationPageProductService::class);

        $groupIds = array_map('intval', (array) ($data['group_ids'] ?? []));
        $locationIds = array_map('intval', (array) ($data['location_ids'] ?? []));
        $requestedSlug = $data['slug'] ?? null;
        $previousPoster = $record->poster_path;

        /*
         | Saklar section produk mati -> field-nya tersembunyi -> Filament
         | membuang state-nya, sehingga key ini TIDAK ADA. Itulah tandanya
         | relasi produk tidak boleh disentuh sama sekali; pilihan lama harus
         | tetap utuh dan kembali saat saklarnya dinyalakan lagi.
         */
        $hasProductPayload = array_key_exists('product_ids', $data);
        $productIds = $products->normalizeIds($data['product_ids'] ?? null);
        $showProducts = (bool) ($data['show_products'] ?? $record->show_products);

        unset($data['group_ids'], $data['location_ids'], $data['slug'], $data['product_ids']);

        /*
         | Dua pemeriksaan server-side, dijalankan SEBELUM apa pun ditulis:
         |   1. Tidak boleh melepas Kota/Grup yang gerobaknya masih dipilih.
         |   2. Tidak boleh memilih gerobak di luar cakupan.
         */
        $scope->assertGroupsCanBeDetached($record, $groupIds);
        $scope->assertLocationsWithinGroups($locationIds, $groupIds);

        if ($hasProductPayload) {
            $products->assertSelection($showProducts, $productIds);
        }

        $data = [...$data, ...CreateLocationPage::posterMetadata($data)];

        $record->fill([...$data, 'updated_by' => auth()->id()]);

        DB::transaction(function () use ($record, $groupIds, $locationIds): void {
            $record->save();
            $record->groups()->sync($groupIds);
            $record->locations()->sync($locationIds);
        });

        if ($hasProductPayload) {
            $products->sync($record, $productIds);
        }

        // Poster lama dibuang SETELAH yang baru tersimpan.
        UploadedImage::deleteReplaced($previousPoster, $record->poster_path);

        if (is_string($requestedSlug) && $requestedSlug !== '') {
            $previousSlug = $record->slug;

            if ($slugService->apply($record, $requestedSlug, auth()->id())) {
                Notification::make()
                    ->warning()
                    ->title('URL halaman berubah')
                    ->body("Alamat lama /alamat/{$previousSlug} kini dialihkan permanen ke /alamat/{$record->slug}. "
                        .'Perbarui tautan di iklan yang sedang berjalan.')
                    ->persistent()
                    ->send();
            }
        }

        return $record;
    }

    protected function afterSave(): void
    {
        /** @var LocationPage $record */
        $record = $this->getRecord();

        /*
         | Halaman yang belum bisa dibuka pengunjung menyebutkan sebabnya:
         | nonaktif, atau berada di luar periode berlakunya.
         */
        $issue = $record->publicVisibilityIssue();

        if ($issue !== null) {
            Notification::make()
                ->warning()
                ->title('Halaman belum dapat dibuka pengunjung')
                ->body($issue)
                ->persistent()
                ->send();

            return;
        }

        /*
         | Halaman terbit tanpa satu pun gerobak yang benar-benar tampil akan
         | menyajikan empty state kepada pengunjung iklan. Kasusnya disebutkan,
         | bukan dibiarkan ditemukan sendiri.
         */
        if ($record->isPubliclyVisible() && ! LocationPage::query()->whereKey($record->getKey())->hasVisibleLocations()->exists()) {
            Notification::make()
                ->warning()
                ->title('Halaman ini aktif tanpa gerobak yang tampil')
                ->body('Pengunjung hanya akan melihat pesan "sedang disiapkan". Periksa status gerobak, Area, Kota/Grup, dan provinsinya sebelum dipakai sebagai tujuan iklan.')
                ->persistent()
                ->send();
        }
    }
}
