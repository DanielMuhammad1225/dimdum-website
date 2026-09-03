<?php

namespace App\Filament\Resources\LocationPages\Schemas;

use App\Enums\PanelPermission;
use App\Filament\Support\UploadedImage;
use App\Models\LocationPage;
use App\Services\LocationPageProductService;
use App\Services\LocationPageScopeService;
use App\Services\LocationPageSlugService;
use App\Support\SafeUrl;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Form Halaman Slug Lokasi.
 *
 * ALUR YANG DIKUNCI:
 *   1. Admin mengisi identitas halaman.
 *   2. Admin memilih satu atau beberapa Kota/Grup -> ini CAKUPAN halaman.
 *   3. Sistem membuka kandidat gerobak dari SELURUH Area di bawah grup itu.
 *   4. Admin memilih gerobak mana yang benar-benar masuk halaman -> ini ISI.
 *
 * Area BUKAN input. Ia hanya muncul sebagai judul kelompok pada daftar
 * kandidat, supaya gerobak bernama mirip tetap bisa dibedakan.
 *
 * Seluruh aturan cakupan diperiksa ULANG di server lewat
 * LocationPageScopeService: menyembunyikan pilihan di UI tidak pernah dianggap
 * sebagai proteksi.
 */
class LocationPageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Halaman')
                    ->columnSpanFull()
                    ->tabs([
                        Tabs\Tab::make('Isi Halaman')->schema(self::contentFields()),
                        Tabs\Tab::make('Lokasi')->schema(self::scopeFields()),
                        Tabs\Tab::make('Produk')->schema(self::productFields()),
                        Tabs\Tab::make('Poster')->schema(self::posterFields()),
                        Tabs\Tab::make('SEO')->schema(self::seoFields()),
                        Tabs\Tab::make('Publikasi')->schema(self::publicationFields()),
                    ]),
            ]);
    }

    /**
     * @return array<mixed>
     */
    protected static function contentFields(): array
    {
        return [
            TextInput::make('title')
                ->label('Judul halaman')
                ->placeholder('Contoh: Alamat Gerobak DIMDUM Cianjur')
                ->required()
                ->maxLength(160)
                ->live(onBlur: true)
                // Slug hanya diisi otomatis saat masih kosong, supaya slug yang
                // sudah terbit tidak berubah diam-diam ketika judul diperbaiki.
                ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                    if (blank($get('slug')) && filled($state)) {
                        $set('slug', Str::slug($state));
                    }
                }),

            TextInput::make('slug')
                ->label('Slug URL')
                ->prefix(url('/alamat').'/')
                ->helperText(fn (?LocationPage $record): string => self::slugHelper($record))
                ->required()
                ->maxLength(180)
                ->disabled(fn (): bool => ! self::canChangeSlug())
                ->dehydrated(fn (): bool => self::canChangeSlug())
                ->rule('regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                ->validationMessages([
                    'regex' => 'Slug hanya boleh huruf kecil, angka, dan tanda hubung.',
                ])
                ->rule(function (?LocationPage $record): callable {
                    return function (string $attribute, mixed $value, callable $fail) use ($record): void {
                        $slug = Str::slug((string) $value);

                        if ($slug === '') {
                            $fail('Slug tidak valid.');

                            return;
                        }

                        if (! app(LocationPageSlugService::class)->isAvailable($slug, $record?->getKey())) {
                            $fail('Slug ini sudah dipakai halaman lain atau merupakan slug lama yang sedang dialihkan.');
                        }
                    };
                }),

            Textarea::make('short_description')
                ->label('Deskripsi singkat')
                ->helperText('Satu-dua kalimat. Tampil di bawah judul dan pada kartu homepage.')
                ->maxLength(500)
                ->rows(3),

            Textarea::make('detailed_description')
                ->label('Deskripsi detail')
                ->helperText('Keterangan panjang. Ditampilkan sebagai teks biasa; markup HTML tidak dirender.')
                ->rows(8),

            Section::make('Periode')
                ->description('Opsional. Halaman tanpa tanggal berlaku selamanya.')
                ->columns(2)
                ->schema([
                    TextInput::make('period_text')
                        ->label('Periode (teks)')
                        ->helperText('Kalimat yang dibaca pengunjung, mis. "Setiap hari, 09.00-21.00".')
                        ->maxLength(160)
                        ->columnSpanFull(),

                    DateTimePicker::make('starts_at')
                        ->label('Mulai berlaku')
                        ->seconds(false),

                    DateTimePicker::make('ends_at')
                        ->label('Selesai berlaku')
                        ->seconds(false)
                        // Tanggal selesai sebelum mulai membuat halaman tidak
                        // pernah tampil sama sekali -- ditolak sejak di form.
                        ->after('starts_at')
                        ->validationMessages([
                            'after' => 'Tanggal selesai harus setelah tanggal mulai.',
                        ]),
                ]),

            Section::make('Tombol CTA')
                ->description('Tombol hanya tampil bila teks DAN URL-nya sama-sama terisi dan aman.')
                ->columns(2)
                ->schema([
                    TextInput::make('button_text')
                        ->label('Teks tombol')
                        ->maxLength(80),

                    TextInput::make('button_url')
                        ->label('URL tombol')
                        ->helperText('Wajib HTTPS. Tautan javascript:, data:, dan sejenisnya ditolak.')
                        ->maxLength(2048)
                        ->rule(function (): callable {
                            return function (string $attribute, mixed $value, callable $fail): void {
                                if (blank($value)) {
                                    return;
                                }

                                if (SafeUrl::sanitizeExternal($value) === null) {
                                    $fail('URL tidak aman atau tidak valid. Gunakan tautan HTTPS yang lengkap.');
                                }
                            };
                        }),
                ]),
        ];
    }

    /**
     * @return array<mixed>
     */
    protected static function scopeFields(): array
    {
        return [
            Section::make('Cakupan Kota/Grup')
                ->description('Menentukan gerobak mana yang BOLEH dipilih. Satu halaman dapat mencakup beberapa Kota/Grup sekaligus.')
                ->schema([
                    Select::make('group_ids')
                        ->label('Kota/Grup')
                        // Label menyertakan provinsi: nama grup mudah berulang
                        // antar provinsi dan akan ambigu tanpa itu.
                        ->options(fn (): array => app(LocationPageScopeService::class)->groupOptions())
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->required()
                        ->live()
                        ->helperText('Pilih dulu di sini, lalu daftar gerobaknya terbuka di bawah.')
                        ->rule(function (?LocationPage $record): callable {
                            return function (string $attribute, mixed $value, callable $fail) use ($record): void {
                                if ($record === null) {
                                    return;
                                }

                                /*
                                 | Melepas Kota/Grup yang gerobaknya masih
                                 | dipilih akan mengosongkan isi halaman tanpa
                                 | disadari. Ditolak dengan menyebut namanya.
                                 */
                                $blocking = app(LocationPageScopeService::class)
                                    ->groupsStillInUse($record, array_map('intval', (array) $value));

                                if ($blocking->isNotEmpty()) {
                                    $fail('Kota/Grup berikut masih memiliki gerobak yang dipilih pada halaman ini: '
                                        .$blocking->implode(', ').'. Hapus dulu pilihan gerobaknya.');
                                }
                            };
                        }),
                ]),

            Section::make('Gerobak yang Ditampilkan')
                ->description('Kandidat berasal dari SELURUH Area di bawah Kota/Grup terpilih. Gerobak yang dibuat kemudian tidak ikut otomatis -- pilih sendiri di sini.')
                ->schema([
                    Select::make('location_ids')
                        ->label('Gerobak')
                        /*
                         | Opsi dikelompokkan "Provinsi — Kota/Grup · Area"
                         | sehingga Area tampil sebagai konteks, bukan input.
                         | Pilihan lama yang masih sah tetap ikut ditampilkan
                         | agar tidak hilang saat cakupan diperluas.
                         */
                        ->options(function (Get $get, ?LocationPage $record): array {
                            $groupIds = array_map('intval', (array) $get('group_ids'));
                            $existing = $record?->locations()->pluck('locations.id')->all() ?? [];

                            return app(LocationPageScopeService::class)
                                ->candidateOptions($groupIds, array_map('intval', $existing));
                        })
                        ->multiple()
                        ->searchable()
                        ->helperText('Kosongkan Kota/Grup di atas dan daftar ini ikut kosong -- seluruh gerobak tidak pernah ditampilkan sekaligus.')
                        ->rule(function (Get $get): callable {
                            return function (string $attribute, mixed $value, callable $fail) use ($get): void {
                                $locationIds = array_map('intval', (array) $value);
                                $groupIds = array_map('intval', (array) $get('group_ids'));

                                if ($locationIds === []) {
                                    return;
                                }

                                if ($groupIds === []) {
                                    $fail('Pilih Kota/Grup lebih dulu sebelum memilih gerobak.');

                                    return;
                                }

                                /*
                                 | Pemeriksaan server-side yang sesungguhnya.
                                 | Payload yang disisipkan langsung lewat
                                 | request atau Livewire menempuh jalur ini
                                 | juga, jadi id di luar cakupan tetap ditolak.
                                 */
                                $outside = app(LocationPageScopeService::class)
                                    ->locationsOutsideGroups($locationIds, $groupIds);

                                if ($outside->isNotEmpty()) {
                                    $fail('Gerobak berikut berada di luar Kota/Grup yang dipilih dan ditolak: '
                                        .$outside->implode(', ').'.');
                                }
                            };
                        }),
                ]),
        ];
    }

    /**
     * Pemilihan produk untuk section produk halaman ini.
     *
     * CRUD Produk tetap satu-satunya sumber datanya: yang disimpan di sini
     * hanyalah RELASI. Nama, kategori, harga, foto, dan deskripsi selalu
     * dibaca ulang dari tabel products saat halaman dirender.
     *
     * Perilaku saklar yang perlu dijaga: mematikan "Tampilkan Produk" hanya
     * MENYEMBUNYIKAN pilihannya. Filament membuang state komponen tersembunyi
     * dari data terdehidrasi, sehingga halaman Create/Edit tidak menerima
     * product_ids sama sekali dan relasi lamanya tidak tersentuh. Menyalakan
     * saklar lagi memunculkan pilihan yang sama persis.
     *
     * @return array<mixed>
     */
    protected static function productFields(): array
    {
        return [
            Section::make('Section Produk')
                ->description('Menampilkan kartu produk pada halaman publik. Datanya diambil langsung dari menu Produk, jadi harga dan foto tidak perlu ditulis ulang di sini.')
                ->schema([
                    Toggle::make('show_products')
                        ->label('Tampilkan Produk')
                        ->helperText('Mematikan saklar ini menyembunyikan section-nya, TETAPI tidak menghapus pilihan produknya. Menyalakannya lagi memunculkan pilihan yang sama.')
                        ->default(false)
                        ->live(),

                    Select::make('product_ids')
                        ->label('Pilih Produk')
                        ->helperText('Urutannya mengikuti urutan pada menu Produk, bukan urutan pemilihan di sini. Saklar "Sorot di homepage" pada produk tidak berpengaruh di halaman ini.')
                        /*
                         | Label memuat kategori, tipe, dan status supaya admin
                         | tidak perlu membuka menu Produk untuk memastikan apa
                         | yang sedang dipilih. Produk nonaktif tetap boleh
                         | dipilih -- halaman publiklah yang menyembunyikannya.
                         */
                        ->options(fn (?LocationPage $record): array => app(LocationPageProductService::class)
                            ->options($record?->products()->pluck('products.id')->all() ?? []))
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->visible(fn (Get $get): bool => (bool) $get('show_products'))
                        ->required(fn (Get $get): bool => (bool) $get('show_products'))
                        ->validationMessages([
                            'required' => 'Pilih minimal satu produk selama "Tampilkan Produk" dinyalakan.',
                        ])
                        ->rule(function (): callable {
                            return function (string $attribute, mixed $value, callable $fail): void {
                                /*
                                 | Pemeriksaan server-side yang sesungguhnya.
                                 | Payload yang disisipkan langsung ke request
                                 | Livewire menempuh jalur ini juga, jadi id
                                 | karangan tetap ditolak.
                                 */
                                $service = app(LocationPageProductService::class);

                                try {
                                    $service->assertProductsExist($service->normalizeIds($value));
                                } catch (ValidationException $exception) {
                                    $fail($exception->validator->errors()->first('product_ids'));
                                }
                            };
                        }),

                    Placeholder::make('product_selection_note')
                        ->label('Catatan')
                        ->visible(fn (Get $get): bool => ! $get('show_products'))
                        ->content(fn (?LocationPage $record): HtmlString => new HtmlString(
                            self::hiddenProductsNote($record)
                        )),
                ]),
        ];
    }

    /**
     * Keterangan saat section produk dimatikan.
     *
     * Menyebutkan jumlah pilihan yang tersimpan supaya admin tahu relasinya
     * masih ada dan tidak perlu memilih ulang.
     */
    protected static function hiddenProductsNote(?LocationPage $record): string
    {
        $count = $record?->exists ? $record->products()->count() : 0;

        if ($count === 0) {
            return 'Section produk dimatikan. Nyalakan saklar di atas untuk memilih produk.';
        }

        return 'Section produk dimatikan, tetapi <strong>'.$count.' produk</strong> yang pernah dipilih '
            .'tetap tersimpan. Nyalakan saklar di atas untuk memunculkannya kembali.';
    }

    /**
     * @return array<mixed>
     */
    protected static function posterFields(): array
    {
        return [
            UploadedImage::locationPagePoster()
                ->disabled(fn (): bool => ! self::canManageMedia())
                ->dehydrated(fn (): bool => self::canManageMedia()),

            TextInput::make('poster_alt')
                ->label('Teks alternatif poster')
                ->helperText('Menjelaskan isi gambar untuk pembaca layar dan saat gambar gagal dimuat.')
                ->maxLength(180)
                ->disabled(fn (): bool => ! self::canManageMedia())
                ->dehydrated(fn (): bool => self::canManageMedia()),
        ];
    }

    /**
     * @return array<mixed>
     */
    protected static function seoFields(): array
    {
        return [
            TextInput::make('seo_title')
                ->label('SEO title')
                ->helperText('Kosongkan untuk memakai judul halaman.')
                ->maxLength(160),

            Textarea::make('seo_description')
                ->label('SEO description')
                ->helperText('Kosongkan untuk memakai deskripsi singkat.')
                ->maxLength(500)
                ->rows(2),
        ];
    }

    /**
     * @return array<mixed>
     */
    protected static function publicationFields(): array
    {
        return [
            /*
             | Status EFEKTIF disebutkan lebih dulu, sebelum saklarnya, supaya
             | "kenapa URL saya 404" terjawab di tempat kejadian.
             */
            Placeholder::make('public_status')
                ->label('Status publik')
                ->content(fn (?LocationPage $record): HtmlString => new HtmlString(
                    self::publicStatusText($record),
                )),

            Toggle::make('is_active')
                ->label('Aktif')
                ->helperText('Satu-satunya saklar publikasi. Halaman aktif langsung dapat dibuka pengunjung; halaman nonaktif menghasilkan 404.')
                ->default(true)
                ->disabled(fn (): bool => ! self::canPublish())
                ->dehydrated(fn (): bool => self::canPublish()),

            Toggle::make('is_featured')
                ->label('Sorot di homepage')
                ->helperText('Halaman sorotan tampil lebih dulu pada daftar lokasi di homepage.'),
        ];
    }

    /**
     * Keterangan status publik beserta URL yang dituju.
     */
    protected static function publicStatusText(?LocationPage $record): string
    {
        if ($record === null) {
            return 'Halaman baru langsung dapat dibuka pengunjung begitu disimpan dalam keadaan aktif.';
        }

        $url = url('/alamat/'.$record->slug);
        $issue = $record->publicVisibilityIssue();

        if ($issue === null) {
            return 'AKTIF &mdash; dapat dibuka di <a href="'.e($url)
                .'" target="_blank" rel="noopener noreferrer" class="underline">'.e($url).'</a>';
        }

        return e($record->statusLabel()).' &mdash; '.e($issue).'<br>Alamat yang dituju: '.e($url);
    }

    protected static function slugHelper(?LocationPage $record): string
    {
        if (! self::canChangeSlug()) {
            return 'Anda tidak memiliki izin mengubah slug. Hubungi Admin bila URL perlu diganti.';
        }

        if ($record?->exists) {
            return 'PERINGATAN: mengganti slug mengubah URL yang mungkin sedang dipakai iklan. '
                .'Slug lama otomatis dialihkan 301 ke slug baru, tetapi laporan iklan bisa terpecah.';
        }

        return 'Bagian akhir URL halaman. Sebaiknya ditetapkan sekali dan tidak diubah lagi.';
    }

    protected static function canChangeSlug(): bool
    {
        return auth()->user()?->can(PanelPermission::ChangeLocationPageSlugs->value) ?? false;
    }

    protected static function canPublish(): bool
    {
        return auth()->user()?->can(PanelPermission::PublishLocationPages->value) ?? false;
    }

    protected static function canManageMedia(): bool
    {
        return auth()->user()?->can(PanelPermission::ManageLocationPageMedia->value) ?? false;
    }
}
