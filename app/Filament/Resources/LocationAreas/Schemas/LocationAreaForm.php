<?php

namespace App\Filament\Resources\LocationAreas\Schemas;

use App\Enums\PanelPermission;
use App\Models\LocationArea;
use App\Services\LocationAreaSlugService;
use Filament\Forms\Components\DateTimePicker;
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
                Section::make('Identitas Wilayah')
                    ->description('Wilayah landing adalah area pemasaran, bukan wilayah administratif. Satu wilayah boleh mencakup beberapa kecamatan.')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nama wilayah')
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
                                        $fail('Slug ini sudah dipakai wilayah lain atau merupakan slug lama yang sedang dialihkan.');
                                    }
                                };
                            }),

                        TextInput::make('headline')
                            ->label('Headline halaman')
                            ->helperText('Kosongkan untuk memakai "Lokasi Gerobak DIMDUM di {nama wilayah}".')
                            ->maxLength(160),

                        Textarea::make('description')
                            ->label('Deskripsi wilayah')
                            ->helperText('Kalimat pembuka halaman. Kosongkan untuk memakai kalimat bawaan.')
                            ->maxLength(500)
                            ->rows(3),
                    ]),

                Section::make('Data Administratif')
                    ->description('Opsional. Hanya untuk konteks tampilan, bukan penentu wilayah landing.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('city_regency')
                            ->label('Kota/Kabupaten')
                            ->maxLength(120),

                        TextInput::make('province')
                            ->label('Provinsi')
                            ->maxLength(120),
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
                    ->description('Halaman baru tampil di publik bila AKTIF dan waktu terbitnya sudah lewat.')
                    ->columns(2)
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Aktif')
                            ->helperText('Nonaktif berarti halaman wilayah menjadi 404.')
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
