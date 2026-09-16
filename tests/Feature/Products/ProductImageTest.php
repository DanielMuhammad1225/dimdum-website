<?php

namespace Tests\Feature\Products;

use App\Enums\ProductCategory;
use App\Enums\ProductType;
use App\Enums\UserRole;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Support\UploadedImage;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductCatalogService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Foto produk: opsional, aman, dan tidak pernah hilang tanpa diminta.
 *
 * Aturan upload sama dengan modul lain -- allowlist MIME tanpa SVG, nama file
 * acak, path relatif -- dengan batas ukuran yang lebih kecil karena enam
 * kartu tampil sekaligus di halaman yang paling sering dibuka.
 */
class ProductImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(UserRole::SuperAdmin->value);
        $this->actingAs($user->fresh());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function formState(array $overrides = []): array
    {
        return [
            'name' => 'Dimsum Uji',
            'category' => ProductCategory::Menu->value,
            'type' => ProductType::Satuan->value,
            ...$overrides,
        ];
    }

    // --------------------------------------------------------- foto opsional

    public function test_a_product_can_be_created_without_a_photo(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm($this->formState(['name' => 'Tanpa Foto']))
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::query()->where('name', 'Tanpa Foto')->firstOrFail();

        $this->assertNull($product->image_path);
    }

    public function test_alt_text_alone_never_creates_a_photo(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm($this->formState([
                'name' => 'Alt Tanpa Foto',
                'image_alt' => 'Sekadar teks',
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::query()->where('name', 'Alt Tanpa Foto')->firstOrFail();

        $this->assertNull($product->image_path);
    }

    // ------------------------------------------------------------ keamanan

    public function test_it_accepts_jpg_png_and_webp(): void
    {
        foreach (['jpg', 'png', 'webp'] as $extension) {
            $name = 'Produk '.strtoupper($extension);

            Livewire::test(CreateProduct::class)
                ->fillForm($this->formState([
                    'name' => $name,
                    'image_path' => [UploadedFile::fake()->image("foto.{$extension}", 600, 600)],
                ]))
                ->call('create')
                ->assertHasNoFormErrors();

            $path = Product::query()->where('name', $name)->value('image_path');

            $this->assertIsString($path, "Upload {$extension} tidak tersimpan.");
            Storage::disk('public')->assertExists($path);
        }
    }

    public function test_it_rejects_an_svg_upload(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm($this->formState([
                'image_path' => [UploadedFile::fake()->createWithContent(
                    'jahat.svg',
                    '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
                )],
            ]))
            ->call('create')
            ->assertHasFormErrors(['image_path']);

        $this->assertSame(0, Product::query()->count());
    }

    public function test_it_rejects_a_non_image_file(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm($this->formState([
                'image_path' => [UploadedFile::fake()->create('dokumen.pdf', 100, 'application/pdf')],
            ]))
            ->call('create')
            ->assertHasFormErrors(['image_path']);

        $this->assertSame(0, Product::query()->count());
    }

    public function test_it_rejects_a_file_larger_than_two_megabytes(): void
    {
        $this->assertSame(2048, UploadedImage::PRODUCT_MAX_SIZE_KB);

        Livewire::test(CreateProduct::class)
            ->fillForm($this->formState([
                'image_path' => [UploadedFile::fake()->image('besar.jpg')->size(2049)],
            ]))
            ->call('create')
            ->assertHasFormErrors(['image_path']);

        $this->assertSame(0, Product::query()->count());
    }

    public function test_the_stored_name_is_random_and_the_path_is_relative(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm($this->formState([
                'image_path' => [UploadedFile::fake()->image('NAMA ASLI yang panjang.jpg', 600, 600)],
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $path = Product::query()->value('image_path');

        $this->assertIsString($path);
        $this->assertStringStartsWith(UploadedImage::PRODUCT_DIRECTORY.'/', $path);
        $this->assertStringNotContainsString('NAMA ASLI', $path);
        // Path relatif: bukan URL dan bukan path filesystem.
        $this->assertStringNotContainsString('http', $path);
        $this->assertStringNotContainsString(':', $path);
        $this->assertStringNotContainsString('storage/app', $path);
    }

    /**
     * Ekstensi mengikuti ISI berkas, bukan nama maupun header kiriman.
     */
    public function test_the_extension_follows_the_real_file_content(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm($this->formState([
                // Berkas PNG sungguhan yang dinamai .jpg.
                'image_path' => [UploadedFile::fake()->image('menipu.jpg', 400, 400)->mimeType('image/png')],
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $path = Product::query()->value('image_path');

        $this->assertIsString($path);
        $this->assertMatchesRegularExpression('/\.(jpg|png|webp)$/', $path);
    }

    public function test_private_storage_is_never_used(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm($this->formState([
                'image_path' => [UploadedFile::fake()->image('foto.jpg', 400, 400)],
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('public', UploadedImage::DISK);
        Storage::disk('public')->assertExists((string) Product::query()->value('image_path'));
    }

    // -------------------------------------------------------- siklus berkas

    public function test_editing_other_fields_keeps_the_existing_photo(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm($this->formState([
                'name' => 'Punya Foto',
                'image_path' => [UploadedFile::fake()->image('awal.jpg', 400, 400)],
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::query()->where('name', 'Punya Foto')->firstOrFail();
        $original = $product->image_path;

        $this->assertIsString($original);

        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->fillForm(['name' => 'Nama Baru'])
            ->call('save')
            ->assertHasNoFormErrors();

        $product->refresh();

        $this->assertSame('Nama Baru', $product->name);
        $this->assertSame($original, $product->image_path, 'Menyimpan tanpa upload baru menghapus foto lama.');
        Storage::disk('public')->assertExists($original);
    }

    public function test_replacing_the_photo_removes_the_previous_file(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm($this->formState([
                'image_path' => [UploadedFile::fake()->image('lama.jpg', 400, 400)],
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::query()->firstOrFail();
        $original = (string) $product->image_path;

        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->fillForm(['image_path' => [UploadedFile::fake()->image('baru.jpg', 500, 500)]])
            ->call('save')
            ->assertHasNoFormErrors();

        $product->refresh();

        $this->assertNotSame($original, $product->image_path);
        Storage::disk('public')->assertMissing($original);
        Storage::disk('public')->assertExists((string) $product->image_path);
    }

    /**
     * Soft delete TIDAK menyentuh berkas, supaya pemulihan mengembalikan
     * produk beserta fotonya.
     */
    public function test_soft_deleting_keeps_the_file_on_disk(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm($this->formState([
                'image_path' => [UploadedFile::fake()->image('foto.jpg', 400, 400)],
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::query()->firstOrFail();
        $path = (string) $product->image_path;

        $product->delete();

        Storage::disk('public')->assertExists($path);
    }

    public function test_force_deleting_removes_the_file(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm($this->formState([
                'image_path' => [UploadedFile::fake()->image('foto.jpg', 400, 400)],
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::query()->firstOrFail();
        $path = (string) $product->image_path;

        // Filament hanya menampilkan "Hapus permanen" untuk baris yang sudah
        // terhapus, jadi soft delete dulu -- persis alur admin.
        $product->delete();

        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->callAction('forceDelete');

        Storage::disk('public')->assertMissing($path);
        $this->assertSame(0, Product::withTrashed()->count());
    }

    /**
     * Aset brand berada di public/images/brand -- di luar disk 'public' --
     * dan tidak boleh bisa ikut terhapus lewat jalur mana pun.
     */
    public function test_unmanaged_paths_are_never_deleted(): void
    {
        $this->assertFalse(UploadedImage::deleteManagedFile('images/brand/dimdum-logo-primary.png'));
        $this->assertFalse(UploadedImage::deleteManagedFile('../../.env'));
        $this->assertTrue(UploadedImage::isManagedPath(UploadedImage::PRODUCT_DIRECTORY.'/abc.jpg'));
    }

    // ------------------------------------------------------ fallback tampilan

    public function test_a_photo_beats_the_emoji_on_the_card(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm($this->formState([
                'name' => 'Berfoto',
                'emoji' => '🥟',
                'show_on_homepage' => true,
                'image_path' => [UploadedFile::fake()->image('foto.jpg', 400, 400)],
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $card = app(ProductCatalogService::class)->homepageProducts()['items'][0];

        $this->assertIsArray($card['image']);
        $this->assertSame('🥟', $card['emoji']);

        // Blade memilih foto lebih dulu; emoji hanya cadangan.
        $this->get('/')->assertOk()->assertSee($card['image']['src'], false);
    }

    public function test_the_emoji_is_used_when_there_is_no_photo(): void
    {
        Product::factory()->onHomepage()->withEmoji('🍤')->create(['name' => 'Ekkado']);

        $card = app(ProductCatalogService::class)->homepageProducts()['items'][0];

        $this->assertNull($card['image']);
        $this->assertSame('🍤', $card['emoji']);

        $this->get('/')->assertOk()->assertSee('🍤', false);
    }

    /**
     * Berkas yang hilang dari disk TIDAK boleh menghasilkan <img src="">.
     */
    public function test_a_missing_file_falls_back_instead_of_rendering_an_empty_src(): void
    {
        Product::factory()->onHomepage()->withEmoji('🥟')->create([
            'name' => 'Foto Hilang',
            'image_path' => UploadedImage::PRODUCT_DIRECTORY.'/tidak-ada.jpg',
        ]);

        $card = app(ProductCatalogService::class)->homepageProducts()['items'][0];

        $this->assertNull($card['image'], 'Berkas yang tidak ada tidak boleh menjadi src.');

        $this->get('/')->assertOk()->assertDontSee('<img src=""', false);
    }

    public function test_the_alt_text_falls_back_to_the_product_name(): void
    {
        $product = Product::factory()->create(['name' => 'Dimsum Ayam', 'image_alt' => null]);

        $this->assertSame('Dimsum Ayam', $product->imageAltText());

        $product->update(['image_alt' => 'Foto dimsum ayam di atas piring']);

        $this->assertSame('Foto dimsum ayam di atas piring', $product->fresh()->imageAltText());
    }

    public function test_uploading_a_photo_invalidates_the_cache(): void
    {
        $product = Product::factory()->create();

        $before = ProductCatalogService::cacheVersion();

        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->fillForm(['image_path' => [UploadedFile::fake()->image('foto.jpg', 400, 400)]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertGreaterThan($before, ProductCatalogService::cacheVersion());
    }
}
