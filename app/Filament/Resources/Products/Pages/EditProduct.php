<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\UploadedImage;
use App\Models\Product;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    public function getTitle(): string
    {
        return 'Ubah Produk';
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->label('Hapus'),
            RestoreAction::make()->label('Pulihkan'),

            /*
             | Force delete membersihkan foto SETELAH baris benar-benar
             | terhapus. Soft delete sengaja tidak menyentuh berkas sama
             | sekali, supaya pemulihan mengembalikan produk beserta fotonya.
             */
            ForceDeleteAction::make()
                ->label('Hapus permanen')
                ->after(function (Product $record): void {
                    UploadedImage::deleteManagedFile($record->image_path);
                }),
        ];
    }

    /**
     * sort_order TIDAK ikut ditulis: urutan hanya berubah lewat drag-and-drop.
     *
     * Foto lama dibuang SETELAH yang baru tersimpan, dan hanya bila memang
     * berbeda -- menyimpan form tanpa mengunggah ulang tidak pernah menghapus
     * foto yang sudah ada (lihat UploadedImage::deleteReplaced()).
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Product $record */
        $previousImage = $record->image_path;

        $record->fill([...$data, 'updated_by' => auth()->id()]);
        $record->save();

        UploadedImage::deleteReplaced($previousImage, $record->image_path);

        return $record;
    }
}
