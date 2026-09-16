<?php

namespace App\Filament\Resources\LocationAreas\Schemas;

use App\Enums\PanelPermission;
use App\Models\LocationArea;
use App\Models\LocationGroup;
use App\Models\Province;
use App\Services\LocationHierarchyService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/**
 * Form Area -- MASTER DATA saja.
 *
 * Tanpa slug, headline, deskripsi, SEO, maupun waktu terbit: Area tidak punya
 * halaman sendiri. Ia hanya mengelompokkan gerobak di bawah satu Kota/Grup,
 * dan menjadi konteks tampilan saat admin memilih gerobak untuk sebuah
 * Halaman Slug Lokasi.
 */
class LocationAreaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identitas Area')
                    ->description('Area adalah satuan operasional/pemasaran, bukan selalu kecamatan.')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nama area')
                            ->placeholder('Contoh: Cianjur Kota')
                            ->required()
                            ->maxLength(120),

                        Toggle::make('is_active')
                            ->label('Aktif')
                            ->helperText('Status operasional. Menonaktifkan area membuat gerobaknya berhenti tampil di halaman slug mana pun.')
                            ->disabled(fn (): bool => ! self::canManage())
                            ->dehydrated(fn (): bool => self::canManage()),
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
                             |
                             | Aturan kedua kini soal HALAMAN, bukan URL:
                             | memindahkan Area keluar dari cakupan sebuah
                             | Halaman Slug Lokasi akan mengurangi isi halaman
                             | itu diam-diam.
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
            ]);
    }

    protected static function canManage(): bool
    {
        return auth()->user()?->can(PanelPermission::ManageLocationAreas->value) ?? false;
    }
}
