<?php

namespace App\Filament\Pages;

use App\Enums\PanelPermission;
use App\Filament\Support\UploadedImage;
use App\Models\HomepageSetting;
use App\Rules\SafeLink;
use App\Services\HomepageContentService;
use App\Services\SiteSettingsService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Homepage CMS (singleton).
 *
 * Halaman ini HANYA mengubah teks, tautan, gambar, serta urutan dan status
 * aktif section. Nama file Blade, path view, dan template bebas TIDAK PERNAH
 * berasal dari halaman ini: admin hanya memilih key section yang sudah
 * terdaftar di config('homepage.section_views').
 */
class ManageHomepage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static ?string $navigationLabel = 'Homepage';

    protected static string|UnitEnum|null $navigationGroup = 'Konten Website';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'homepage';

    protected string $view = 'filament.pages.manage-homepage';

    /**
     * Label section yang tampil di tab "Susunan Section".
     * Key-nya WAJIB sama dengan key di config('homepage.section_views').
     */
    public const SECTION_LABELS = [
        'hero' => 'Hero',
        'usp' => 'Keunggulan (USP)',
        'products' => 'Pilihan Dimsum',
        'how' => 'Cara Jajan',
        'budget' => 'Budget',
        'locations' => 'Lokasi',
        'cta' => 'CTA Penutup',
    ];

    /**
     * Hero selalu aktif: tanpa hero, halaman kehilangan judul utama (h1) dan
     * seluruh konteks brand. Ini juga menjamin section tidak pernah kosong.
     */
    public const REQUIRED_SECTION = 'hero';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can(PanelPermission::ManageHomepage->value) ?? false;
    }

    public function getTitle(): string|Htmlable
    {
        return 'Homepage';
    }

    public function getSubheading(): ?string
    {
        return 'Ubah copy, urutan, dan status aktif setiap section. Perubahan yang disimpan langsung tampil di website.';
    }

    public function mount(): void
    {
        $record = app(HomepageContentService::class)->recordOrCreate();

        $this->form->fill([
            'hero' => $this->heroState($record),
            'usp' => $record->usp,
            'products' => $record->products,
            'how' => $record->how,
            'budget' => $record->budget,
            'locations' => $record->locations,
            'cta' => $record->cta,
            'sections' => $this->sectionsState($record),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Konten Homepage')
                    ->persistTabInQueryString()
                    ->tabs([
                        Tabs\Tab::make('Hero')->schema($this->heroFields()),
                        Tabs\Tab::make('Keunggulan')->schema($this->uspFields()),
                        Tabs\Tab::make('Pilihan Dimsum')->schema($this->productsFields()),
                        Tabs\Tab::make('Cara Jajan')->schema($this->howFields()),
                        Tabs\Tab::make('Budget')->schema($this->budgetFields()),
                        Tabs\Tab::make('Lokasi')->schema($this->locationsFields()),
                        Tabs\Tab::make('CTA Penutup')->schema($this->ctaFields()),
                        Tabs\Tab::make('Susunan Section')->schema($this->sectionFields()),
                    ]),
            ])
            ->statePath('data');
    }

    // --------------------------------------------------------------- fields

    /**
     * @return array<mixed>
     */
    protected function heroFields(): array
    {
        return [
            TextInput::make('hero.eyebrow')
                ->label('Eyebrow')
                ->helperText('Teks kecil di atas headline.')
                ->required()
                ->maxLength(80),

            TextInput::make('hero.headline')
                ->label('Headline')
                ->helperText('Judul utama halaman (h1).')
                ->required()
                ->maxLength(120),

            Textarea::make('hero.description')
                ->label('Deskripsi')
                ->required()
                ->maxLength(300)
                ->rows(3),

            TagsInput::make('hero.highlights')
                ->label('Highlight')
                ->helperText('Label pendek di bawah tombol. Maksimal '.HomepageContentService::MAX_HIGHLIGHTS.' item. Tekan Enter untuk menambah.')
                ->rules(['array', 'max:'.HomepageContentService::MAX_HIGHLIGHTS])
                ->nestedRecursiveRules(['string', 'max:40']),

            $this->ctaFieldset('hero.primary_cta', 'Tombol Utama'),
            $this->ctaFieldset('hero.secondary_cta', 'Tombol Kedua'),

            Fieldset::make('Foto Hero')
                ->schema([
                    UploadedImage::make('hero.image.path', UploadedImage::HERO_DIRECTORY)
                        ->label('Foto hero')
                        ->helperText('JPG, PNG, atau WebP. Maksimal 3 MB. Kosongkan untuk memakai lockup logo DIMDUM.')
                        ->imageEditor(),

                    TextInput::make('hero.image.alt')
                        ->label('Teks alternatif (alt)')
                        ->helperText('Deskripsi singkat isi foto untuk pembaca layar. Wajib diisi bila foto di-upload.')
                        ->maxLength(160)
                        ->required(fn (Get $get): bool => filled($get('hero.image.path'))),
                ]),

            $this->taglineNotice(),
        ];
    }

    /**
     * @return array<mixed>
     */
    protected function uspFields(): array
    {
        return [
            TextInput::make('usp.title')
                ->label('Judul section')
                ->required()
                ->maxLength(120),

            Repeater::make('usp.items')
                ->label('Daftar keunggulan')
                ->helperText('Geser untuk mengubah urutan tampil. Maksimal '.HomepageContentService::MAX_USP_ITEMS.' item.')
                ->reorderable()
                ->maxItems(HomepageContentService::MAX_USP_ITEMS)
                ->minItems(1)
                ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                ->collapsible()
                ->schema([
                    TextInput::make('title')
                        ->label('Judul')
                        ->required()
                        ->maxLength(80),

                    Textarea::make('description')
                        ->label('Deskripsi')
                        ->required()
                        ->maxLength(240)
                        ->rows(2),
                ]),
        ];
    }

    /**
     * @return array<mixed>
     */
    protected function productsFields(): array
    {
        return [
            TextInput::make('products.title')
                ->label('Judul section')
                ->required()
                ->maxLength(120),

            Textarea::make('products.description')
                ->label('Deskripsi section')
                ->required()
                ->maxLength(300)
                ->rows(2),

            Fieldset::make('Empty State')
                ->schema([
                    TextInput::make('products.empty_state.title')
                        ->label('Judul saat daftar varian kosong')
                        ->required()
                        ->maxLength(120),

                    Textarea::make('products.empty_state.description')
                        ->label('Deskripsi saat daftar varian kosong')
                        ->required()
                        ->maxLength(300)
                        ->rows(2),
                ])
                ->columns(1),
        ];
    }

    /**
     * @return array<mixed>
     */
    protected function howFields(): array
    {
        return [
            TextInput::make('how.title')
                ->label('Judul section')
                ->required()
                ->maxLength(120),

            Repeater::make('how.steps')
                ->label('Langkah cara jajan')
                ->helperText('Geser untuk mengubah urutan. Nomor langkah dibuat otomatis. Maksimal '.HomepageContentService::MAX_STEPS.' langkah.')
                ->reorderable()
                ->maxItems(HomepageContentService::MAX_STEPS)
                ->minItems(1)
                ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                ->collapsible()
                ->schema([
                    TextInput::make('title')
                        ->label('Judul langkah')
                        ->required()
                        ->maxLength(80),

                    Textarea::make('description')
                        ->label('Deskripsi langkah')
                        ->required()
                        ->maxLength(240)
                        ->rows(2),
                ]),

            Textarea::make('how.closing')
                ->label('Teks penutup')
                ->required()
                ->maxLength(300)
                ->rows(2),
        ];
    }

    /**
     * @return array<mixed>
     */
    protected function budgetFields(): array
    {
        return [
            TextInput::make('budget.title')
                ->label('Judul')
                ->required()
                ->maxLength(120),

            Textarea::make('budget.copy')
                ->label('Copy utama')
                ->required()
                ->maxLength(300)
                ->rows(2),

            Textarea::make('budget.support_copy')
                ->label('Copy pendukung')
                ->required()
                ->maxLength(300)
                ->rows(2),
        ];
    }

    /**
     * @return array<mixed>
     */
    protected function locationsFields(): array
    {
        return [
            TextInput::make('locations.title')
                ->label('Judul')
                ->required()
                ->maxLength(120),

            Textarea::make('locations.description')
                ->label('Deskripsi')
                ->required()
                ->maxLength(300)
                ->rows(2),

            Fieldset::make('Empty State')
                ->schema([
                    TextInput::make('locations.coming_soon_title')
                        ->label('Judul saat daftar lokasi kosong')
                        ->required()
                        ->maxLength(120),

                    Textarea::make('locations.coming_soon_description')
                        ->label('Deskripsi saat daftar lokasi kosong')
                        ->required()
                        ->maxLength(300)
                        ->rows(2),
                ])
                ->columns(1),
        ];
    }

    /**
     * @return array<mixed>
     */
    protected function ctaFields(): array
    {
        return [
            TextInput::make('cta.headline')
                ->label('Headline')
                ->required()
                ->maxLength(120),

            Textarea::make('cta.description')
                ->label('Deskripsi')
                ->required()
                ->maxLength(300)
                ->rows(2),

            $this->ctaFieldset('cta.primary_cta', 'Tombol Utama'),
            $this->ctaFieldset('cta.secondary_cta', 'Tombol Kedua'),
        ];
    }

    /**
     * @return array<mixed>
     */
    protected function sectionFields(): array
    {
        return [
            Repeater::make('sections')
                ->label('Urutan dan status section')
                ->helperText('Geser untuk mengubah urutan tampil. Matikan toggle untuk menyembunyikan section dari website. Section Hero selalu aktif.')
                ->reorderable()
                // Section hanya boleh diaktifkan/dinonaktifkan dan diurutkan.
                // Menambah atau menghapus baris tidak diizinkan, sehingga key
                // di database selalu berasal dari allowlist.
                ->addable(false)
                ->deletable(false)
                ->itemLabel(fn (array $state): string => self::SECTION_LABELS[$state['key'] ?? ''] ?? 'Section')
                ->schema([
                    Hidden::make('key'),

                    Toggle::make('enabled')
                        ->label('Tampilkan di website')
                        ->inline(false)
                        ->disabled(fn (Get $get): bool => $get('key') === self::REQUIRED_SECTION)
                        ->dehydrated(),
                ]),
        ];
    }

    /**
     * Pasangan label + tautan yang selalu divalidasi dengan aturan yang sama.
     */
    protected function ctaFieldset(string $statePath, string $label): Fieldset
    {
        return Fieldset::make($label)
            ->schema([
                TextInput::make($statePath.'.label')
                    ->label('Teks tombol')
                    ->required()
                    ->maxLength(60),

                TextInput::make($statePath.'.href')
                    ->label('Tautan tombol')
                    ->helperText('Anchor (#lokasi), path internal (/halaman), atau URL lengkap https://')
                    ->required()
                    ->maxLength(255)
                    ->rule(new SafeLink),
            ]);
    }

    protected function taglineNotice(): TextInput
    {
        return TextInput::make('tagline_notice')
            ->label('Tagline')
            ->disabled()
            ->dehydrated(false)
            ->helperText('Tagline hanya diubah di halaman Pengaturan Website supaya tidak ada dua sumber yang berbeda.')
            ->afterStateHydrated(fn (TextInput $component) => $component->state(
                app(SiteSettingsService::class)->brand()['tagline'],
            ));
    }

    // ----------------------------------------------------------------- save

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->form->getState();

        $record = app(HomepageContentService::class)->recordOrCreate();
        $previousHeroImage = $record->hero['image']['path'] ?? null;

        $hero = $this->normalizeHero($data['hero'] ?? []);
        $sections = $this->normalizeSections($data['sections'] ?? []);

        /*
         | Baris disimpan lebih dulu; file lama baru dihapus setelah commit.
         | Bila penyimpanan gagal, data lama dan gambar lama tetap utuh.
         */
        DB::transaction(function () use ($record, $data, $hero, $sections): void {
            $record->fill([
                'hero' => $hero,
                'usp' => [
                    'title' => trim($data['usp']['title']),
                    'items' => $this->withOrder($data['usp']['items'] ?? []),
                ],
                'products' => [
                    'title' => trim($data['products']['title']),
                    'description' => trim($data['products']['description']),
                    'empty_state' => [
                        'title' => trim($data['products']['empty_state']['title']),
                        'description' => trim($data['products']['empty_state']['description']),
                    ],
                ],
                'how' => [
                    'title' => trim($data['how']['title']),
                    'steps' => $this->withOrder($data['how']['steps'] ?? []),
                    'closing' => trim($data['how']['closing']),
                ],
                'budget' => [
                    'title' => trim($data['budget']['title']),
                    'copy' => trim($data['budget']['copy']),
                    'support_copy' => trim($data['budget']['support_copy']),
                ],
                'locations' => [
                    'title' => trim($data['locations']['title']),
                    'description' => trim($data['locations']['description']),
                    'coming_soon_title' => trim($data['locations']['coming_soon_title']),
                    'coming_soon_description' => trim($data['locations']['coming_soon_description']),
                ],
                'cta' => [
                    'headline' => trim($data['cta']['headline']),
                    'description' => trim($data['cta']['description']),
                    'primary_cta' => $this->normalizeCta($data['cta']['primary_cta'] ?? []),
                    'secondary_cta' => $this->normalizeCta($data['cta']['secondary_cta'] ?? []),
                ],
                'sections' => $sections,
                'updated_by' => auth()->id(),
            ])->save();
        });

        UploadedImage::deleteReplaced($previousHeroImage, $hero['image']['path'] ?? null);

        $record->refresh();

        $this->form->fill([
            'hero' => $this->heroState($record),
            'usp' => $record->usp,
            'products' => $record->products,
            'how' => $record->how,
            'budget' => $record->budget,
            'locations' => $record->locations,
            'cta' => $record->cta,
            'sections' => $this->sectionsState($record),
        ]);

        Notification::make()
            ->success()
            ->title('Homepage tersimpan')
            ->body('Perubahan sudah tampil di website.')
            ->send();

        $this->warnAboutHiddenSections($sections);
    }

    // -------------------------------------------------------------- helpers

    /**
     * @param  array<string, mixed>  $hero
     * @return array<string, mixed>
     */
    protected function normalizeHero(array $hero): array
    {
        $path = $hero['image']['path'] ?? null;
        $path = is_string($path) && trim($path) !== '' ? trim($path) : null;

        $alt = $hero['image']['alt'] ?? null;
        $alt = is_string($alt) ? trim($alt) : '';

        return [
            'eyebrow' => trim($hero['eyebrow'] ?? ''),
            'headline' => trim($hero['headline'] ?? ''),
            'description' => trim($hero['description'] ?? ''),
            'primary_cta' => $this->normalizeCta($hero['primary_cta'] ?? []),
            'secondary_cta' => $this->normalizeCta($hero['secondary_cta'] ?? []),
            'highlights' => array_values(array_filter(
                array_map(
                    fn ($value): string => is_string($value) ? trim($value) : '',
                    is_array($hero['highlights'] ?? null) ? $hero['highlights'] : [],
                ),
                fn (string $value): bool => $value !== '',
            )),
            // Tanpa file, seluruh key gambar dibuang supaya fallback lockup
            // logo yang dipakai -- bukan referensi ke file yang tidak ada.
            'image' => $path === null ? null : ['path' => $path, 'alt' => $alt],
        ];
    }

    /**
     * @param  array<string, mixed>  $cta
     * @return array{label: string, href: string}
     */
    protected function normalizeCta(array $cta): array
    {
        return [
            'label' => trim($cta['label'] ?? ''),
            'href' => trim($cta['href'] ?? ''),
        ];
    }

    /**
     * Tulis ulang key 'order' berdasarkan posisi hasil drag admin, supaya
     * urutan tersimpan eksplisit dan tidak bergantung pada urutan key JSON.
     *
     * @param  array<mixed>  $items
     * @return list<array<string, mixed>>
     */
    protected function withOrder(array $items): array
    {
        $ordered = [];
        $order = 1;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $ordered[] = [
                'title' => trim($item['title'] ?? ''),
                'description' => trim($item['description'] ?? ''),
                'order' => $order++,
            ];
        }

        return $ordered;
    }

    /**
     * Hanya key allowlist yang boleh tersimpan, tanpa duplikat, dan hero
     * selalu aktif.
     *
     * @param  array<mixed>  $sections
     * @return list<array{key: string, enabled: bool}>
     */
    protected function normalizeSections(array $sections): array
    {
        $allowed = array_keys(config('homepage.section_views', []));

        $normalized = [];
        $seen = [];

        foreach ($sections as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $key = $entry['key'] ?? null;

            if (! is_string($key) || ! in_array($key, $allowed, true) || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $normalized[] = [
                'key' => $key,
                'enabled' => $key === self::REQUIRED_SECTION
                    ? true
                    : (bool) filter_var($entry['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }

        // Key allowlist yang belum tercatat ditambahkan dalam keadaan nonaktif,
        // sehingga tidak ada section yang diam-diam muncul.
        foreach ($allowed as $key) {
            if (! isset($seen[$key])) {
                $normalized[] = ['key' => $key, 'enabled' => $key === self::REQUIRED_SECTION];
            }
        }

        return $normalized;
    }

    /**
     * @param  list<array{key: string, enabled: bool}>  $sections
     */
    protected function warnAboutHiddenSections(array $sections): void
    {
        $hidden = array_values(array_filter($sections, fn (array $entry): bool => ! $entry['enabled']));

        if ($hidden === []) {
            return;
        }

        $labels = array_map(
            fn (array $entry): string => self::SECTION_LABELS[$entry['key']] ?? $entry['key'],
            $hidden,
        );

        Notification::make()
            ->warning()
            ->title('Ada section yang disembunyikan')
            ->body('Tidak tampil di website: '.implode(', ', $labels).'.')
            ->send();
    }

    /**
     * @return array<string, mixed>
     */
    protected function heroState(HomepageSetting $record): array
    {
        $hero = $record->hero ?? [];

        // FileUpload dan TextInput butuh key yang selalu ada, walaupun kosong.
        $hero['image'] = [
            'path' => $hero['image']['path'] ?? null,
            'alt' => $hero['image']['alt'] ?? '',
        ];

        return $hero;
    }

    /**
     * Susun state tab "Susunan Section": urutan tersimpan lebih dulu, lalu key
     * allowlist yang belum tercatat. Key asing dibuang.
     *
     * @return list<array{key: string, enabled: bool}>
     */
    protected function sectionsState(HomepageSetting $record): array
    {
        $allowed = array_keys(config('homepage.section_views', []));
        $defaults = config('homepage.sections', []);

        $state = [];
        $seen = [];

        foreach ($record->sections ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $key = $entry['key'] ?? null;

            if (! is_string($key) || ! in_array($key, $allowed, true) || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $state[] = [
                'key' => $key,
                'enabled' => $key === self::REQUIRED_SECTION
                    ? true
                    : (bool) filter_var($entry['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }

        foreach ($allowed as $key) {
            if (! isset($seen[$key])) {
                $state[] = [
                    'key' => $key,
                    'enabled' => $key === self::REQUIRED_SECTION || in_array($key, $defaults, true),
                ];
            }
        }

        return $state;
    }

    // -------------------------------------------------------------- actions

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make($this->getFormActions())->key('form-actions'),
                ]),
        ]);
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Simpan Perubahan')
                ->submit('save')
                ->keyBindings(['mod+s']),

            Action::make('preview')
                ->label('Lihat Homepage')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->url(fn (): string => route('home'), shouldOpenInNewTab: true),
        ];
    }
}
