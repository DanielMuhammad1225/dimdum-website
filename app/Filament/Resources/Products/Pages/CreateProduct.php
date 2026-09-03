<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Services\LocationOrderingService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    public function getTitle(): string
    {
        return 'Tambah Produk';
    }

    /**
     * Urutan tidak pernah datang dari form.
     *
     * Produk baru selalu ditempatkan di posisi TERAKHIR, dihitung server di
     * dalam satu transaction. Produk tidak punya induk, jadi scope-nya global
     * -- sama seperti Halaman Slug Lokasi.
     *
     * LocationOrderingService dipakai apa adanya: nextPosition(),
     * assignLastPosition(), dan normalize() sepenuhnya generik terhadap model
     * dan tidak memuat satu pun asumsi tentang hierarki lokasi. Hanya
     * moveToScope() yang khusus lokasi, dan itu tidak dipakai di sini karena
     * produk tidak pernah berpindah induk.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $record = new (static::getModel());

        $record->fill([
            ...$data,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        app(LocationOrderingService::class)->assignLastPosition($record, []);

        return $record;
    }
}
