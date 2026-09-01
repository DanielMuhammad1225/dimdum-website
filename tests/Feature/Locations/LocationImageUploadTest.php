<?php

namespace Tests\Feature\Locations;

use App\Enums\UserRole;
use App\Filament\Resources\Locations\Pages\EditLocation;
use App\Filament\Resources\Locations\Pages\LocationMediaCleaner;
use App\Filament\Support\UploadedImage;
use App\Models\Location;
use App\Models\LocationArea;
use App\Models\LocationImage;
use App\Models\User;
use App\Services\ImageMetadata;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class LocationImageUploadTest extends TestCase
{
    use RefreshDatabase;

    protected LocationArea $area;

    protected Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(UserRole::SuperAdmin->value);
        $this->actingAs($user->fresh());

        $this->area = LocationArea::factory()->published()->create(['slug' => 'cianjur', 'name' => 'Cianjur']);
        $this->location = Location::factory()->for($this->area, 'area')->published()->create(['name' => 'Gerobak Uji']);
    }

    /**
     * State form lengkap dengan galeri.
     *
     * FileUpload Filament selalu menyimpan state sebagai ARRAY -- bentuk yang
     * sama dengan yang dikirim browser.
     *
     * @param  list<array<string, mixed>>  $images
     * @return array<string, mixed>
     */
    protected function formState(array $images): array
    {
        return [
            'location_area_id' => $this->area->id,
            'name' => $this->location->name,
            'full_address' => $this->location->full_address,
            'sort_order' => 0,
            'images' => $images,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function imageRow(mixed $file, string $alt = 'Foto gerobak DIMDUM di Cianjur'): array
    {
        return [
            'image_path' => $file === null ? [] : [$file],
            'alt_text' => $alt,
            'caption' => null,
            'is_cover' => false,
        ];
    }

    protected function save(array $images): Testable
    {
        return Livewire::test(EditLocation::class, ['record' => $this->location->getRouteKey()])
            ->fillForm($this->formState($images))
            ->call('save');
    }

    // -------------------------------------------------------- format diterima

    public function test_it_accepts_jpg_png_and_webp(): void
    {
        foreach ([
            ['foto.jpg', 'image/jpeg', 'jpg'],
            ['foto.png', 'image/png', 'png'],
            ['foto.webp', 'image/webp', 'webp'],
        ] as [$name, $mime, $extension]) {
            $file = UploadedFile::fake()->image($name, 1200, 800)->mimeType($mime);

            $this->save([$this->imageRow($file)])->assertHasNoFormErrors();

            $image = $this->location->images()->latest('id')->firstOrFail();

            $this->assertSame($extension, pathinfo($image->image_path, PATHINFO_EXTENSION));
            Storage::disk('public')->assertExists($image->image_path);

            $this->location->images()->forceDelete();
        }
    }

    public function test_it_rejects_svg(): void
    {
        $svg = UploadedFile::fake()->createWithContent(
            'jahat.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>',
        );

        $this->save([$this->imageRow($svg)])->assertHasFormErrors();

        $this->assertSame(0, $this->location->images()->count());
    }

    public function test_it_rejects_a_non_image_file(): void
    {
        $php = UploadedFile::fake()->createWithContent('shell.php', '<?php echo "pwned";');

        $this->save([$this->imageRow($php)])->assertHasFormErrors();

        $this->assertSame(0, $this->location->images()->count());
    }

    public function test_it_rejects_files_over_three_megabytes(): void
    {
        $big = UploadedFile::fake()->image('besar.jpg', 4000, 3000)->size(4096);

        $this->save([$this->imageRow($big)])->assertHasFormErrors();

        $this->assertSame(0, $this->location->images()->count());
    }

    public function test_alt_text_is_required(): void
    {
        $file = UploadedFile::fake()->image('foto.jpg', 800, 600);

        $this->save([$this->imageRow($file, alt: '')])->assertHasFormErrors();

        $this->assertSame(0, $this->location->images()->count());
    }

    // ---------------------------------------------------------------- path

    public function test_the_stored_path_is_relative_and_inside_the_module_directory(): void
    {
        $this->save([$this->imageRow(UploadedFile::fake()->image('foto.jpg', 1200, 800))])
            ->assertHasNoFormErrors();

        $path = $this->location->images()->firstOrFail()->image_path;

        $this->assertStringStartsWith(UploadedImage::LOCATION_ROOT_DIRECTORY.'/', $path);
        $this->assertStringNotContainsString('http', $path);
        $this->assertStringNotContainsString('://', $path);
        $this->assertStringNotContainsString('..', $path);
        $this->assertStringNotContainsString(base_path(), $path);
        $this->assertStringNotContainsString('storage/app', $path);
    }

    public function test_the_original_filename_is_never_reused(): void
    {
        $file = UploadedFile::fake()
            ->image('../../nama jahat<>.jpg', 1200, 800)
            ->mimeType('image/jpeg');

        $this->save([$this->imageRow($file)])->assertHasNoFormErrors();

        $path = $this->location->images()->firstOrFail()->image_path;

        $this->assertStringNotContainsString('jahat', $path);
        $this->assertMatchesRegularExpression('/^[0-9A-Za-z]{26}\.jpg$/', basename($path));
    }

    public function test_a_spoofed_mime_follows_the_real_file_content(): void
    {
        // Berkasnya benar-benar PNG, tetapi browser melaporkannya image/jpeg.
        $file = UploadedFile::fake()->image('menipu.png', 800, 600)->mimeType('image/jpeg');

        $this->save([$this->imageRow($file)])->assertHasNoFormErrors();

        $image = $this->location->images()->firstOrFail();

        // Isi berkas yang menentukan, bukan header kiriman -- dan ekstensi
        // serta kolom mime_type wajib sepakat.
        $this->assertSame('png', pathinfo($image->image_path, PATHINFO_EXTENSION));
        $this->assertSame('image/png', $image->mime_type);
    }

    public function test_the_extension_always_matches_the_stored_mime_type(): void
    {
        foreach ([
            ['a.jpg', 'image/jpeg', 'jpg'],
            ['b.png', 'image/png', 'png'],
            ['c.webp', 'image/webp', 'webp'],
        ] as [$name, $mime, $extension]) {
            $this->save([$this->imageRow(
                UploadedFile::fake()->image($name, 640, 480)->mimeType($mime),
            )])->assertHasNoFormErrors();

            $image = $this->location->images()->latest('id')->firstOrFail();

            $this->assertSame($extension, pathinfo($image->image_path, PATHINFO_EXTENSION));
            $this->assertSame($mime, $image->mime_type);

            $this->location->images()->forceDelete();
        }
    }

    // ------------------------------------------------------------ metadata

    public function test_real_dimensions_and_size_are_stored(): void
    {
        $this->save([$this->imageRow(UploadedFile::fake()->image('foto.jpg', 1280, 720))])
            ->assertHasNoFormErrors();

        $image = $this->location->images()->firstOrFail();

        $this->assertSame(1280, $image->width);
        $this->assertSame(720, $image->height);
        $this->assertSame('image/jpeg', $image->mime_type);
        $this->assertGreaterThan(0, $image->size_bytes);
    }

    public function test_uploaded_photos_render_with_explicit_dimensions(): void
    {
        $this->save([$this->imageRow(
            UploadedFile::fake()->image('foto.jpg', 1280, 720),
            'Gerobak DIMDUM di depan pasar',
        )])->assertHasNoFormErrors();

        $path = $this->location->images()->firstOrFail()->image_path;

        $content = $this->get('/lokasi/cianjur')->getContent();

        $this->assertStringContainsString('storage/'.$path, $content);
        $this->assertStringContainsString('width="1280"', $content);
        $this->assertStringContainsString('height="720"', $content);
        $this->assertStringContainsString('alt="Gerobak DIMDUM di depan pasar"', $content);
        // object-contain: bentuk gerobak tidak dipotong menyesatkan.
        $this->assertStringContainsString('object-contain', $content);
    }

    public function test_every_rendered_image_declares_width_and_height(): void
    {
        $this->save([$this->imageRow(UploadedFile::fake()->image('foto.jpg', 1280, 720))])
            ->assertHasNoFormErrors();

        $content = $this->get('/lokasi/cianjur')->getContent();

        preg_match_all('/<img\s[^>]*>/i', $content, $matches);

        $this->assertNotEmpty($matches[0]);

        foreach ($matches[0] as $tag) {
            $this->assertMatchesRegularExpression('/\swidth="\d+"/i', $tag, "Tanpa width: {$tag}");
            $this->assertMatchesRegularExpression('/\sheight="\d+"/i', $tag, "Tanpa height: {$tag}");
            $this->assertMatchesRegularExpression('/\salt="[^"]*"/i', $tag, "Tanpa alt: {$tag}");
        }
    }

    // -------------------------------------------------------------- cover

    public function test_only_one_cover_survives(): void
    {
        $first = UploadedFile::fake()->image('satu.jpg', 800, 600);
        $second = UploadedFile::fake()->image('dua.jpg', 800, 600);

        $rows = [
            [...$this->imageRow($first, 'Foto satu'), 'is_cover' => true],
            [...$this->imageRow($second, 'Foto dua'), 'is_cover' => true],
        ];

        $this->save($rows)->assertHasNoFormErrors();

        $this->assertSame(
            1,
            $this->location->images()->where('is_cover', true)->count(),
            'Hanya satu foto utama per gerobak.'
        );
    }

    public function test_the_first_photo_becomes_the_cover_when_none_is_marked(): void
    {
        $this->save([
            $this->imageRow(UploadedFile::fake()->image('satu.jpg', 800, 600), 'Foto satu'),
            $this->imageRow(UploadedFile::fake()->image('dua.jpg', 800, 600), 'Foto dua'),
        ])->assertHasNoFormErrors();

        $this->assertSame(1, $this->location->images()->where('is_cover', true)->count());
    }

    // ------------------------------------------------- siklus hidup berkas

    public function test_soft_deleting_a_location_keeps_its_files(): void
    {
        $this->save([$this->imageRow(UploadedFile::fake()->image('foto.jpg', 800, 600))])
            ->assertHasNoFormErrors();

        $path = $this->location->images()->firstOrFail()->image_path;

        $this->location->delete();

        Storage::disk('public')->assertExists($path);
    }

    public function test_restoring_a_location_brings_back_its_photos(): void
    {
        $this->save([$this->imageRow(UploadedFile::fake()->image('foto.jpg', 800, 600), 'Foto pulih')])
            ->assertHasNoFormErrors();

        $path = $this->location->images()->firstOrFail()->image_path;

        $this->location->delete();
        $this->location->restore();

        Storage::disk('public')->assertExists($path);

        $this->get('/lokasi/cianjur')
            ->assertOk()
            ->assertSee('storage/'.$path, false);
    }

    public function test_force_delete_cleans_up_managed_files(): void
    {
        $this->save([$this->imageRow(UploadedFile::fake()->image('foto.jpg', 800, 600))])
            ->assertHasNoFormErrors();

        $path = $this->location->images()->firstOrFail()->image_path;
        Storage::disk('public')->assertExists($path);

        LocationMediaCleaner::purge($this->location);

        Storage::disk('public')->assertMissing($path);
    }

    public function test_a_failed_save_leaves_existing_photos_untouched(): void
    {
        $this->save([$this->imageRow(UploadedFile::fake()->image('asli.jpg', 800, 600), 'Foto asli')])
            ->assertHasNoFormErrors();

        $path = $this->location->images()->firstOrFail()->image_path;

        // Penyimpanan berikutnya ditolak karena URL Maps tidak aman.
        Livewire::test(EditLocation::class, ['record' => $this->location->getRouteKey()])
            ->fillForm([
                ...$this->formState([$this->imageRow(null)]),
                'google_maps_url' => 'javascript:alert(1)',
            ])
            ->call('save')
            ->assertHasFormErrors(['google_maps_url']);

        Storage::disk('public')->assertExists($path);
        $this->assertSame(1, $this->location->images()->count());
    }

    // --------------------------------------------- perlindungan aset brand

    public function test_paths_outside_the_module_are_never_deletable(): void
    {
        foreach ([
            'images/brand/dimdum-logo-primary.png',
            'favicon.ico',
            'site/og/../../images/brand/dimdum-og.jpg',
            'homepage/hero/../../images/brand/dimdum-icon.png',
            '../public/images/brand/dimdum-og.jpg',
        ] as $path) {
            $this->assertFalse(
                UploadedImage::deleteManagedFile($path),
                "Path di luar kelolaan modul tidak boleh terhapus: {$path}"
            );
        }

        // Aset fallback brand tetap ada di disk publik project.
        $this->assertFileExists(public_path('images/brand/dimdum-logo-primary.png'));
        $this->assertFileExists(public_path('images/brand/dimdum-og.jpg'));
    }

    public function test_only_location_subdirectories_can_be_removed(): void
    {
        // Akar locations/ sendiri tidak boleh dihapus, hanya subfolder gerobak.
        $this->assertFalse(UploadedImage::deleteManagedDirectory('locations'));
        $this->assertFalse(UploadedImage::deleteManagedDirectory('homepage/hero'));
        $this->assertFalse(UploadedImage::deleteManagedDirectory('locations/../homepage'));
        $this->assertFalse(UploadedImage::deleteManagedDirectory(''));
    }

    // ---------------------------------------------------------- metadata API

    public function test_image_metadata_inspection_rejects_missing_and_odd_paths(): void
    {
        $this->assertNull(ImageMetadata::inspect('locations/aaa/tidak-ada.jpg'));
        $this->assertNull(ImageMetadata::inspect('../../etc/passwd'));
        $this->assertNull(ImageMetadata::inspect(''));
        $this->assertNull(ImageMetadata::inspect(null));
    }

    public function test_orphan_rows_never_reach_the_public_page(): void
    {
        // Baris ada, berkasnya tidak: halaman tetap aman dan tanpa gambar rusak.
        LocationImage::factory()->for($this->location)->create([
            'image_path' => 'locations/hilang/tidak-ada.jpg',
        ]);

        $content = $this->get('/lokasi/cianjur')->getContent();

        $this->assertStringNotContainsString('tidak-ada.jpg', $content);
        $this->assertStringNotContainsString('src=""', $content);
    }
}
