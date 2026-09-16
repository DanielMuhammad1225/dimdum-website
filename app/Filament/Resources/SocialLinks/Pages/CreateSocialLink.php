<?php

namespace App\Filament\Resources\SocialLinks\Pages;

use App\Filament\Resources\SocialLinks\SocialLinkResource;
use App\Services\LocationOrderingService;
use App\Support\SafeUrl;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSocialLink extends CreateRecord
{
    protected static string $resource = SocialLinkResource::class;

    public function getTitle(): string
    {
        return 'Tambah Social Media';
    }

    /**
     * Urutan tidak pernah datang dari form: tautan baru selalu ditempatkan
     * TERAKHIR, dihitung server di dalam satu transaction. Tautan tidak punya
     * induk, jadi scope-nya global.
     *
     * LocationOrderingService dipakai apa adanya -- nextPosition() dan
     * assignLastPosition() generik terhadap model.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $record = new (static::getModel());

        $record->fill([
            ...$data,
            // Lapis terakhir di balik validasi form: URL yang tidak aman tidak
            // pernah tersimpan, apa pun jalur masuknya.
            'url' => SafeUrl::sanitizeWeb($data['url'] ?? null) ?? abort(422),
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        app(LocationOrderingService::class)->assignLastPosition($record, []);

        return $record;
    }
}
