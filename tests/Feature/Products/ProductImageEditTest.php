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
use App\Services\LocationPageCatalogService;
use App\Services\ProductCatalogService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Foto produk pada halaman Edit.
 *
 * Bug yang ditutup: saat Edit dibuka, FilePond mengunduh foto lama dengan
 * fetch() ke URL dari Storage::url(), yang untuk disk 'public' memakai
 * APP_URL. Bila admin membuka panel lewat host lain, fetch itu gagal, load()
 * FilePond tidak pernah dipanggil, dan field berputar selamanya.
 *
 * Karena itu disk palsu di sini SENGAJA diberi URL dengan host yang berbeda
 * dari host panel -- persis kondisi development (APP_URL localhost:8000,
 * panel dibuka lewat dimdum_website.test). Tanpa perbedaan itu kedua URL
 * kebetulan sama dan test lulus walau bug-nya kembali.
 */
class ProductImageEditTest extends TestCase
{
    use RefreshDatabase;

    /** Host yang dipakai URL bawaan disk -- setara APP_URL yang tidak cocok. */
    protected const DISK_URL = 'http://app-url.test:8000/storage';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public', ['url' => self::DISK_URL]);

        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(UserRole::SuperAdmin->value);
        $this->actingAs($user->fresh());
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Buat produk berfoto lewat form Create, persis alur admin.
     */
    protected function productWithImage(string $name = 'Dimsum Berfoto'): Product
    {
        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => $name,
                'category' => ProductCategory::Menu->value,
                'type' => ProductType::Satuan->value,
                'image_path' => [UploadedFile::fake()->image('asli.jpg', 400, 400)],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        return Product::query()->where('name', $name)->firstOrFail();
    }

    /**
     * Panggil method yang dipakai FilePond untuk memuat berkas lama --
     * jalur yang sama dengan getUploadedFilesUsing() di browser.
     *
     * @return array<string, array<string, mixed>|null>
     */
    protected function uploadedFiles(Testable $page): array
    {
        $files = null;

        $page->call('callSchemaComponentMethod', 'form.image_path', 'getUploadedFiles')
            ->assertReturned(function ($returned) use (&$files): bool {
                $files = $returned;

                return true;
            });

        $this->assertIsArray($files, 'getUploadedFiles tidak mengembalikan array.');

        return $files;
    }

    // ------------------------------------------------------------------ tests

    public function test_creating_a_product_with_an_image_stores_the_file(): void
    {
        $product = $this->productWithImage();

        $this->assertIsString($product->image_path);
        $this->assertStringStartsWith(UploadedImage::PRODUCT_DIRECTORY.'/', $product->image_path);
        Storage::disk('public')->assertExists($product->image_path);
    }

    public function test_the_edit_form_loads_the_existing_image_from_the_admin_origin(): void
    {
        $product = $this->productWithImage();

        $page = Livewire::test(EditProduct::class, ['record' => $product->getKey()]);

        $state = $page->get('data.image_path');
        $this->assertSame([$product->image_path], array_values((array) $state), 'Path lama tidak masuk ke state form.');

        $files = array_values(array_filter($this->uploadedFiles($page)));

        $this->assertCount(1, $files, 'FilePond tidak menerima berkas lama untuk dimuat.');
        $this->assertSame('image/jpeg', $files[0]['type']);
        $this->assertGreaterThan(0, $files[0]['size']);

        // Satu origin dengan halaman admin, bukan host bawaan disk.
        $this->assertSame(asset('storage/'.$product->image_path), $files[0]['url']);
        $this->assertStringStartsNotWith(self::DISK_URL, $files[0]['url']);
    }

    /**
     * URL pratinjau mengikuti host request yang sedang dipakai admin.
     */
    public function test_the_preview_url_follows_the_request_host(): void
    {
        $this->app['url']->setRequest(Request::create('http://127.0.0.1:8123/admin/produk/1/edit'));

        $this->assertSame(
            'http://127.0.0.1:8123/storage/products/01ABC.jpg',
            UploadedImage::previewUrl('products/01ABC.jpg'),
        );

        // Setiap segmen di-encode; path di luar direktori modul tidak diberi URL.
        $this->assertSame('http://127.0.0.1:8123/storage/products/nama%20lama.jpg', UploadedImage::previewUrl('products/nama lama.jpg'));
        $this->assertNull(UploadedImage::previewUrl('../../.env'));
        $this->assertNull(UploadedImage::previewUrl('products/../../.env'));
        $this->assertNull(UploadedImage::previewUrl('images/brand/dimdum-logo-primary.png'));
        $this->assertNull(UploadedImage::previewUrl(''));
        $this->assertNull(UploadedImage::previewUrl(null));
    }

