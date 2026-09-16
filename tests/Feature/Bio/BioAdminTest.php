<?php

namespace Tests\Feature\Bio;

use App\Enums\BioButton;
use App\Enums\BioLocationMode;
use App\Enums\PanelPermission;
use App\Enums\ProductCategory;
use App\Enums\ProductType;
use App\Enums\SocialIcon;
use App\Enums\UserRole;
use App\Filament\Pages\ManageBioSettings;
use App\Filament\Resources\LocationPages\Pages\EditLocationPage;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\SocialLinks\Pages\CreateSocialLink;
use App\Filament\Resources\SocialLinks\Pages\EditSocialLink;
use App\Filament\Resources\SocialLinks\Pages\ListSocialLinks;
use App\Models\BioSetting;
use App\Models\LocationPage;
use App\Models\Product;
use App\Models\SocialLink;
use App\Models\User;
use App\Services\BioCatalogService;
use App\Services\BioSettingsService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Bio\Concerns\BuildsBio;
use Tests\TestCase;

/**
 * Panel admin modul Bio: Pengaturan Bio, Social Media, dan saklar Bio pada
 * Produk serta Halaman Slug Lokasi.
 */
class BioAdminTest extends TestCase
{
    use BuildsBio;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function actingAsRole(UserRole $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role->value);

        $this->actingAs($user);

