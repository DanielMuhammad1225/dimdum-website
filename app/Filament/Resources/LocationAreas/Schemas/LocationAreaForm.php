<?php

namespace App\Filament\Resources\LocationAreas\Schemas;

use App\Enums\PanelPermission;
use App\Models\LocationArea;
use App\Models\LocationGroup;
use App\Models\Province;
use App\Services\LocationAreaSlugService;
use App\Services\LocationHierarchyService;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class LocationAreaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identitas Area')
                    ->description('Area adalah satuan operasional/pemasaran, bukan selalu kecamatan. Halaman Area menjadi tujuan iklan.')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nama area')
                            ->placeholder('Contoh: Cianjur')
                            ->required()
                            ->maxLength(120)
                            ->live(onBlur: true)
                            // Slug hanya diisi otomatis saat masih kosong,
                            // supaya slug yang sudah terbit tidak berubah
                            // diam-diam ketika nama diperbaiki.
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                if (blank($get('slug')) && filled($state)) {
                                    $set('slug', Str::slug($state));
                                }
                            }),

                        TextInput::make('slug')
                            ->label('Slug URL')
                            ->helperText(fn (?LocationArea $record): string => self::slugHelper($record))
                            ->required()
                            ->maxLength(160)
                            ->disabled(fn (): bool => ! self::canChangeSlug())
                            ->dehydrated(fn (): bool => self::canChangeSlug())
                            ->rule('regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                            ->validationMessages([
                                'regex' => 'Slug hanya boleh huruf kecil, angka, dan tanda hubung.',
                            ])
                            ->rule(function (?LocationArea $record): callable {
                                return function (string $attribute, mixed $value, callable $fail) use ($record): void {
                                    $slug = Str::slug((string) $value);

                                    if ($slug === '') {
                                        $fail('Slug tidak valid.');

                                        return;
                                    }

                                    if (! app(LocationAreaSlugService::class)->isAvailable($slug, $record?->getKey())) {
                                        $fail('Slug ini sudah dipakai area lain atau merupakan slug lama yang sedang dialihkan.');
                                    }
                                };
                            }),

                        TextInput::make('headline')
                            ->label('Headline halaman')
                            ->helperText('Kosongkan untuk memakai "Lokasi Gerobak DIMDUM di {nama area}".')
                            ->maxLength(160),

                        Textarea::make('description')
                            ->label('Deskripsi area')
                            ->helperText('Kalimat pembuka halaman. Kosongkan untuk memakai kalimat bawaan.')
                            ->maxLength(500)
                            ->rows(3),
                    ]),

                Section::make('Induk Hierarki')
                    ->description('Area wajib berada di bawah satu Kota/Grup. Provinsi hanya membantu menyaring pilihan dan TIDAK disimpan di Area -- ia diturunkan lewat Kota/Grup.')
                    ->columns(2)
                    ->schema([
                        /*
                         | Field bantu. dehydrated(false) memastikan nilainya
                         | TIDAK pernah ikut tersimpan, sehingga tidak ada
                         | province_id redundan di tabel location_areas.
                         */
                        Select::make('province_id')
                            ->label('Provinsi')
                            ->helperText('Menyaring daftar Kota/Grup di sebelahnya.')
                            ->options(fn (): array => Province::query()
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->dehydrated(false)
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('location_group_id', null)),

                        Select::make('location_group_id')
                            ->label('Kota/Grup')
                            ->helperText('Pilih provinsi lebih dulu untuk melihat daftarnya.')
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
                            ->required()
                            ->native(false)
                            ->exists('location_groups', 'id')
                            /*
                             | Cascading select hanyalah kenyamanan tampilan.
                             | Dua aturan ini ditegakkan DI SERVER, karena form
                             | apa pun bisa dikirim ulang dengan kombinasi yang
                             | tidak sah tanpa menyentuh antarmuka.
                             */
                            ->rule(function (?LocationArea $record, Get $get): callable {
                                return function (string $attribute, mixed $value, callable $fail) use ($record, $get): void {
                                    $hierarchy = app(LocationHierarchyService::class);
                                    $groupId = $value === null ? null : (int) $value;
                                    $provinceId = $get('province_id');

                                    if ($provinceId !== null && $provinceId !== ''
                                        && ! $hierarchy->groupBelongsToProvince($groupId, (int) $provinceId)) {
                                        $fail('Kota/Grup yang dipilih tidak berada di provinsi tersebut.');

                                        return;
                                    }

                                    $reason = $hierarchy->rejectionReasonForAreaMove(
                                        $record ?? new LocationArea,
                                        $groupId,
                                    );

                                    if ($reason !== null) {
                                        $fail($reason);
                                    }
                                };
                            }),
                    ]),

                Section::make('SEO')
                    ->description('Kosongkan untuk memakai headline dan deskripsi di atas.')
                    ->schema([
                        TextInput::make('seo_title')
                            ->label('SEO title')
                            ->maxLength(160),

                        Textarea::make('seo_description')
                            ->label('SEO description')
                            ->maxLength(500)
                            ->rows(2),
                    ]),

                Section::make('Publikasi')
                    ->description('Area tampil bila AKTIF, waktu terbitnya sudah lewat, DAN Kota/Grup serta provinsinya juga tampil.')
                    ->columns(2)
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Aktif')
                            ->helperText('Nonaktif berarti halaman area menjadi 404.')
                            ->disabled(fn (): bool => ! self::canPublish())
                            ->dehydrated(fn (): bool => self::canPublish()),

                        DateTimePicker::make('published_at')
                            ->label('Waktu terbit')
                            ->helperText('Kosong berarti masih draft. Waktu di masa depan membuat halaman belum tampil.')
                            ->seconds(false)
                            ->disabled(fn (): bool => ! self::canPublish())
                            ->dehydrated(fn (): bool => self::canPublish()),

                        TextInput::make('sort_order')
                            ->label('Urutan')
                            ->helperText('Angka kecil tampil lebih dulu.')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->maxValue(65535)
                            ->required(),
                    ]),
            ]);
    }

    protected static function slugHelper(?LocationArea $record): string
    {
        if (! self::canChangeSlug()) {
            return 'Anda tidak memiliki izin mengubah slug. Hubungi Admin bila URL perlu diganti.';
        }

        if ($record?->hasEverBeenPublished()) {
            return 'PERINGATAN: halaman ini sudah pernah terbit. Mengganti slug akan mengubah URL yang mungkin sedang dipakai iklan. '
                .'Slug lama otomatis dialihkan 301 ke slug baru, tetapi laporan iklan bisa terpecah.';
        }

        return 'Bagian akhir URL halaman wilayah. Sebaiknya ditetapkan sebelum halaman diterbitkan.';
    }

    protected static function canChangeSlug(): bool
    {
        return auth()->user()?->can(PanelPermission::ChangeLocationSlugs->value) ?? false;
    }

    protected static function canPublish(): bool
    {
        return auth()->user()?->can(PanelPermission::PublishLocations->value) ?? false;
    }
}
