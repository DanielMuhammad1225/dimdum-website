<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\PanelPermission;
use App\Enums\ProductCategory;
use App\Enums\ProductType;
use App\Filament\Support\UploadedImage;
use App\Services\ProductCatalogService;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * Form produk.
 *
 * Dua hal yang SENGAJA tidak ada di sini:
 *
 *   sort_order  Urutan dihitung server -- posisi terakhir saat dibuat -- dan
 *               sesudah itu hanya berubah lewat drag-and-drop pada tabel.
 *               Kolom isian akan membuat nomor urut bisa dikirim lewat
 *               request oleh siapa pun yang boleh menyimpan form ini.
 *   slug        Produk tidak punya URL sendiri pada fase ini, jadi tidak ada
 *               yang perlu dijaga keunikannya maupun dialihkan.
 */
class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identitas')
                    ->columns(2)
                    ->schema(self::identityFields()),

                Section::make('Harga')
                    ->columns(2)
                    ->description('Cukup isi salah satu. Bila keduanya kosong, kartu produk tampil tanpa label harga -- bukan "Rp0".')
                    ->schema(self::priceFields()),

                Section::make('Tampilan kartu')
                    ->columns(2)
                    ->description('Foto opsional. Bila tidak ada foto, emoji yang tampil; bila keduanya kosong, kartu memakai placeholder netral.')
                    ->schema(self::mediaFields()),

                Section::make('Status')
                    ->columns(2)
                    ->schema(self::statusFields()),
            ]);
    }

    /**
     * @return list<mixed>
     */
    protected static function identityFields(): array
    {
        return [
            TextInput::make('name')
                ->label('Nama produk')
                ->placeholder('Contoh: Dimsum Ayam Original')
                ->required()
                ->maxLength(120)
                ->columnSpanFull(),

            Select::make('category')
                ->label('Kategori')
                ->helperText('Menentukan kelompok produk ini pada halaman /produk.')
                ->options(ProductCategory::options())
                ->default(ProductCategory::Menu->value)
                ->required()
                ->native(false)
                // Nilai di luar enum ditolak server-side, bukan hanya di UI.
                ->in(ProductCategory::values()),

            Select::make('type')
                ->label('Tipe')
                ->helperText('Bentuk penjualannya. Berdiri sendiri dari kategori.')
                ->options(ProductType::options())
                ->default(ProductType::Satuan->value)
                ->required()
                ->native(false)
                ->in(ProductType::values()),

            Textarea::make('description')
                ->label('Deskripsi')
                ->helperText('Opsional. Tampil di halaman /produk; kartu homepage sengaja hanya memuat nama, gambar, dan harga.')
                ->rows(3)
                ->maxLength(1000)
                ->columnSpanFull(),
        ];
    }

    /**
     * @return list<mixed>
     */
    protected static function priceFields(): array
    {
        return [
            TextInput::make('price')
                ->label('Harga (angka)')
                ->helperText('Rupiah penuh, tanpa titik dan tanpa sen. Contoh: 5000.')
                ->numeric()
                ->minValue(0)
                // Batas kolom unsignedInteger; nilai di atasnya ditolak server.
                ->maxValue(4294967295)
                ->prefix('Rp'),

            TextInput::make('price_text')
                ->label('Harga (teks)')
                ->helperText('Menang atas harga angka bila diisi, karena memuat satuan yang tidak bisa disimpulkan dari angka.')
                ->placeholder('Rp5.000/pcs')
                ->maxLength(120),
        ];
    }

    /**
     * @return list<mixed>
     */
    protected static function mediaFields(): array
    {
        return [
            UploadedImage::product('image_path')
                ->columnSpanFull()
                ->disabled(fn (): bool => ! self::canManageMedia())
                /*
                 | Tanpa dehydrated(false), menyimpan form oleh user yang tidak
                 | berhak mengelola media akan menuliskan null dan MENGHAPUS
                 | foto yang sudah ada. Field yang dinonaktifkan harus benar-
                 | benar tidak ikut terkirim, bukan sekadar tidak bisa diklik.
                 */
                ->dehydrated(fn (): bool => self::canManageMedia()),

            TextInput::make('image_alt')
                ->label('Teks alternatif foto')
                ->helperText('Opsional. Bila kosong, nama produk yang dipakai -- alt tidak pernah dibiarkan kosong.')
                ->maxLength(160)
                ->disabled(fn (): bool => ! self::canManageMedia())
                ->dehydrated(fn (): bool => self::canManageMedia()),

            TextInput::make('emoji')
                ->label('Emoji cadangan')
                ->helperText('Opsional. Hanya dipakai bila produk ini belum punya foto.')
                ->placeholder('🥟')
                ->maxLength(8),

            Placeholder::make('card_preview')
                ->label('Yang akan tampil di kartu')
                ->live()
                ->content(fn (Get $get): HtmlString => new HtmlString(self::cardPreview($get)))
                ->columnSpanFull(),
        ];
    }

    /**
     * @return list<mixed>
     */
    protected static function statusFields(): array
    {
        return [
            Toggle::make('is_active')
                ->label('Aktif')
                ->helperText('Produk nonaktif tidak tampil di homepage maupun di halaman /produk.')
                ->default(true),

            Toggle::make('show_on_homepage')
                ->label('Tampilkan di homepage')
                ->helperText('Homepage menampilkan maksimal '.ProductCatalogService::HOMEPAGE_LIMIT
                    .' produk. Sisanya tetap terlihat di halaman /produk.')
                ->default(false),

            // Terpisah dari saklar homepage: isi /bio/produk boleh berbeda.
            Toggle::make('show_on_bio')
                ->label('Tampilkan di Bio')
                ->helperText('Produk yang menyala tampil di halaman /bio/produk. Tidak memengaruhi homepage maupun /produk.')
                ->default(false),
        ];
    }

    /**
     * Ringkasan apa yang benar-benar akan dirender, supaya admin tidak perlu
     * membuka homepage untuk memastikannya.
     */
    protected static function cardPreview(Get $get): string
    {
        if (filled($get('image_path'))) {
            return 'Kartu memakai <strong>foto</strong> yang diunggah.';
        }

        $emoji = trim((string) $get('emoji'));

        if ($emoji !== '') {
            return 'Belum ada foto, kartu memakai emoji <strong>'.e($emoji).'</strong>.';
        }

        return 'Belum ada foto dan emoji. Kartu menampilkan placeholder netral.';
    }

    protected static function canManageMedia(): bool
    {
        return auth()->user()?->can(PanelPermission::ManageProductMedia->value) ?? false;
    }
}