        return $user->fresh();
    }

    // -------------------------------------------------------- authorization

    public function test_super_admin_and_admin_reach_both_screens(): void
    {
        foreach ([UserRole::SuperAdmin, UserRole::Admin] as $role) {
            $link = SocialLink::factory()->create();
            $this->actingAsRole($role);

            $this->get('/admin/pengaturan-bio')->assertOk();
            $this->get('/admin/social-media')->assertOk();
            $this->get('/admin/social-media/create')->assertOk();
            $this->get("/admin/social-media/{$link->getKey()}/edit")->assertOk();
        }
    }

    /**
     * Menyembunyikan menu saja tidak pernah dianggap proteksi: URL langsung
     * pun ditolak.
     */
    public function test_an_operator_is_refused_on_every_direct_url(): void
    {
        $link = SocialLink::factory()->create();
        $operator = $this->actingAsRole(UserRole::Operator);

        $this->get('/admin/pengaturan-bio')->assertForbidden();
        $this->get('/admin/social-media')->assertForbidden();
        $this->get('/admin/social-media/create')->assertForbidden();
        $this->get("/admin/social-media/{$link->getKey()}/edit")->assertForbidden();

        $this->assertFalse($operator->can(PanelPermission::ManageBioSettings->value));
        $this->assertFalse($operator->can(PanelPermission::ManageSocialLinks->value));
        $this->assertFalse($operator->can('delete', $link));
    }

    public function test_an_inactive_account_is_refused_even_as_super_admin(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $user->assignRole(UserRole::SuperAdmin->value);
        $this->actingAs($user->fresh());

        $this->get('/admin/pengaturan-bio')->assertForbidden();
        $this->get('/admin/social-media')->assertForbidden();
    }

    /**
     * save() punya penjaganya sendiri, tidak hanya bergantung pada halaman
     * yang sudah terbuka.
     *
     * (Filament juga menolak setiap request Livewire berikutnya begitu izin
     * dicabut; penjaga di save() adalah lapis kedua bila jalur itu berubah.)
     */
    public function test_saving_rechecks_the_permission(): void
    {
        $this->actingAsRole(UserRole::Operator);

        try {
            (new ManageBioSettings)->save();
            $this->fail('save() seharusnya ditolak untuk user tanpa izin.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertSame(0, BioSetting::query()->count(), 'Tidak ada yang boleh tertulis.');
    }

    // ------------------------------------------------------ pengaturan Bio

    public function test_opening_the_page_creates_one_inactive_row(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        Livewire::test(ManageBioSettings::class)->assertSuccessful();
        Livewire::test(ManageBioSettings::class)->assertSuccessful();

        $this->assertSame(1, BioSetting::query()->count(), 'Singleton tidak boleh berlipat.');
        $this->assertFalse(BioSetting::query()->first()->is_active);
    }

    public function test_the_settings_can_be_saved(): void
    {
        $user = $this->actingAsRole(UserRole::SuperAdmin);

        Livewire::test(ManageBioSettings::class)
            ->fillForm([
                'is_active' => true,
                'title' => 'Bio DIMDUM',
                'description' => 'Tautan resmi DIMDUM.',
                'location_mode' => BioLocationMode::LocationPages->value,
                'whatsapp_number' => '0812-3456-7890',
                'whatsapp_message' => 'Halo DIMDUM',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $record = BioSetting::query()->firstOrFail();

        $this->assertTrue($record->is_active);
        $this->assertSame('Bio DIMDUM', $record->title);
        $this->assertSame(BioLocationMode::LocationPages, $record->locationMode());
        // Tersimpan dalam bentuk ternormalisasi.
        $this->assertSame('6281234567890', $record->whatsapp_number);
        $this->assertSame($user->getKey(), $record->updated_by);

        $this->get(route('bio.home'))->assertOk()->assertSee('Bio DIMDUM', false);
    }

    /**
     * Normalisasi dan validasi nomor seluler Indonesia, server-side.
     */
    public function test_whatsapp_numbers_are_normalised_or_rejected(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        foreach ([
            '081234567890' => '6281234567890',
            '+62 812-3456-7890' => '6281234567890',
            '62 0812 3456 7890' => '6281234567890',
            '812 3456 789' => '628123456789',
        ] as $input => $expected) {
            Livewire::test(ManageBioSettings::class)
                ->fillForm(['whatsapp_number' => $input])
                ->call('save')
                ->assertHasNoFormErrors();

            $this->assertSame($expected, BioSetting::query()->value('whatsapp_number'), "Input {$input}");
        }

        foreach (['021 1234 5678', '+1 555 123 4567', '0812', 'abcd'] as $invalid) {
            Livewire::test(ManageBioSettings::class)
                ->fillForm(['whatsapp_number' => $invalid])
                ->call('save')
                ->assertHasFormErrors(['whatsapp_number']);
        }
    }

    public function test_an_empty_number_is_stored_as_null_and_warns_the_admin(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        Livewire::test(ManageBioSettings::class)
            ->fillForm(['whatsapp_number' => ''])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Tombol WhatsApp belum tampil');

        $this->assertNull(BioSetting::query()->value('whatsapp_number'));
    }

    public function test_a_location_mode_outside_the_enum_is_rejected(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        Livewire::test(ManageBioSettings::class)
            ->fillForm(['location_mode' => 'mode_karangan'])
            ->call('save')
            ->assertHasFormErrors(['location_mode']);
    }

    /**
     * Urutan tombol diatur lewat Repeater yang bisa diseret -- tanpa angka
     * urutan. Payload yang disusun ulang tersimpan apa adanya.
     */
    public function test_the_button_order_and_labels_are_saved(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        Livewire::test(ManageBioSettings::class)
            ->fillForm(['buttons' => [
                ['key' => 'menu', 'label' => 'Menu Kami', 'visible' => true],
                ['key' => 'whatsapp', 'label' => '', 'visible' => false],
                ['key' => 'location', 'label' => 'Cari Gerobak', 'visible' => true],
            ]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([
            ['key' => 'menu', 'label' => 'Menu Kami', 'visible' => true],
            // Label kosong jatuh ke label bawaan.
            ['key' => 'whatsapp', 'label' => BioButton::WhatsApp->defaultLabel(), 'visible' => false],
            ['key' => 'location', 'label' => 'Cari Gerobak', 'visible' => true],
        ], BioSetting::query()->first()->buttons);
    }

    /**
     * Repeater tidak bisa ditambah atau dihapus di UI, tetapi payload yang
     * disisipkan langsung ke Livewire tetap dinormalisasi di server.
     */
    public function test_a_forged_button_payload_is_normalised_on_save(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        Livewire::test(ManageBioSettings::class)
            ->set('data.buttons', [
                ['key' => 'location', 'label' => 'Lokasi', 'visible' => true],
                ['key' => 'location', 'label' => 'Kembar', 'visible' => true],
                ['key' => 'jahat', 'label' => 'Klik', 'visible' => true],
            ])
            ->call('save');

        $buttons = BioSetting::query()->first()->buttons;

        $this->assertSame(['location', 'menu', 'whatsapp'], array_column($buttons, 'key'));
        $this->assertSame('Lokasi', $buttons[0]['label']);
        // Tombol yang hilang dari payload kembali, tetapi tersembunyi.
        $this->assertFalse($buttons[1]['visible']);
        $this->assertFalse($buttons[2]['visible']);
    }

    public function test_the_form_has_no_url_field_for_the_buttons(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $html = $this->get('/admin/pengaturan-bio')->assertOk()->getContent();

        $this->assertStringNotContainsString('buttons.0.url', $html);
        $this->assertStringNotContainsString('location_url', $html);
    }

    public function test_saving_the_settings_raises_the_bio_cache_version(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);
        $component = Livewire::test(ManageBioSettings::class);

        $before = BioCatalogService::cacheVersion();

        $component->fillForm(['title' => 'Judul Baru'])->call('save');

        $this->assertGreaterThan($before, BioCatalogService::cacheVersion());
    }

    // --------------------------------------------------------- social media

    public function test_a_social_link_can_be_created_and_is_appended_last(): void
    {
        $user = $this->actingAsRole(UserRole::Admin);

        SocialLink::factory()->create(['sort_order' => 1]);

        Livewire::test(CreateSocialLink::class)
            ->fillForm([
                'name' => 'Instagram',
                'url' => 'https://www.instagram.test/dimdum',
                'icon' => SocialIcon::Instagram->value,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $link = SocialLink::query()->where('name', 'Instagram')->firstOrFail();

        $this->assertSame(2, $link->sort_order);
        $this->assertSame($user->getKey(), $link->created_by);
    }

    public function test_the_form_asks_for_no_ordering_number(): void
    {
        $this->actingAsRole(UserRole::Admin);

        Livewire::test(CreateSocialLink::class)->assertFormFieldDoesNotExist('sort_order');
    }

    public function test_plain_http_is_accepted(): void
    {
        $this->actingAsRole(UserRole::Admin);

        Livewire::test(CreateSocialLink::class)
            ->fillForm(['name' => 'Toko', 'url' => 'http://toko-lama.test', 'icon' => SocialIcon::Website->value])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    /**
     * Skema berbahaya, userinfo, backslash, karakter kontrol, dan skema
     * non-web ditolak di server.
     */
    public function test_unsafe_urls_are_rejected(): void
    {
        $this->actingAsRole(UserRole::Admin);

        foreach ([
            'javascript:alert(1)',
            'JaVaScRiPt:alert(1)',
            'data:text/html;base64,PHNjcmlwdD4=',
            'vbscript:msgbox(1)',
            'https://tampak-benar.test@jahat.test',
            'https://user:rahasia@jahat.test',
            'https://baik.test\\@jahat.test',
            "https://baik.test/\x00jahat",
            "java\tscript:alert(1)",
            'ftp://file.test/menu',
            '//jahat.test',
            '/halaman-internal',
            'bukan url',
        ] as $url) {
            Livewire::test(CreateSocialLink::class)
                ->fillForm(['name' => 'Uji', 'url' => $url, 'icon' => SocialIcon::Link->value])
                ->call('create')
                ->assertHasFormErrors(['url']);
        }

        $this->assertSame(0, SocialLink::query()->count());
    }

    public function test_an_icon_outside_the_allowlist_is_rejected(): void
    {
        $this->actingAsRole(UserRole::Admin);

        Livewire::test(CreateSocialLink::class)
            ->fillForm(['name' => 'Uji', 'url' => 'https://uji.test', 'icon' => '<svg onload=alert(1)>'])
            ->call('create')
            ->assertHasFormErrors(['icon']);
    }

    public function test_a_social_link_can_be_edited_and_deactivated(): void
    {
        $this->actingAsRole(UserRole::Admin);

        $link = SocialLink::factory()->create(['name' => 'Lama']);

        Livewire::test(EditSocialLink::class, ['record' => $link->getKey()])
            ->fillForm(['name' => 'Baru', 'is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $link->refresh();

        $this->assertSame('Baru', $link->name);
        $this->assertFalse($link->is_active);
    }

    public function test_a_social_link_can_be_deleted(): void
    {
        $this->actingAsRole(UserRole::Admin);

        $link = SocialLink::factory()->create();

        Livewire::test(EditSocialLink::class, ['record' => $link->getKey()])->callAction('delete');

        $this->assertSame(0, SocialLink::query()->count());
    }

    public function test_dragging_rows_reorders_and_raises_the_cache_version(): void
    {
        $this->actingAsRole(UserRole::Admin);

        $a = SocialLink::factory()->create(['name' => 'A', 'sort_order' => 1]);
        $b = SocialLink::factory()->create(['name' => 'B', 'sort_order' => 2]);
        $c = SocialLink::factory()->create(['name' => 'C', 'sort_order' => 3]);

        $before = BioCatalogService::cacheVersion();

        Livewire::test(ListSocialLinks::class)
            ->call('reorderTable', [(string) $c->getKey(), (string) $a->getKey(), (string) $b->getKey()]);

        $this->assertSame(['C', 'A', 'B'], SocialLink::query()->ordered()->pluck('name')->all());
        $this->assertGreaterThan($before, BioCatalogService::cacheVersion());
    }

    public function test_a_user_without_the_permission_cannot_reorder(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(PanelPermission::AccessAdminPanel->value);
        $this->actingAs($user->fresh());

        SocialLink::factory()->create(['name' => 'A', 'sort_order' => 1]);
        SocialLink::factory()->create(['name' => 'B', 'sort_order' => 2]);

        $this->get('/admin/social-media')->assertForbidden();
        $this->assertSame(['A', 'B'], SocialLink::query()->ordered()->pluck('name')->all());
    }

    public function test_every_social_link_change_raises_the_cache_version(): void
    {
        $link = SocialLink::factory()->create();

        foreach ([
            fn () => $link->update(['name' => 'Berubah']),
            fn () => $link->update(['is_active' => false]),
            fn () => $link->delete(),
        ] as $change) {
            $before = BioCatalogService::cacheVersion();
            $change();
            $this->assertGreaterThan($before, BioCatalogService::cacheVersion());
        }
    }

    public function test_a_social_link_change_appears_on_the_bio_immediately(): void
    {
        $this->bio();
        $link = SocialLink::factory()->create(['name' => 'Nama Lama']);

        $this->get(route('bio.home'))->assertOk()->assertSee('Nama Lama', false);

        $link->update(['name' => 'Nama Baru']);
        $this->get(route('bio.home'))->assertOk()->assertSee('Nama Baru', false)->assertDontSee('Nama Lama', false);

        $link->update(['is_active' => false]);
        $this->get(route('bio.home'))->assertOk()->assertDontSee('Nama Baru', false);
    }

    // ------------------------------------------------- saklar Bio terpisah

    public function test_the_product_form_has_a_separate_bio_toggle(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        Livewire::test(CreateProduct::class)
            ->assertFormFieldExists('show_on_homepage')
            ->assertFormFieldExists('show_on_bio')
            ->fillForm([
                'name' => 'Produk Bio Saja',
                'category' => ProductCategory::Menu->value,
                'type' => ProductType::Satuan->value,
                'show_on_homepage' => false,
                'show_on_bio' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::query()->where('name', 'Produk Bio Saja')->firstOrFail();

        $this->assertTrue($product->show_on_bio);
        $this->assertFalse($product->show_on_homepage);
    }

    public function test_new_products_and_pages_default_to_off(): void
    {
        $this->assertFalse(Product::factory()->create()->fresh()->show_on_bio);
        $this->assertFalse(LocationPage::factory()->create()->fresh()->show_on_bio);
    }

    public function test_the_location_page_form_has_a_separate_bio_toggle(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        // Form Edit mewajibkan cakupan Kota/Grup, jadi halamannya lengkap.
        $chain = $this->chain();
        $page = LocationPage::factory()->create(['is_featured' => true, 'show_on_bio' => false]);
        $page->groups()->attach($chain['group']);
        $page->locations()->attach($chain['location']);

        Livewire::test(EditLocationPage::class, ['record' => $page->getKey()])
            ->assertFormFieldExists('is_featured')
            ->assertFormFieldExists('show_on_bio')
            ->fillForm(['show_on_bio' => true, 'is_featured' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $page->refresh();

        $this->assertTrue($page->show_on_bio);
        $this->assertFalse($page->is_featured);
    }

    // ---------------------------------------------------------- service

    public function test_button_normalisation_restores_missing_buttons_hidden(): void
    {
        $this->assertSame(
            BioSettingsService::defaultButtons(),
            BioSettingsService::normalizeButtons(null),
            'Belum pernah disimpan: seluruh tombol bawaan tampil.',
        );

        $normalized = BioSettingsService::normalizeButtons([['key' => 'menu', 'label' => 'Menu', 'visible' => true]]);

        $this->assertSame(['menu', 'location', 'whatsapp'], array_column($normalized, 'key'));
        $this->assertSame([true, false, false], array_column($normalized, 'visible'));
    }
}
