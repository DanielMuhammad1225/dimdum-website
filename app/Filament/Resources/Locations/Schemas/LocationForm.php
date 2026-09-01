<?php

namespace App\Filament\Resources\Locations\Schemas;

use App\Enums\PanelPermission;
use App\Filament\Support\UploadedImage;
use App\Models\Location;
use App\Models\LocationArea;
use App\Models\LocationGroup;
use App\Models\Province;
use App\Services\ImageMetadata;
use App\Services\LocationHierarchyService;
use App\Support\MapsUrl;
use App\Support\WhatsAppNumber;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class LocationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Data Gerobak')
                    ->columnSpanFull()
                    ->tabs([
                        Tabs\Tab::make('Identitas')->schema(self::identityFields()),
                        Tabs\Tab::make('Alamat')->schema(self::addressFields()),
                        Tabs\Tab::make('Peta & Kontak')->schema(self::mapFields()),
                        Tabs\Tab::make('Foto')->schema(self::mediaFields()),
                        Tabs\Tab::make('Status')->schema(self::publicationFields()),
                    ]),
            ]);
    }

    /**
     * @return array<mixed>
     */
    protected static function identityFields(): array
    {
        return [
            /*
             | Tiga select bertingkat: Provinsi -> Kota/Grup -> Area.
             | HANYA location_area_id yang disimpan. Dua select di atasnya
             | memakai dehydrated(false) supaya tidak ada FK redundan di
             | tabel locations -- provinsi dan grup diturunkan lewat Area.
             |
             | Cascading di UI hanya kenyamanan; keabsahan rantainya
             | diperiksa ulang DI SERVER pada rule location_area_id.
             */
            Select::make('province_id')
                ->label('Provinsi')
                ->helperText('Menyaring daftar Kota/Grup dan Area di bawahnya.')
                ->options(fn (): array => Province::query()
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->preload()
                ->native(false)
                ->dehydrated(false)
                ->live()
                ->afterStateUpdated(function (Set $set): void {
                    $set('location_group_id', null);
                    $set('location_area_id', null);
                }),

            Select::make('location_group_id')
                ->label('Kota/Grup')
                ->helperText('Pilih provinsi lebih dulu.')
                ->options(function (Get $get): array {
                    $provinceId = $get('province_id');

                    return LocationGroup::query()
                        ->when($provinceId, fn ($query) => $query->where('province_id', $provinceId))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all();
                })
                ->searchable()
                ->preload()
                ->native(false)
                ->dehydrated(false)
                ->live()
                ->afterStateUpdated(fn (Set $set) => $set('location_area_id', null)),

            Select::make('location_area_id')
                ->label('Area')
                ->helperText('Area menentukan halaman publik tempat gerobak ini tampil.')
                ->options(function (Get $get): array {
                    $groupId = $get('location_group_id');
                    $provinceId = $get('province_id');

                    return LocationArea::query()
                        ->when($groupId, fn ($query) => $query->where('location_group_id', $groupId))
                        ->when(
                            ! $groupId && $provinceId,
                            fn ($query) => $query->whereHas('group', fn ($g) => $g->where('province_id', $provinceId)),
                        )
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all();
                })
                ->searchable()
                ->preload()
                ->required()
                ->native(false)
                ->exists('location_areas', 'id')
                ->rule(function (Get $get, ?Location $record): callable {
                    return function (string $attribute, mixed $value, callable $fail) use ($get, $record): void {
                        $areaId = $value === null ? null : (int) $value;

                        if ($areaId === null) {
                            return;
                        }

                        $area = LocationArea::query()->whereKey($areaId)->first();

                        if ($area === null) {
                            $fail('Area yang dipilih tidak ditemukan.');

                            return;
                        }

                        $hierarchy = app(LocationHierarchyService::class);
                        $groupId = $get('location_group_id');
                        $provinceId = $get('province_id');

                        if ($groupId !== null && $groupId !== ''
                            && (int) $area->location_group_id !== (int) $groupId) {
                            $fail('Area yang dipilih tidak berada di Kota/Grup tersebut.');

                            return;
                        }

                        if ($provinceId !== null && $provinceId !== ''
                            && ! $hierarchy->groupBelongsToProvince((int) $area->location_group_id, (int) $provinceId)) {
                            $fail('Area yang dipilih tidak berada di provinsi tersebut.');

                            return;
                        }

                        /*
                         | Memindahkan gerobak keluar dari cakupan Halaman Slug
                         | Lokasi yang memilihnya akan mengurangi isi halaman
                         | itu diam-diam. Kasus itu diblokir, bukan dibiarkan.
                         */
                        $reason = $hierarchy->rejectionReasonForLocationMove(
                            $record ?? new Location,
                            $areaId,
                        );

                        if ($reason !== null) {
                            $fail($reason);
                        }
                    };
                }),

            TextInput::make('name')
                ->label('Nama gerobak')
                ->placeholder('Contoh: Gerobak Depan Pasar')
                ->required()
                ->maxLength(150),

        ];
    }

    /**
     * @return array<mixed>
     */
    protected static function addressFields(): array
    {
        return [
            Textarea::make('full_address')
                ->label('Alamat lengkap')
                ->helperText('Alamat yang tampil di kartu lokasi. Tulis apa adanya, jangan diisi contoh.')
                ->required()
                ->maxLength(500)
                ->rows(3),

            Section::make('Rincian Alamat')
                ->columns(2)
                ->schema([
                    TextInput::make('village')->label('Kelurahan/Desa')->maxLength(120),
                    TextInput::make('district')->label('Kecamatan')->maxLength(120),
                    TextInput::make('city_regency')->label('Kota/Kabupaten')->maxLength(120),

                    TextInput::make('postal_code')
                        ->label('Kode pos')
                        ->maxLength(10)
                        ->rule('regex:/^[0-9]{5}$/')
                        ->validationMessages(['regex' => 'Kode pos Indonesia terdiri dari 5 angka.']),

                    TextInput::make('landmark')
                        ->label('Patokan')
                        ->helperText('Penanda yang memudahkan pembeli menemukan gerobak.')
                        ->maxLength(180),
                ]),

            TextInput::make('operational_hours_text')
                ->label('Jam operasional')
                ->helperText('Ditulis bebas, contoh penulisan: "Setiap hari 09.00-21.00". Kosongkan bila belum pasti.')
                ->maxLength(180),
        ];
    }

    /**
     * @return array<mixed>
     */
    protected static function mapFields(): array
    {
        return [
            Section::make('Koordinat')
                ->description('Bila diisi, tautan Google Maps dirakit otomatis oleh server dan link manual di bawah tidak dipakai.')
                ->columns(2)
                ->schema([
                    TextInput::make('latitude')
                        ->label('Latitude')
                        ->numeric()
                        ->minValue(-90)
                        ->maxValue(90)
                        ->step('0.0000001')
                        ->requiredWith('longitude'),

                    TextInput::make('longitude')
                        ->label('Longitude')
                        ->numeric()
                        ->minValue(-180)
                        ->maxValue(180)
                        ->step('0.0000001')
                        ->requiredWith('latitude'),
                ]),

            TextInput::make('google_maps_url')
                ->label('Link Google Maps (manual)')
                ->helperText('Hanya dipakai bila koordinat kosong. Tempel link berbagi dari aplikasi Google Maps.')
                ->maxLength(2048)
                ->rule(function (): callable {
                    return function (string $attribute, mixed $value, callable $fail): void {
                        if (blank($value)) {
                            return;
                        }

                        if (! MapsUrl::isSafe($value)) {
                            $fail('Tautan harus HTTPS dan mengarah ke domain Google Maps resmi (contoh: google.com/maps atau maps.app.goo.gl).');
                        }
                    };
                }),

            TextInput::make('whatsapp_number')
                ->label('Nomor WhatsApp (opsional)')
                ->helperText('Boleh ditulis 0812..., +62812..., atau 62812... Kosongkan bila belum ada nomor resmi.')
                ->maxLength(32)
                ->rule(function (): callable {
                    return function (string $attribute, mixed $value, callable $fail): void {
                        if (blank($value)) {
                            return;
                        }

                        if (WhatsAppNumber::normalize($value) === null) {
                            $fail('Nomor WhatsApp tidak valid. Gunakan 8-15 digit, contoh: 081234567890.');
                        }
                    };
                }),
        ];
    }

    /**
     * @return array<mixed>
     */
    protected static function mediaFields(): array
    {
        return [
            Repeater::make('images')
                ->label('Foto gerobak')
                ->helperText('JPG, PNG, atau WebP. Maksimal 3 MB per foto. Geser untuk mengubah urutan tampil.')
                ->relationship()
                ->orderColumn('sort_order')
                ->reorderable()
                ->collapsible()
                ->itemLabel(fn (array $state): ?string => $state['alt_text'] ?? null)
                ->visible(fn (): bool => self::canManageMedia())
                /*
                 | Dimensi, MIME, dan ukuran byte dibaca dari file yang
                 | benar-benar tersimpan, bukan dari kiriman browser, lalu
                 | ikut disimpan supaya <img> selalu punya width/height nyata.
                 */
                ->mutateRelationshipDataBeforeCreateUsing(
                    fn (array $data): array => self::withImageMetadata($data),
                )
                ->mutateRelationshipDataBeforeSaveUsing(
                    fn (array $data): array => self::withImageMetadata($data),
                )
                ->schema([
                    /*
                     | Setiap foto mendapat folder ULID sendiri di bawah
                     | locations/. Folder tidak pernah dirakit dari nama atau
                     | slug yang bisa diubah admin, dan penghapusan satu foto
                     | tidak mungkin menyentuh berkas gerobak lain.
                     */
                    UploadedImage::locationGallery(
                        'image_path',
                        UploadedImage::locationDirectory((string) Str::ulid()),
                    )->required(),

                    TextInput::make('alt_text')
                        ->label('Teks alternatif (alt)')
                        ->helperText('Wajib. Deskripsi singkat isi foto untuk pembaca layar.')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('caption')
                        ->label('Keterangan (opsional)')
                        ->maxLength(500),

                    Toggle::make('is_cover')
                        ->label('Jadikan foto utama')
                        ->helperText('Foto utama tampil lebih dulu. Hanya satu foto per gerobak.'),
                ]),
        ];
    }

    /**
     * @return array<mixed>
     */
    protected static function publicationFields(): array
    {
        return [
            Toggle::make('is_active')
                ->label('Aktif')
                ->helperText('Status operasional gerobak. Gerobak nonaktif berhenti tampil pada Halaman Slug Lokasi yang memilihnya, tanpa dilepas dari halaman itu.')
                ->disabled(fn (): bool => ! self::canUpdate())
                ->dehydrated(fn (): bool => self::canUpdate()),
        ];
    }

    protected static function canUpdate(): bool
    {
        return auth()->user()?->can(PanelPermission::UpdateLocations->value) ?? false;
    }

    protected static function canManageMedia(): bool
    {
        return auth()->user()?->can(PanelPermission::ManageLocationMedia->value) ?? false;
    }

    /**
     * Lengkapi baris foto dengan metadata nyata file.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withImageMetadata(array $data): array
    {
        $metadata = ImageMetadata::inspect($data['image_path'] ?? null);

        return [
            ...$data,
            // Bila metadata gagal dibaca, nilainya 0 dan lapisan render
            // membuang foto tersebut -- lebih baik tidak tampil daripada
            // menghasilkan <img> tanpa dimensi yang memicu layout shift.
            'width' => $metadata['width'] ?? 0,
            'height' => $metadata['height'] ?? 0,
            'mime_type' => $metadata['mime_type'] ?? 'application/octet-stream',
            'size_bytes' => $metadata['size_bytes'] ?? 0,
            'created_by' => $data['created_by'] ?? auth()->id(),
        ];
    }

    /**
     * Wilayah yang bisa dipilih. Dipakai test dan filter tabel.
     *
     * @return array<int, string>
     */
    public static function areaOptions(): array
    {
        return LocationArea::query()->ordered()->pluck('name', 'id')->all();
    }
}
