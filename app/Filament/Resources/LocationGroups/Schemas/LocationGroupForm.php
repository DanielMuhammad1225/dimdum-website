<?php

namespace App\Filament\Resources\LocationGroups\Schemas;

use App\Enums\LocationGroupType;
use App\Enums\PanelPermission;
use App\Models\LocationGroup;
use App\Models\Province;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class LocationGroupForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identitas Kota/Grup')
                    ->description('Kota/Grup mengelompokkan Area di halaman provinsi. Ia tidak punya halaman sendiri.')
                    ->schema([
                        Select::make('province_id')
                            ->label('Provinsi')
                            ->helperText('Satu Kota/Grup wajib berada di tepat satu provinsi dan tidak boleh lintas provinsi.')
                            ->options(fn (): array => Province::query()
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->required()
                            ->native(false)
                            // Validasi server-side: id provinsi harus benar ada.
                            ->exists('provinces', 'id')
                            /*
                             | Memindahkan grup ke provinsi lain akan memindahkan
                             | SELURUH area di bawahnya sekaligus -- termasuk yang
                             | sudah terbit -- sehingga URL iklannya berubah.
                             | Tanpa aturan ini, aturan "Area terbit tidak boleh
                             | lintas provinsi" bisa diputar lewat grupnya.
                             */
                            ->rule(function (?LocationGroup $record): callable {
                                return function (string $attribute, mixed $value, callable $fail) use ($record): void {
                                    if ($record === null || (int) $value === (int) $record->province_id) {
                                        return;
                                    }

                                    $published = $record->areas()
                                        ->whereNotNull('published_at')
                                        ->exists();

                                    if ($published) {
                                        $fail('Kota/Grup ini memiliki Area yang sudah pernah terbit, jadi tidak boleh '
                                            .'dipindahkan ke provinsi lain. URL area memuat nama provinsi, sehingga '
                                            .'perpindahan akan mematikan tautan iklan yang sedang berjalan.');
                                    }
                                };
                            }),

                        TextInput::make('name')
                            ->label('Nama Kota/Grup')
                            ->placeholder('Contoh: Kabupaten Cianjur atau Bandung Raya')
                            ->required()
                            ->maxLength(120)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                if (blank($get('slug')) && filled($state)) {
                                    $set('slug', Str::slug($state));
                                }
                            }),

                        TextInput::make('slug')
                            ->label('Slug internal')
                            ->helperText('Tidak muncul di URL publik. Dipakai untuk membedakan grup di dalam satu provinsi.')
                            ->maxLength(160)
                            ->disabled(fn (): bool => ! self::canChangeSlug())
                            ->dehydrated(fn (): bool => self::canChangeSlug())
                            ->rule('regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                            ->validationMessages([
                                'regex' => 'Slug hanya boleh huruf kecil, angka, dan tanda hubung.',
                            ]),

                        Select::make('type')
                            ->label('Tipe')
                            ->helperText(self::typeHelper())
                            ->options(LocationGroupType::options())
                            ->default(LocationGroupType::Administrative->value)
                            ->required()
                            ->native(false)
                            // Nilai di luar enum ditolak server-side.
                            ->in(LocationGroupType::values()),

                        Textarea::make('description')
                            ->label('Deskripsi')
                            ->helperText('Opsional. Tampil sebagai kalimat pendek di bawah heading grup.')
                            ->maxLength(500)
                            ->rows(2),
                    ]),

                Section::make('Status')
                    ->description('Kota/Grup tidak punya jadwal terbit sendiri. Menonaktifkannya menyembunyikan SELURUH area dan gerobak di bawahnya.')
                    ->columns(2)
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Aktif')
                            ->helperText('Nonaktif menyembunyikan seluruh area di bawah grup ini.')
                            ->disabled(fn (): bool => ! self::canPublish())
                            ->dehydrated(fn (): bool => self::canPublish()),

                        TextInput::make('sort_order')
                            ->label('Urutan')
                            ->helperText('Angka kecil tampil lebih dulu di halaman provinsi.')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->maxValue(65535)
                            ->required(),
                    ]),
            ]);
    }

    protected static function typeHelper(): string
    {
        return collect(LocationGroupType::cases())
            ->map(fn (LocationGroupType $type): string => $type->label().': '.$type->description())
            ->implode(' ');
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
