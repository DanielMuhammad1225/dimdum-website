<?php

namespace App\Filament\Resources\SocialLinks\Pages;

use App\Filament\Resources\SocialLinks\SocialLinkResource;
use App\Support\SafeUrl;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditSocialLink extends EditRecord
{
    protected static string $resource = SocialLinkResource::class;

    public function getTitle(): string
    {
        return 'Ubah Social Media';
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->label('Hapus'),
        ];
    }

    /**
     * sort_order tidak ikut ditulis: urutan hanya berubah lewat
     * seret-dan-lepas di tabel.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->fill([
            ...$data,
            'url' => SafeUrl::sanitizeWeb($data['url'] ?? null) ?? abort(422),
            'updated_by' => auth()->id(),
        ]);

        $record->save();

        return $record;
    }
}
