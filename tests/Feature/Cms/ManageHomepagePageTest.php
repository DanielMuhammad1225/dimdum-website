<?php

namespace Tests\Feature\Cms;

use App\Enums\UserRole;
use App\Filament\Pages\ManageHomepage;
use App\Models\HomepageSetting;
use App\Models\User;
use Database\Seeders\HomepageContentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ManageHomepagePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(HomepageContentSeeder::class);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(UserRole::SuperAdmin->value);

        $this->actingAs($user->fresh());
    }

    protected function setting(): HomepageSetting
    {
        return HomepageSetting::query()->firstOrFail();
    }

    /**
     * State form lengkap, dibangun dari baris yang sudah di-seed supaya setiap
     * test hanya perlu menimpa bagian yang diuji.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function formState(array $overrides = []): array
    {
        $record = $this->setting();

        $hero = $record->hero;
        $hero['image'] = ['path' => null, 'alt' => ''];

        return [
            'hero' => $hero,
            'usp' => $record->usp,
            'products' => $record->products,
            'how' => $record->how,
            'budget' => $record->budget,
            'locations' => $record->locations,
            'cta' => $record->cta,
            'sections' => $record->sections,
            ...$overrides,
        ];
    }

    // ------------------------------------------------------------- rendering

    public function test_the_page_loads_with_the_stored_content(): void
    {
        Livewire::test(ManageHomepage::class)
            ->assertSuccessful()
            ->assertSchemaStateSet([
                'hero.headline' => config('homepage.hero.headline'),
                'usp.title' => config('homepage.usp.title'),
                'budget.title' => config('homepage.budget.title'),
            ]);
    }

    public function test_every_tab_is_present(): void
    {
        $html = Livewire::test(ManageHomepage::class)->html();

        foreach ([
            'Hero', 'Keunggulan', 'Pilihan Dimsum', 'Cara Jajan',
            'Budget', 'Lokasi', 'CTA Penutup', 'Susunan Section',
        ] as $tab) {
            $this->assertStringContainsString($tab, $html, "Tab {$tab} tidak ditemukan.");
        }
    }

    public function test_it_offers_a_preview_link_to_the_homepage(): void
    {
        Livewire::test(ManageHomepage::class)
            ->assertSuccessful()
            ->assertSee('Lihat Homepage')
            ->assertSee('Simpan Perubahan');
    }

    // ------------------------------------------------------------ menyimpan

    public function test_it_saves_hero_copy_and_shows_it_on_the_homepage(): void
    {
        $hero = $this->setting()->hero;
        $hero['image'] = ['path' => null, 'alt' => ''];
        $hero['headline'] = 'Headline Dari Panel';
        $hero['eyebrow'] = 'Eyebrow Dari Panel';
        $hero['description'] = 'Deskripsi dari panel admin.';

        Livewire::test(ManageHomepage::class)
            ->fillForm($this->formState(['hero' => $hero]))
            ->call('save')
            ->assertHasNoFormErrors();

        $this->get('/')
            ->assertOk()
            ->assertSee('Headline Dari Panel', false)
            ->assertSee('Eyebrow Dari Panel', false)
            ->assertSee('Deskripsi dari panel admin.', false);
    }

    public function test_it_records_who_saved_the_change(): void
    {
        $userId = auth()->id();

        Livewire::test(ManageHomepage::class)
            ->fillForm($this->formState())
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($userId, $this->setting()->updated_by);
    }

    public function test_repeater_items_are_stored_with_an_explicit_order(): void
    {
        $usp = [
            'title' => 'Keunggulan Baru',
            'items' => [
                ['title' => 'Kedua', 'description' => 'Deskripsi kedua.'],
                ['title' => 'Pertama', 'description' => 'Deskripsi pertama.'],
            ],
        ];

        Livewire::test(ManageHomepage::class)
            ->fillForm($this->formState(['usp' => $usp]))
            ->call('save')
            ->assertHasNoFormErrors();

        $stored = $this->setting()->usp['items'];

        $this->assertSame([1, 2], array_column($stored, 'order'));
        $this->assertSame(['Kedua', 'Pertama'], array_column($stored, 'title'));

        $content = $this->get('/')->getContent();

        $this->assertLessThan(
            strpos($content, 'Deskripsi pertama.'),
            strpos($content, 'Deskripsi kedua.'),
            'Urutan USP di homepage harus mengikuti urutan yang disimpan admin.'
        );
    }

    public function test_it_saves_the_steps_of_how_it_works(): void
    {
        $how = [
            'title' => 'Cara Jajan Baru',
            'closing' => 'Penutup baru.',
            'steps' => [
                ['title' => 'Langkah A', 'description' => 'Deskripsi A.'],
                ['title' => 'Langkah B', 'description' => 'Deskripsi B.'],
            ],
        ];

        Livewire::test(ManageHomepage::class)
            ->fillForm($this->formState(['how' => $how]))
            ->call('save')
            ->assertHasNoFormErrors();

        $this->get('/')
            ->assertSee('Cara Jajan Baru', false)
            ->assertSee('Langkah A', false)
            ->assertSee('Langkah B', false)
            ->assertSee('Penutup baru.', false);
    }

    // ------------------------------------------------------ susunan section

    public function test_it_can_disable_a_section(): void
    {
        $sections = array_map(
            fn (array $entry): array => [
                'key' => $entry['key'],
                'enabled' => $entry['key'] !== 'budget',
            ],
            $this->setting()->sections,
        );

        Livewire::test(ManageHomepage::class)
            ->fillForm($this->formState(['sections' => $sections]))
            ->call('save')
            ->assertHasNoFormErrors();

        $this->get('/')
            ->assertOk()
            ->assertDontSee(config('homepage.budget.title'), false)
            ->assertSee(config('homepage.hero.headline'), false);
    }

    public function test_hero_stays_enabled_even_when_submitted_as_disabled(): void
    {
        $sections = array_map(
            fn (array $entry): array => ['key' => $entry['key'], 'enabled' => false],
            $this->setting()->sections,
        );

        Livewire::test(ManageHomepage::class)
            ->fillForm($this->formState(['sections' => $sections]))
            ->call('save')
            ->assertHasNoFormErrors();

        $stored = collect($this->setting()->sections)->firstWhere('key', 'hero');

        $this->assertTrue($stored['enabled'], 'Hero tidak boleh bisa dimatikan.');

        // Halaman tetap punya satu h1 dan tidak pernah kosong total.
        $content = $this->get('/')->getContent();

        $this->assertSame(1, substr_count($content, '<h1'));
    }

    public function test_unknown_section_keys_submitted_by_hand_are_dropped(): void
    {
        $sections = [
            ['key' => 'hero', 'enabled' => true],
            ['key' => 'sections.budget', 'enabled' => true],
            ['key' => '../../../etc/passwd', 'enabled' => true],
            ['key' => 'cta', 'enabled' => true],
        ];

        Livewire::test(ManageHomepage::class)
            ->fillForm($this->formState(['sections' => $sections]))
            ->call('save')
            ->assertHasNoFormErrors();

        $keys = array_column($this->setting()->sections, 'key');

        $this->assertSame(
            ['hero', 'cta', 'usp', 'products', 'how', 'budget', 'locations'],
            $keys,
            'Key asing dibuang; key allowlist yang belum tercatat ditambahkan dalam keadaan nonaktif.'
        );

        foreach ($this->setting()->sections as $entry) {
            $this->assertContains($entry['key'], array_keys(config('homepage.section_views')));
        }
    }

    public function test_duplicate_section_keys_are_collapsed_on_save(): void
    {
        $sections = [
            ['key' => 'hero', 'enabled' => true],
            ['key' => 'cta', 'enabled' => true],
            ['key' => 'cta', 'enabled' => false],
        ];

        Livewire::test(ManageHomepage::class)
            ->fillForm($this->formState(['sections' => $sections]))
            ->call('save')
            ->assertHasNoFormErrors();

        $keys = array_column($this->setting()->sections, 'key');

        $this->assertSame(count($keys), count(array_unique($keys)), 'Tidak boleh ada key duplikat.');
    }

    // ---------------------------------------------------------- validasi CTA

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeCtaProvider(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'data uri' => ['data:text/html,<script>alert(1)</script>'],
            'vbscript' => ['vbscript:msgbox(1)'],
            'file' => ['file:///etc/passwd'],
            'protocol relative' => ['//evil.example.com'],
            'empty anchor' => ['#'],
            'backslash smuggling' => ['/\\evil.example.com'],
        ];
    }

    #[DataProvider('unsafeCtaProvider')]
    public function test_it_rejects_unsafe_cta_links(string $href): void
    {
        $hero = $this->setting()->hero;
        $hero['image'] = ['path' => null, 'alt' => ''];
        $hero['primary_cta'] = ['label' => 'Klik', 'href' => $href];

        Livewire::test(ManageHomepage::class)
            ->fillForm($this->formState(['hero' => $hero]))
            ->call('save')
            ->assertHasFormErrors(['hero.primary_cta.href']);

        $this->assertSame(
            config('homepage.hero.primary_cta.href'),
            $this->setting()->hero['primary_cta']['href'],
            'Tautan lama harus tetap utuh ketika penyimpanan ditolak.'
        );
    }

    public function test_it_accepts_safe_cta_links(): void
    {
        $hero = $this->setting()->hero;
        $hero['image'] = ['path' => null, 'alt' => ''];
        $hero['primary_cta'] = ['label' => 'Anchor', 'href' => '#cara-jajan'];
        $hero['secondary_cta'] = ['label' => 'Eksternal', 'href' => 'https://wa.me/6281234567890'];

        Livewire::test(ManageHomepage::class)
            ->fillForm($this->formState(['hero' => $hero]))
            ->call('save')
            ->assertHasNoFormErrors();

        $this->get('/')
            ->assertSee('href="#cara-jajan"', false)
            ->assertSee('https://wa.me/6281234567890', false);
    }

    public function test_a_dangerous_href_written_straight_into_the_database_is_neutralised(): void
    {
        $setting = $this->setting();
        $hero = $setting->hero;
        $hero['primary_cta'] = ['label' => 'Klik', 'href' => 'javascript:alert(1)'];
        $setting->update(['hero' => $hero]);

        $content = $this->get('/')->getContent();

        $this->assertStringNotContainsString('javascript:', $content);
        // Jatuh kembali ke tautan config, bukan menjadi link mati.
        $this->assertStringContainsString('href="'.config('homepage.hero.primary_cta.href').'"', $content);
    }

    // ----------------------------------------------------------------- XSS

    public function test_script_in_homepage_copy_is_escaped(): void
    {
        $hero = $this->setting()->hero;
        $hero['image'] = ['path' => null, 'alt' => ''];
        $hero['headline'] = '<script>alert("xss")</script>';

        Livewire::test(ManageHomepage::class)
            ->fillForm($this->formState(['hero' => $hero]))
            ->call('save')
            ->assertHasNoFormErrors();

        $content = $this->get('/')->getContent();

        $this->assertStringNotContainsString('<script>alert("xss")</script>', $content);
        $this->assertStringContainsString('&lt;script&gt;', $content);
    }

    // ---------------------------------------------------------- required

    public function test_required_copy_fields_are_enforced(): void
    {
        $hero = $this->setting()->hero;
        $hero['image'] = ['path' => null, 'alt' => ''];
        $hero['headline'] = '';
        $hero['eyebrow'] = '';

        Livewire::test(ManageHomepage::class)
            ->fillForm($this->formState(['hero' => $hero]))
            ->call('save')
            ->assertHasFormErrors(['hero.headline', 'hero.eyebrow']);
    }

    public function test_no_form_field_can_carry_a_blade_view_name(): void
    {
        $html = Livewire::test(ManageHomepage::class)->html();

        // Setiap field terikat ke state lewat wire:model. Nama binding inilah
        // permukaan input yang sebenarnya -- bukan seluruh markup halaman.
        preg_match_all('/wire:model[^=]*="([^"]+)"/', $html, $matches);

        $this->assertNotEmpty($matches[1], 'Form seharusnya punya field yang terikat state.');

        foreach (array_unique($matches[1]) as $binding) {
            foreach (['blade', 'view', 'template', 'path', 'include', 'file'] as $forbidden) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $forbidden,
                    // 'image.path' adalah upload gambar, bukan path template.
                    str_replace('image.path', 'image.upload', $binding),
                    "Field terikat ke state yang mencurigakan: {$binding}"
                );
            }
        }
    }

    public function test_stored_section_keys_are_never_blade_view_names(): void
    {
        $allowed = array_keys(config('homepage.section_views'));

        foreach ($this->setting()->sections as $entry) {
            $this->assertContains($entry['key'], $allowed);
            $this->assertStringNotContainsString('.', $entry['key']);
            $this->assertStringNotContainsString('/', $entry['key']);
        }
    }
}
