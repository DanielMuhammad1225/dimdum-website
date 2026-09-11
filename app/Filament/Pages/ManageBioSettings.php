<?php

namespace App\Filament\Pages;

use App\Enums\BioButton;
use App\Enums\BioLocationMode;
use App\Enums\PanelPermission;
use App\Models\BioSetting;
use App\Services\BioSettingsService;
use App\Support\WhatsAppNumber;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Pengaturan halaman Bio (singleton).
 *
 * SATU halaman, bukan sistem multi-Bio. Tidak ada input URL bebas di sini:
 *   - tujuan tombol Lokasi dan Menu ditentukan named route di kode;
 *   - tujuan tombol WhatsApp dibentuk service dari nomor yang tervalidasi;
 *   - sumber /bio/lokasi dipilih sebagai MODE, bukan sebagai alamat.
 *
 * Urutan tombol diatur dengan seret-dan-lepas, memakai pola yang sama dengan
 * susunan section homepage. Tidak ada angka urutan yang perlu diketik.
 */
class ManageBioSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static ?string $navigationLabel = 'Pengaturan Bio';

    protected static string|UnitEnum|null $navigationGroup = 'Bio';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'pengaturan-bio';

    protected string $view = 'filament.pages.manage-bio-settings';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * Menutup DUA jalur sekaligus: item navigasi tidak didaftarkan, dan
     * Filament menolak akses URL langsung dengan 403. Akun nonaktif sudah
     * ditolak lebih dulu oleh Gate::before global.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->can(PanelPermission::ManageBioSettings->value) ?? false;
    }

    public function getTitle(): string|Htmlable
    {
        return 'Pengaturan Bio';
    }

    public function getSubheading(): ?string
    {
        return 'Halaman tautan untuk profil social media: /bio, /bio/lokasi, dan /bio/produk.';
    }

    public function mount(): void
    {
        $record = app(BioSettingsService::class)->recordOrCreate();

        $this->form->fill($this->stateFrom($record));
    }

    /**
     * @return array<string, mixed>
     */
    protected function stateFrom(BioSetting $record): array
    {
        return [
            'is_active' => (bool) $record->is_active,
            'title' => $record->title,
            'description' => $record->description,
            'location_mode' => BioSettingsService::resolveLocationMode($record->location_mode)->value,
            'whatsapp_number' => $record->whatsapp_number,
            'whatsapp_message' => $record->whatsapp_message,
            'buttons' => BioSettingsService::normalizeButtons($record->buttons),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Status')
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Aktifkan halaman Bio')
                            ->helperText('Selama nonaktif, ketiga alamat Bio menampilkan halaman "belum tersedia" (404).'),
                    ]),

                Section::make('Identitas')
                    ->description('Kosongkan untuk memakai nama dan tagline dari Pengaturan Website.')
                    ->schema([
                        TextInput::make('title')
                            ->label('Judul')
                            ->maxLength(120),

                        Textarea::make('description')
                            ->label('Deskripsi singkat')
                            ->maxLength(500)
                            ->rows(3),
                    ]),

                Section::make('Tombol Utama')
                    ->description('Geser untuk mengubah urutan. Tombol teratas tampil paling menonjol. Tujuan tombol diatur sistem dan tidak bisa diubah di sini.')
                    ->schema([
                        Repeater::make('buttons')
                            ->hiddenLabel()
                            ->reorderable()
                            // Tombol hanya boleh diberi label, disembunyikan, dan
                            // diurutkan. Menambah atau menghapus baris tidak
                            // diizinkan, sehingga key di database selalu
                            // berasal dari allowlist BioButton.
                            ->addable(false)
                            ->deletable(false)
                            ->itemLabel(fn (array $state): string => BioButton::tryFrom((string) ($state['key'] ?? ''))?->adminName() ?? 'Tombol')
                            ->schema([
                                Hidden::make('key'),

                                TextInput::make('label')
                                    ->label('Label')
                                    ->maxLength(BioSettingsService::MAX_LABEL_LENGTH)
                                    ->placeholder(fn (Get $get): string => BioButton::tryFrom((string) $get('key'))?->defaultLabel() ?? ''),

                                Toggle::make('visible')
                                    ->label('Tampilkan')
                                    ->inline(false),
                            ])
                            ->columns(2),
                    ]),

                Section::make('WhatsApp')
                    ->description('Nomor KHUSUS Bio, terpisah dari nomor di Pengaturan Website. Bila kosong atau tidak valid, tombol WhatsApp tidak ditampilkan.')
                    ->schema([
                        TextInput::make('whatsapp_number')
                            ->label('Nomor WhatsApp')
                            ->helperText('Nomor seluler Indonesia. Boleh ditulis 0812..., +62812..., atau 62812... -- dirapikan otomatis saat disimpan.')
                            ->tel()
                            // Regex bawaan Filament menolak spasi dan tanda
                            // hubung; penulisan Indonesia justru lazim memakainya.
                            ->telRegex('/^[\d\s+()-]+$/')
                            ->maxLength(32)
                            ->rule(function (): callable {
                                return function (string $attribute, mixed $value, callable $fail): void {
                                    if (blank($value)) {
                                        return;
                                    }

                                    if (WhatsAppNumber::normalizeIndonesian($value) === null) {
                                        $fail('Nomor WhatsApp harus nomor seluler Indonesia, contoh: 081234567890.');
                                    }
                                };
                            }),

                        Textarea::make('whatsapp_message')
                            ->label('Pesan pembuka')
                            ->helperText('Opsional. Teks yang sudah terisi saat pengunjung membuka chat.')
                            ->maxLength(500)
                            ->rows(3),
                    ]),

                Section::make('Halaman Lokasi')
                    ->description('Menentukan isi /bio/lokasi. Alamatnya tetap sama apa pun mode yang dipilih.')
                    ->schema([
                        Radio::make('location_mode')
                            ->label('Sumber lokasi')
                            ->options(BioLocationMode::options())
                            ->descriptions(collect(BioLocationMode::cases())
                                ->mapWithKeys(fn (BioLocationMode $mode): array => [$mode->value => $mode->description()])
                                ->all())
                            ->required()
                            // Nilai di luar enum ditolak server-side.
                            ->in(BioLocationMode::values()),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->form->getState();

        $record = app(BioSettingsService::class)->recordOrCreate();

        DB::transaction(function () use ($record, $data): void {
            $record->fill([
                'is_active' => (bool) ($data['is_active'] ?? false),
                'title' => $this->cleanText($data['title'] ?? null),
                'description' => $this->cleanText($data['description'] ?? null),
                'location_mode' => BioSettingsService::resolveLocationMode($data['location_mode'] ?? null)->value,
                // Disimpan dalam bentuk ternormalisasi. Nilai yang lolos form
                // tetapi tidak sah (mis. dikirim langsung ke Livewire) menjadi
                // null -- tombolnya lalu tidak ditampilkan.
                'whatsapp_number' => WhatsAppNumber::normalizeIndonesian($data['whatsapp_number'] ?? null),
                'whatsapp_message' => $this->cleanText($data['whatsapp_message'] ?? null),
                'buttons' => BioSettingsService::normalizeButtons($data['buttons'] ?? []),
                'updated_by' => auth()->id(),
            ])->save();
        });

        // Isi ulang dengan nilai yang benar-benar tersimpan, supaya admin
        // langsung melihat hasil normalisasi nomor dan tombol.
        $this->form->fill($this->stateFrom($record->refresh()));

        $this->notifyAboutWhatsApp($record);

        Notification::make()
            ->success()
            ->title('Pengaturan Bio tersimpan')
            ->body($record->is_active ? 'Perubahan sudah tampil di halaman Bio.' : 'Halaman Bio masih nonaktif.')
            ->send();
    }

    /**
     * Tombol WhatsApp yang dinyalakan tanpa nomor sah tidak akan tampil.
     * Admin diberi tahu di tempat, bukan dibiarkan menemukannya sendiri.
     */
    protected function notifyAboutWhatsApp(BioSetting $record): void
    {
        $whatsappVisible = collect(BioSettingsService::normalizeButtons($record->buttons))
            ->contains(fn (array $button): bool => $button['key'] === BioButton::WhatsApp->value && $button['visible']);

        if (! $whatsappVisible || $record->whatsapp_number !== null) {
            return;
        }

        Notification::make()
            ->warning()
            ->title('Tombol WhatsApp belum tampil')
            ->body('Tombolnya dinyalakan, tetapi nomor WhatsApp Bio masih kosong.')
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
                ->label('Lihat Halaman Bio')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->url(fn (): string => route('bio.home'), shouldOpenInNewTab: true),
        ];
    }

    protected function cleanText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
