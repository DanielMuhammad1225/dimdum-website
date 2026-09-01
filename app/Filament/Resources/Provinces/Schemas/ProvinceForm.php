<?php

namespace App\Filament\Resources\Provinces\Schemas;

use App\Enums\PanelPermission;
use App\Models\Province;
use App\Services\LocationProvinceSlugService;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ProvinceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identitas Provinsi')
                    ->description('Provinsi menjadi segmen pertama URL: /lokasi/{provinsi}/{area}.')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nama provinsi')
                            ->placeholder('Contoh: Jawa Barat')
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
                            ->helperText(fn (?Province $record): string => self::slugHelper($record))
                            ->required()
                            ->maxLength(160)
                            ->disabled(fn (): bool => ! self::canChangeSlug())
                            ->dehydrated(fn (): bool => self::canChangeSlug())
                            ->rule('regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                            ->validationMessages([
                                'regex' => 'Slug hanya boleh huruf kecil, angka, dan tanda hubung.',
                            ])
                            ->rule(function (?Province $record): callable {
                                return function (string $attribute, mixed $value, callable $fail) use ($record): void {
                                    $slug = Str::slug((string) $value);

                                    if ($slug === '') {
                                        $fail('Slug tidak valid.');

                                        return;
                                    }

                                    if (! app(LocationProvinceSlugService::class)->isAvailable($slug, $record?->getKey())) {
                                        $fail('Slug ini sudah dipakai provinsi lain atau merupakan slug lama yang sedang dialihkan.');
                                    }
                                };
                            }),

                        Textarea::make('description')
                            ->label('Deskripsi provinsi')
                            ->helperText('Kalimat pembuka halaman provinsi. Kosongkan untuk memakai kalimat bawaan.')
                            ->maxLength(500)
                            ->rows(3),
                    ]),

                Section::make('SEO')
                    ->description('Kosongkan untuk memakai nama dan deskripsi di atas.')
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
                    ->description('Halaman provinsi tampil bila AKTIF dan waktu terbitnya sudah lewat. Provinsi yang belum terbit menyembunyikan SELURUH area dan gerobak di bawahnya.')
                    ->columns(2)
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Aktif')
                            ->helperText('Nonaktif menyembunyikan provinsi ini beserta seluruh turunannya.')
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

    protected static function slugHelper(?Province $record): string
    {
        if (! self::canChangeSlug()) {
            return 'Anda tidak memiliki izin mengubah slug. Hubungi Admin bila URL perlu diganti.';
        }

        if ($record?->hasEverBeenPublished()) {
            return 'PERINGATAN: provinsi ini sudah pernah terbit. Mengganti slug akan mengubah URL SELURUH area di bawahnya. '
                .'Slug lama otomatis dialihkan 301, tetapi laporan iklan bisa terpecah.';
        }

        return 'Segmen pertama URL halaman lokasi. Sebaiknya ditetapkan sebelum halaman diterbitkan.';
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