    public function test_saving_without_replacing_keeps_the_image(): void
    {
        $product = $this->productWithImage();
        $original = $product->image_path;

        $page = Livewire::test(EditProduct::class, ['record' => $product->getKey()]);

        // Urutan persis browser: FilePond memuat berkas lama, lalu disimpan.
        $this->uploadedFiles($page);

        $page->fillForm(['name' => 'Nama Baru'])
            ->call('save')
            ->assertHasNoFormErrors();

        $product->refresh();

        $this->assertSame('Nama Baru', $product->name);
        $this->assertSame($original, $product->image_path);
        Storage::disk('public')->assertExists($original);
    }

    /**
     * Berkas lama baru dihapus SETELAH pengganti tersimpan: selama unggahan
     * baru belum disimpan -- atau penyimpanannya gagal -- berkas lama utuh.
     */
    public function test_replacing_the_image_deletes_the_old_file_only_after_a_successful_save(): void
    {
        $product = $this->productWithImage();
        $original = $product->image_path;

        $page = Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->fillForm(['image_path' => [UploadedFile::fake()->image('baru.png', 500, 500)]]);

        Storage::disk('public')->assertExists($original);

        // Penyimpanan yang gagal validasi tidak menyentuh berkas maupun baris.
        $page->fillForm(['name' => ''])
            ->call('save')
            ->assertHasFormErrors(['name' => 'required']);

        Storage::disk('public')->assertExists($original);
        $this->assertSame($original, $product->fresh()->image_path);

        $page->fillForm(['name' => 'Dimsum Berfoto'])
            ->call('save')
            ->assertHasNoFormErrors();

        $product->refresh();

        $this->assertNotSame($original, $product->image_path);
        $this->assertStringEndsWith('.png', (string) $product->image_path);
        Storage::disk('public')->assertExists((string) $product->image_path);
        Storage::disk('public')->assertMissing($original);
    }

    public function test_replacing_with_an_svg_is_rejected_and_keeps_the_old_image(): void
    {
        $product = $this->productWithImage();
        $original = $product->image_path;

        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->fillForm(['image_path' => [UploadedFile::fake()->createWithContent(
                'jahat.svg',
                '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            )]])
            ->call('save')
            ->assertHasFormErrors(['image_path']);

        $this->assertSame($original, $product->fresh()->image_path);
        Storage::disk('public')->assertExists($original);
    }

    public function test_a_product_without_an_image_can_be_edited(): void
    {
        $product = Product::factory()->create(['name' => 'Tanpa Foto', 'image_path' => null]);

        $page = Livewire::test(EditProduct::class, ['record' => $product->getKey()]);

        $this->assertSame([], array_filter($this->uploadedFiles($page)));

        $page->fillForm(['name' => 'Tetap Tanpa Foto'])
            ->call('save')
            ->assertHasNoFormErrors();

        $product->refresh();

        $this->assertSame('Tetap Tanpa Foto', $product->name);
        $this->assertNull($product->image_path);
    }

    /**
     * Path di database yang berkasnya sudah tidak ada: field tampil kosong,
     * tidak ada URL yang ditunggu FilePond, dan form tetap bisa disimpan.
     *
     * Rujukan ke berkas yang tidak ada dibersihkan saat disimpan -- perilaku
     * bawaan Filament. Situs publik sudah memakai emoji untuk kasus ini, jadi
     * tidak ada tampilan yang berubah.
     */
    public function test_a_missing_file_leaves_an_empty_field_and_the_form_saves(): void
    {
        $product = Product::factory()->create([
            'name' => 'Foto Hilang',
            'image_path' => UploadedImage::PRODUCT_DIRECTORY.'/tidak-ada.jpg',
        ]);

        $page = Livewire::test(EditProduct::class, ['record' => $product->getKey()]);

        $this->assertSame([], array_values((array) $page->get('data.image_path')));
        $this->assertSame([], array_filter($this->uploadedFiles($page)));

        $page->fillForm(['name' => 'Foto Hilang Diubah'])
            ->call('save')
            ->assertHasNoFormErrors();

        $product->refresh();

        $this->assertSame('Foto Hilang Diubah', $product->name);
        $this->assertNull($product->image_path);
    }

    /**
     * Berkas yang hilang SETELAH form terbuka tidak diberi URL, sehingga
     * FilePond melewatinya alih-alih menunggu unduhan yang tidak pernah ada.
     */
    public function test_a_file_deleted_after_the_form_opened_gets_no_preview_url(): void
    {
        $product = $this->productWithImage();

        $page = Livewire::test(EditProduct::class, ['record' => $product->getKey()]);

        Storage::disk('public')->delete((string) $product->image_path);

        $this->assertSame([], array_filter($this->uploadedFiles($page)));
    }

    public function test_updating_a_product_invalidates_the_product_caches(): void
    {
        $product = $this->productWithImage();

        $productVersion = ProductCatalogService::cacheVersion();
        $locationVersion = LocationPageCatalogService::cacheVersion();

        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->fillForm(['name' => 'Nama Baru'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertGreaterThan($productVersion, ProductCatalogService::cacheVersion());
        $this->assertGreaterThan($locationVersion, LocationPageCatalogService::cacheVersion());
    }
}
