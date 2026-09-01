<?php

namespace Tests\Feature\Cms;

use App\Enums\UserRole;
use App\Filament\Pages\ManageSiteSettings;
use App\Models\SiteSetting;
use App\Models\User;
use Database\Seeders\HomepageContentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ManageSiteSettingsPageTest extends TestCase
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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function validPayload(array $overrides = []): array
    {
        return [
            'brand_name' => 'DIMDUM',
            'tagline' => 'Dimsum mulai Rp1.000, bikin ketagihan.',
            'positioning' => 'Fun & Affordable Dimsum Street Food',
            'whatsapp_number' => null,
            'instagram_url' => null,
            'tiktok_url' => null,
            'facebook_url' => null,
            'default_meta_title' => 'DIMDUM | Dimsum Mulai Rp1.000',
            'default_meta_description' => 'Street food dimsum satuan mulai Rp1.000.',
            ...$overrides,
        ];
    }

    public function test_the_page_loads_with_the_stored_values(): void
    {
        Livewire::test(ManageSiteSettings::class)
            ->assertSuccessful()
            ->assertSchemaStateSet([
                'brand_name' => config('dimdum.name'),
                'tagline' => config('dimdum.tagline'),
            ]);
    }

    public function test_it_saves_brand_identity_and_shows_it_on_the_homepage(): void
    {
        Livewire::test(ManageSiteSettings::class)
            ->fillForm($this->validPayload([
                'brand_name' => 'DIMDUM',
                'tagline' => 'Tagline baru dari admin panel.',
                'positioning' => 'Positioning baru dari admin panel.',
            ]))
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Tagline baru dari admin panel.', SiteSetting::query()->first()->tagline);

        $this->get('/')
            ->assertOk()
            ->assertSee('Tagline baru dari admin panel.', false)
            ->assertSee('Positioning baru dari admin panel.', false);
    }

    public function test_it_records_who_saved_the_change(): void
    {
        $userId = auth()->id();

        Livewire::test(ManageSiteSettings::class)
            ->fillForm($this->validPayload())
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($userId, SiteSetting::query()->first()->updated_by);
    }

    // ------------------------------------------------------- nomor WhatsApp

    public function test_it_normalizes_the_whatsapp_number(): void
    {
        Livewire::test(ManageSiteSettings::class)
            ->fillForm($this->validPayload(['whatsapp_number' => '  0812-3456-7890 ']))
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('6281234567890', SiteSetting::query()->first()->whatsapp_number);

        $this->get('/')->assertSee('https://wa.me/6281234567890', false);
    }

    public function test_it_rejects_an_implausible_whatsapp_number(): void
    {
        Livewire::test(ManageSiteSettings::class)
            ->fillForm($this->validPayload(['whatsapp_number' => 'bukan-nomor']))
            ->call('save')
            ->assertHasFormErrors(['whatsapp_number']);
    }

    public function test_an_empty_whatsapp_number_is_stored_as_null_and_renders_no_link(): void
    {
        Livewire::test(ManageSiteSettings::class)
            ->fillForm($this->validPayload(['whatsapp_number' => '']))
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull(SiteSetting::query()->first()->whatsapp_number);

        $content = $this->get('/')->getContent();

        $this->assertStringNotContainsString('wa.me', $content);
        $this->assertStringNotContainsString('href="#"', $content);
    }

    // ------------------------------------------------------------ social URL

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeSocialUrlProvider(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'data uri' => ['data:text/html,<script>alert(1)</script>'],
            'vbscript' => ['vbscript:msgbox(1)'],
            'file' => ['file:///etc/passwd'],
            'protocol relative' => ['//evil.example.com'],
            'plain http' => ['http://evil.example.com'],
            'internal path' => ['/evil'],
        ];
    }

    #[DataProvider('unsafeSocialUrlProvider')]
    public function test_it_rejects_unsafe_social_urls(string $url): void
    {
        Livewire::test(ManageSiteSettings::class)
            ->fillForm($this->validPayload(['instagram_url' => $url]))
            ->call('save')
            ->assertHasFormErrors(['instagram_url']);

        $this->assertNull(SiteSetting::query()->first()->instagram_url);
    }

    public function test_it_accepts_https_social_urls_and_renders_them(): void
    {
        Livewire::test(ManageSiteSettings::class)
            ->fillForm($this->validPayload([
                'instagram_url' => 'https://www.instagram.com/dimdum.id',
                'tiktok_url' => 'https://www.tiktok.com/@dimdum.id',
                'facebook_url' => 'https://www.facebook.com/dimdum.id',
            ]))
            ->call('save')
            ->assertHasNoFormErrors();

        $this->get('/')
            ->assertSee('https://www.instagram.com/dimdum.id', false)
            ->assertSee('https://www.tiktok.com/@dimdum.id', false)
            ->assertSee('rel="noopener noreferrer"', false);
    }

    public function test_a_dangerous_url_written_straight_into_the_database_is_never_rendered(): void
    {
        // Melewati form sepenuhnya: nilai berbahaya ditulis langsung ke baris.
        SiteSetting::query()->first()->update([
            'instagram_url' => 'javascript:alert(1)',
            'tiktok_url' => '//evil.example.com',
        ]);

        $content = $this->get('/')->getContent();

        $this->assertStringNotContainsString('javascript:', $content);
        $this->assertStringNotContainsString('//evil.example.com', $content);
    }

    // ------------------------------------------------------------------ XSS

    public function test_script_in_a_text_field_is_escaped_not_executed(): void
    {
        $payload = '<script>alert("xss")</script>';

        Livewire::test(ManageSiteSettings::class)
            ->fillForm($this->validPayload([
                'tagline' => $payload,
                'positioning' => 'Aman '.$payload,
            ]))
            ->call('save')
            ->assertHasNoFormErrors();

        $content = $this->get('/')->getContent();

        $this->assertStringNotContainsString('<script>alert("xss")</script>', $content);
        $this->assertStringContainsString('&lt;script&gt;', $content);
    }

    public function test_script_in_meta_fields_cannot_break_out_of_the_attribute(): void
    {
        Livewire::test(ManageSiteSettings::class)
            ->fillForm($this->validPayload([
                'default_meta_title' => 'Judul "><script>alert(1)</script>',
                'default_meta_description' => 'Deskripsi "><img src=x onerror=alert(1)>',
            ]))
            ->call('save')
            ->assertHasNoFormErrors();

        $content = $this->get('/')->getContent();

        // Tidak ada tag yang benar-benar terbentuk, dan kutip penutup atribut
        // tidak bisa ditembus -- seluruh karakter berbahaya sudah di-escape.
        $this->assertStringNotContainsString('<script>alert(1)</script>', $content);
        $this->assertStringNotContainsString('"><script', $content);
        $this->assertStringNotContainsString('"><img', $content);
        $this->assertStringNotContainsString('<img src=x', $content);

        $this->assertStringContainsString('&quot;&gt;&lt;script&gt;', $content);
        $this->assertStringContainsString('&quot;&gt;&lt;img src=x', $content);
    }

    // ----------------------------------------------------------- validation

    public function test_required_fields_are_enforced(): void
    {
        Livewire::test(ManageSiteSettings::class)
            ->fillForm([
                'brand_name' => '',
                'tagline' => '',
                'positioning' => '',
                'default_meta_title' => '',
                'default_meta_description' => '',
            ])
            ->call('save')
            ->assertHasFormErrors([
                'brand_name',
                'tagline',
                'positioning',
                'default_meta_title',
                'default_meta_description',
            ]);
    }

    public function test_a_failed_save_leaves_the_stored_row_untouched(): void
    {
        $before = SiteSetting::query()->first()->only(['brand_name', 'tagline', 'positioning']);

        Livewire::test(ManageSiteSettings::class)
            ->fillForm($this->validPayload(['instagram_url' => 'javascript:alert(1)']))
            ->call('save')
            ->assertHasFormErrors(['instagram_url']);

        $this->assertSame($before, SiteSetting::query()->first()->only(['brand_name', 'tagline', 'positioning']));
    }

    public function test_the_page_never_offers_raw_html_or_tracking_inputs(): void
    {
        $content = Livewire::test(ManageSiteSettings::class)->html();

        foreach (['custom_head', 'tracking', 'pixel', 'gtm', 'analytics', 'custom_script'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase(
                $forbidden,
                $content,
                "Halaman pengaturan tidak boleh punya input {$forbidden}."
            );
        }
    }
}
