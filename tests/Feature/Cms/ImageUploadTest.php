<?php

namespace Tests\Feature\Cms;

use App\Enums\UserRole;
use App\Filament\Pages\ManageHomepage;
use App\Filament\Pages\ManageSiteSettings;
use App\Filament\Support\UploadedImage;
use App\Models\HomepageSetting;
use App\Models\SiteSetting;
use App\Models\User;
use Database\Seeders\HomepageContentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ImageUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

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
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function homepageState(array $overrides = []): array
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

    /**
     * State hero dengan gambar.
     *
     * FileUpload Filament selalu menyimpan state sebagai ARRAY berisi file
     * atau path -- bentuk yang sama dengan yang dikirim browser. Melewatkan
     * satu nilai telanjang akan diabaikan diam-diam oleh komponennya.
     */
    protected function heroStateWith(UploadedFile|string|null $file, string $alt = 'Foto gerobak DIMDUM'): array
    {
        $hero = $this->setting()->hero;
        $hero['image'] = ['path' => $file === null ? [] : [$file], 'alt' => $alt];

        return $this->homepageState(['hero' => $hero]);
    }

    // ------------------------------------------------------- format diterima

    public function test_it_accepts_jpg_png_and_webp_hero_images(): void
    {
        foreach ([
            ['foto.jpg', 'image/jpeg', 'jpg'],
            ['foto.png', 'image/png', 'png'],
            ['foto.webp', 'image/webp', 'webp'],
        ] as [$name, $mime, $extension]) {
            $file = UploadedFile::fake()->image($name, 1200, 800)->mimeType($mime);

            Livewire::test(ManageHomepage::class)
                ->fillForm($this->heroStateWith($file))
                ->call('save')
                ->assertHasNoFormErrors();

            $path = $this->setting()->hero['image']['path'];

            $this->assertNotNull($path, "Upload {$name} seharusnya berhasil.");
            $this->assertSame($extension, pathinfo($path, PATHINFO_EXTENSION));
            Storage::disk('public')->assertExists($path);
        }
    }

    public function test_it_rejects_an_svg_upload(): void
    {
        $svg = UploadedFile::fake()->createWithContent(
            'jahat.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>',
        );

        Livewire::test(ManageHomepage::class)
            ->fillForm($this->heroStateWith($svg))
            ->call('save')
            ->assertHasFormErrors(['hero.image.path']);

        $this->assertNull($this->setting()->hero['image']['path'] ?? null);
    }

    public function test_it_rejects_a_non_image_file(): void
    {
        $php = UploadedFile::fake()->createWithContent('shell.php', '<?php echo "pwned";');

        Livewire::test(ManageHomepage::class)
            ->fillForm($this->heroStateWith($php))
            ->call('save')
            ->assertHasFormErrors(['hero.image.path']);

        $this->assertNull($this->setting()->hero['image']['path'] ?? null);
    }

    public function test_it_rejects_a_file_larger_than_three_megabytes(): void
    {
        // 4 MB, di atas batas 3 MB.
        $big = UploadedFile::fake()->image('besar.jpg', 4000, 3000)->size(4096);

        Livewire::test(ManageHomepage::class)
            ->fillForm($this->heroStateWith($big))
            ->call('save')
            ->assertHasFormErrors(['hero.image.path']);

        $this->assertNull($this->setting()->hero['image']['path'] ?? null);
    }

    // ------------------------------------------------------------------ path

    public function test_it_stores_a_relative_path_not_a_url_or_filesystem_path(): void
    {
        Livewire::test(ManageHomepage::class)
            ->fillForm($this->heroStateWith(UploadedFile::fake()->image('foto.jpg', 1200, 800)))
            ->call('save')
            ->assertHasNoFormErrors();

        $path = $this->setting()->hero['image']['path'];

        $this->assertStringStartsWith(UploadedImage::HERO_DIRECTORY.'/', $path);
        $this->assertStringNotContainsString('http', $path);
        $this->assertStringNotContainsString('://', $path);
        $this->assertStringNotContainsString(base_path(), $path);
        $this->assertStringNotContainsString('\\', $path);
        $this->assertStringNotContainsString('storage/app', $path);
    }

    public function test_the_original_filename_is_never_used_as_the_stored_name(): void
    {
        $file = UploadedFile::fake()
            ->image('../../evil name<>.jpg', 1200, 800)
            ->mimeType('image/jpeg');

        Livewire::test(ManageHomepage::class)
            ->fillForm($this->heroStateWith($file))
            ->call('save')
            ->assertHasNoFormErrors();

        $path = $this->setting()->hero['image']['path'];
        $basename = basename($path);

        $this->assertStringNotContainsString('evil', $basename);
        $this->assertStringNotContainsString('..', $path);
        $this->assertMatchesRegularExpression('/^[0-9A-Za-z]{26}\.jpg$/', $basename);
    }

    public function test_the_stored_extension_comes_from_the_detected_mime_type(): void
    {
        // Nama file berbohong (.png) tetapi isinya JPEG.
        $file = UploadedFile::fake()->image('menipu.png', 800, 600)->mimeType('image/jpeg');

        Livewire::test(ManageHomepage::class)
            ->fillForm($this->heroStateWith($file))
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(
            'jpg',
            pathinfo($this->setting()->hero['image']['path'], PATHINFO_EXTENSION),
            'Ekstensi harus mengikuti MIME hasil deteksi, bukan nama kiriman.'
        );
    }

    public function test_uploaded_names_never_collide(): void
    {
        $names = [];

        foreach (range(1, 3) as $index) {
            Livewire::test(ManageHomepage::class)
                ->fillForm($this->heroStateWith(UploadedFile::fake()->image('sama.jpg', 800, 600)))
                ->call('save')
                ->assertHasNoFormErrors();

            $names[] = $this->setting()->hero['image']['path'];
        }

        $this->assertSame(3, count(array_unique($names)), 'Setiap upload harus punya nama unik.');
    }

    // --------------------------------------------------------- render publik

    public function test_the_uploaded_hero_image_is_rendered_with_real_dimensions(): void
    {
        Livewire::test(ManageHomepage::class)
            ->fillForm($this->heroStateWith(
                UploadedFile::fake()->image('hero.jpg', 1280, 720),
                'Gerobak DIMDUM di pinggir jalan',
            ))
            ->call('save')
            ->assertHasNoFormErrors();

        $path = $this->setting()->hero['image']['path'];

        $this->get('/')
            ->assertOk()
            ->assertSee('storage/'.$path, false)
            ->assertSee('width="1280"', false)
            ->assertSee('height="720"', false)
            ->assertSee('alt="Gerobak DIMDUM di pinggir jalan"', false);
    }

    public function test_the_homepage_falls_back_when_no_hero_image_is_set(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('dimdum-logo-primary', false)
            ->assertDontSee(UploadedImage::HERO_DIRECTORY, false);
    }

    public function test_a_missing_file_referenced_by_the_database_is_not_rendered(): void
    {
        $setting = $this->setting();
        $hero = $setting->hero;
        $hero['image'] = ['path' => 'homepage/hero/tidak-ada.jpg', 'alt' => 'Hilang'];
        $setting->update(['hero' => $hero]);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('tidak-ada.jpg', false)
            // Fallback lockup logo yang tampil, bukan gambar 404.
            ->assertSee('dimdum-logo-primary', false);
    }

    public function test_alt_text_is_required_when_a_hero_image_is_uploaded(): void
    {
        Livewire::test(ManageHomepage::class)
            ->fillForm($this->heroStateWith(
                UploadedFile::fake()->image('hero.jpg', 800, 600),
                alt: '',
            ))
            ->call('save')
            ->assertHasFormErrors(['hero.image.alt']);
    }

    // ------------------------------------------------------ pembersihan file

    public function test_the_previous_file_is_removed_after_a_successful_replacement(): void
    {
        Livewire::test(ManageHomepage::class)
            ->fillForm($this->heroStateWith(UploadedFile::fake()->image('pertama.jpg', 800, 600)))
            ->call('save')
            ->assertHasNoFormErrors();

        $first = $this->setting()->hero['image']['path'];
        Storage::disk('public')->assertExists($first);

        Livewire::test(ManageHomepage::class)
            ->fillForm($this->heroStateWith(UploadedFile::fake()->image('kedua.jpg', 800, 600)))
            ->call('save')
            ->assertHasNoFormErrors();

        $second = $this->setting()->hero['image']['path'];

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertExists($second);
        Storage::disk('public')->assertMissing($first);
    }

    public function test_the_previous_file_survives_a_failed_save(): void
    {
        Livewire::test(ManageHomepage::class)
            ->fillForm($this->heroStateWith(UploadedFile::fake()->image('pertama.jpg', 800, 600)))
            ->call('save')
            ->assertHasNoFormErrors();

        $original = $this->setting()->hero['image']['path'];

        // Penyimpanan ditolak karena CTA tidak aman, gambarnya tidak diubah.
        $state = $this->heroStateWith($original);
        $state['hero']['primary_cta'] = ['label' => 'Klik', 'href' => 'javascript:alert(1)'];

        Livewire::test(ManageHomepage::class)
            ->fillForm($state)
            ->call('save')
            ->assertHasFormErrors(['hero.primary_cta.href']);

        Storage::disk('public')->assertExists($original);
        $this->assertSame($original, $this->setting()->hero['image']['path']);
    }

    public function test_saving_without_changing_the_image_keeps_the_file(): void
    {
        Livewire::test(ManageHomepage::class)
            ->fillForm($this->heroStateWith(UploadedFile::fake()->image('pertama.jpg', 800, 600)))
            ->call('save')
            ->assertHasNoFormErrors();

        $path = $this->setting()->hero['image']['path'];

        Livewire::test(ManageHomepage::class)
            ->fillForm($this->heroStateWith($path))
            ->call('save')
            ->assertHasNoFormErrors();

        Storage::disk('public')->assertExists($path);
        $this->assertSame($path, $this->setting()->hero['image']['path']);
    }

    public function test_fallback_brand_assets_are_never_deleted(): void
    {
        // Path di luar direktori yang dikelola panel tidak boleh disentuh.
        foreach ([
            'images/brand/dimdum-logo-primary.png',
            'favicon.ico',
            '../../public/images/brand/dimdum-og.jpg',
            'homepage/../../images/brand/dimdum-icon.png',
        ] as $path) {
            $this->assertFalse(
                UploadedImage::isManagedPath($path),
                "Path di luar kelolaan panel tidak boleh dianggap bisa dihapus: {$path}"
            );
        }

        // Aset fallback tetap ada di disk publik project setelah semua test.
        $this->assertFileExists(public_path('images/brand/dimdum-logo-primary.png'));
        $this->assertFileExists(public_path('images/brand/dimdum-og.jpg'));
    }

    // -------------------------------------------------------------- OG image

    public function test_it_uploads_and_renders_the_og_image(): void
    {
        Livewire::test(ManageSiteSettings::class)
            ->fillForm([
                'brand_name' => 'DIMDUM',
                'tagline' => config('dimdum.tagline'),
                'positioning' => config('dimdum.positioning'),
                'default_meta_title' => config('dimdum.seo.title'),
                'default_meta_description' => config('dimdum.seo.description'),
                'default_og_image_path' => [UploadedFile::fake()->image('og.jpg', 1200, 630)],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $path = SiteSetting::query()->first()->default_og_image_path;

        $this->assertNotNull($path);
        $this->assertStringStartsWith(UploadedImage::OG_DIRECTORY.'/', $path);
        Storage::disk('public')->assertExists($path);

        $this->get('/')
            ->assertOk()
            ->assertSee('storage/'.$path, false)
            ->assertSee('<meta property="og:image:width" content="1200">', false)
            ->assertSee('<meta property="og:image:height" content="630">', false)
            ->assertSee('<meta property="og:image:type" content="image/jpeg">', false);
    }

    public function test_the_og_image_falls_back_to_the_brand_asset_when_unset(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('images/brand/dimdum-og.jpg', false);
    }

    public function test_the_previous_og_image_is_removed_after_replacement(): void
    {
        $payload = [
            'brand_name' => 'DIMDUM',
            'tagline' => config('dimdum.tagline'),
            'positioning' => config('dimdum.positioning'),
            'default_meta_title' => config('dimdum.seo.title'),
            'default_meta_description' => config('dimdum.seo.description'),
        ];

        Livewire::test(ManageSiteSettings::class)
            ->fillForm([...$payload, 'default_og_image_path' => [UploadedFile::fake()->image('og1.jpg', 1200, 630)]])
            ->call('save')
            ->assertHasNoFormErrors();

        $first = SiteSetting::query()->first()->default_og_image_path;

        Livewire::test(ManageSiteSettings::class)
            ->fillForm([...$payload, 'default_og_image_path' => [UploadedFile::fake()->image('og2.jpg', 1200, 630)]])
            ->call('save')
            ->assertHasNoFormErrors();

        $second = SiteSetting::query()->first()->default_og_image_path;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertExists($second);
        Storage::disk('public')->assertMissing($first);
    }

    // -------------------------------------------------- konfigurasi komponen

    public function test_the_upload_component_never_accepts_svg(): void
    {
        $types = UploadedImage::make('test', UploadedImage::HERO_DIRECTORY)->getAcceptedFileTypes();

        $this->assertNotContains('image/svg+xml', $types);
        $this->assertNotContains('image/svg', $types);
        $this->assertContains('image/jpeg', $types);
        $this->assertContains('image/png', $types);
        $this->assertContains('image/webp', $types);
    }

    public function test_the_upload_component_uses_the_public_disk_with_a_three_megabyte_limit(): void
    {
        $component = UploadedImage::make('test', UploadedImage::HERO_DIRECTORY);

        $this->assertSame('public', $component->getDiskName());
        $this->assertSame(UploadedImage::HERO_DIRECTORY, $component->getDirectory());
        $this->assertSame(3072, $component->getMaxSize());
        $this->assertSame('public', $component->getVisibility());
        $this->assertFalse($component->shouldPreserveFilenames());
    }

    public function test_private_storage_is_never_used_for_uploads(): void
    {
        foreach ([UploadedImage::HERO_DIRECTORY, UploadedImage::OG_DIRECTORY] as $directory) {
            $this->assertStringNotContainsString('private', $directory);
            $this->assertStringNotContainsString('..', $directory);
        }

        $this->assertSame('public', UploadedImage::DISK);
    }
}
