<?php

namespace App\Filament\Pages;

use App\Enums\PanelPermission;
use App\Filament\Support\UploadedImage;
use App\Rules\SafeLink;
use App\Services\SiteSettingsService;
use App\Support\WhatsAppNumber;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Halaman pengaturan website (singleton).
 *
 * Identitas brand, kontak, social media, dan default SEO. Tidak ada input
 * untuk HTML mentah, JavaScript, maupun tracking script -- seluruh field
 * adalah teks biasa, URL yang divalidasi, atau upload gambar.
 */
class ManageSiteSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Pengaturan Website';

    protected static string|UnitEnum|null $navigationGroup = 'Konten Website';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'pengaturan-website';

    protected string $view = 'filament.pages.manage-site-settings';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * Menutup DUA jalur sekaligus: item navigasi tidak didaftarkan, dan
     * mountCanAuthorizeAccess() milik Filament menolak akses URL langsung
     * dengan 403. Gate global juga sudah menolak seluruh user nonaktif.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->can(PanelPermission::ManageSiteSettings->value) ?? false;
    }

    public function getTitle(): string|Htmlable
    {
        return 'Pengaturan Website';
    }

    public function getSubheading(): ?string
    {
        return 'Identitas brand, kontak resmi, dan metadata default. Perubahan yang disimpan langsung tampil di website.';
    }

    public function mount(): void
    {
        $record = app(SiteSettingsService::class)->recordOrCreate();

        $this->form->fill([
            'brand_name' => $record->brand_name,
            'tagline' => $record->tagline,
            'positioning' => $record->positioning,
            'whatsapp_number' => $record->whatsapp_number,
            'instagram_url' => $record->instagram_url,
            'tiktok_url' => $record->tiktok_url,
            'facebook_url' => $record->facebook_url,
            'default_meta_title' => $record->default_meta_title,
            'default_meta_description' => $record->default_meta_description,
            'default_og_image_path' => $record->default_og_image_path,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identitas Brand')
                    ->description('Nama dan pesan utama yang tampil di header, footer, dan metadata.')
                    ->schema([
                        TextInput::make('brand_name')
                            ->label('Nama brand')
                            ->required()
                            ->maxLength(60),

                        TextInput::make('tagline')
                            ->label('Tagline')
                            ->helperText('Tampil di hero dan footer.')
                            ->required()
                            ->maxLength(160),

                        TextInput::make('positioning')
                            ->label('Positioning')
                            ->helperText('Kalimat pendek yang menjelaskan posisi brand. Tampil di footer.')
                            ->required()
                            ->maxLength(160),
                    ]),

                Section::make('Kontak dan Social Media')
                    ->description('Kosongkan bila kanal resminya belum tersedia. Field kosong tidak akan dirender sebagai tautan di website.')
                    ->schema([
                        TextInput::make('whatsapp_number')
                            ->label('Nomor WhatsApp')
                            ->helperText('Boleh ditulis 0812..., +62812..., atau 62812... Nomor akan dirapikan otomatis saat disimpan.')
                            ->tel()
                            // Regex bawaan Filament menolak spasi dan tanda
                            // hubung; format Indonesia justru lazim memakainya.
                            // Bentuk akhirnya tetap dirapikan WhatsAppNumber.
                            ->telRegex('/^[\d\s+()-]+$/')
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

                        TextInput::make('instagram_url')
                            ->label('Instagram URL')
                            ->placeholder('https://www.instagram.com/...')
                            ->maxLength(255)
                            ->rule(SafeLink::externalOnly()),

                        TextInput::make('tiktok_url')
                            ->label('TikTok URL')
                            ->placeholder('https://www.tiktok.com/@...')
                            ->maxLength(255)
                            ->rule(SafeLink::externalOnly()),

                        TextInput::make('facebook_url')
                            ->label('Facebook URL')
                            ->placeholder('https://www.facebook.com/...')
                            ->maxLength(255)
                            ->rule(SafeLink::externalOnly()),
                    ]),

                Section::make('SEO Default')
                    ->description('Dipakai sebagai judul, deskripsi, dan gambar bagikan default untuk seluruh halaman publik.')
                    ->schema([
                        TextInput::make('default_meta_title')
                            ->label('Meta title default')
                            ->helperText('Idealnya di bawah 60 karakter agar tidak terpotong di hasil pencarian.')
                            ->required()
                            ->maxLength(70),

                        Textarea::make('default_meta_description')
                            ->label('Meta description default')
                            ->helperText('Idealnya 120-160 karakter.')
                            ->required()
                            ->maxLength(300)
                            ->rows(3),

                        UploadedImage::ogImage(),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->form->getState();

        $record = app(SiteSettingsService::class)->recordOrCreate();
        $previousOgImage = $record->default_og_image_path;

        /*
         | Database dan file diperlakukan sebagai satu unit: gambar lama baru
         | dihapus SETELAH transaksi commit. Bila penyimpanan gagal, data lama
         | dan file lama tetap utuh.
         */
        DB::transaction(function () use ($record, $data): void {
            $record->fill([
                'brand_name' => trim($data['brand_name']),
                'tagline' => trim($data['tagline']),
                'positioning' => trim($data['positioning']),
                'whatsapp_number' => WhatsAppNumber::normalize($data['whatsapp_number'] ?? null),
                'instagram_url' => $this->cleanUrl($data['instagram_url'] ?? null),
                'tiktok_url' => $this->cleanUrl($data['tiktok_url'] ?? null),
                'facebook_url' => $this->cleanUrl($data['facebook_url'] ?? null),
                'default_meta_title' => trim($data['default_meta_title']),
                'default_meta_description' => trim($data['default_meta_description']),
                'default_og_image_path' => $data['default_og_image_path'] ?: null,
                'updated_by' => auth()->id(),
            ])->save();
        });

        UploadedImage::deleteReplaced($previousOgImage, $record->default_og_image_path);

        // Isi ulang form dengan nilai yang benar-benar tersimpan, supaya admin
        // langsung melihat hasil normalisasi nomor WhatsApp.
        $this->form->fill($record->only([
            'brand_name', 'tagline', 'positioning', 'whatsapp_number',
            'instagram_url', 'tiktok_url', 'facebook_url',
            'default_meta_title', 'default_meta_description', 'default_og_image_path',
        ]));

        Notification::make()
            ->success()
            ->title('Pengaturan website tersimpan')
            ->body('Perubahan sudah tampil di website.')
            ->send();
    }

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
                ->label('Lihat Website')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->url(fn (): string => route('home'), shouldOpenInNewTab: true),
        ];
    }

    protected function cleanUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
