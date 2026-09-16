<?php

namespace Tests\Feature\Products;

use App\Enums\UserRole;
use App\Filament\Support\UploadedImage;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Filesystem\ServeFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Thumbnail foto pada daftar Produk /admin/produk.
 *
 * Bawaan ImageColumn memakai Storage::url(), yang untuk disk 'public'
 * menempelkan APP_URL; bila panel dibuka lewat host lain, thumbnail rusak.
 * Disk palsu di sini SENGAJA diberi host berbeda dari host panel -- persis
 * kondisi development (APP_URL localhost:8000, panel lewat
 * dimdum_website.test). Tanpa perbedaan itu kedua URL kebetulan sama.
 */
class ProductThumbnailTest extends TestCase
{
    use RefreshDatabase;

    protected const DISK_URL = 'http://app-url.test:8000/storage';

    protected const ADMIN_ORIGIN = 'http://dimdum-admin.test';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public', ['url' => self::DISK_URL, 'visibility' => 'public']);

        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(UserRole::SuperAdmin->value);
        $this->actingAs($user->fresh());
    }

    protected function listPage(): string
    {
        return $this->get(self::ADMIN_ORIGIN.'/admin/produk')->assertOk()->getContent();
    }

    /**
     * src setiap <img> yang menunjuk ke berkas upload.
     *
     * @return list<string>
     */
    protected static function thumbnailSources(string $html): array
    {
        preg_match_all('/<img\b[^>]*\bsrc="([^"]*)"/i', $html, $matches);

        return array_values(array_filter(
            array_map(html_entity_decode(...), $matches[1]),
            static fn (string $src): bool => str_contains($src, '/storage/'),
        ));
    }

    /**
     * Sajikan URL thumbnail seperti server web menyajikan disk 'public'.
     *
     * PHPUnit tidak punya server berkas statis -- di produksi /storage/*
     * dilayani langsung lewat link public/storage -- jadi dipakai penyaji
     * berkas bawaan Laravel (ServeFile) atas disk yang sama.
     */
    protected function fetchFromDisk(string $url): TestResponse
    {
        $path = rawurldecode(substr((string) parse_url($url, PHP_URL_PATH), strlen('/storage/')));

        return TestResponse::fromBaseResponse(
            (new ServeFile('public', config('filesystems.disks.public') + ['visibility' => 'public'], false))(Request::create($url), $path),
        );
    }

    public function test_the_thumbnail_is_same_origin_and_the_file_answers_200(): void
    {
        $path = Storage::disk('public')->putFileAs(
            UploadedImage::PRODUCT_DIRECTORY,
            UploadedFile::fake()->image('foto.jpg', 300, 300),
            '01THUMBNAILPRODUK.jpg',
        );

        Product::factory()->create(['name' => 'Berfoto', 'image_path' => $path]);

        $sources = self::thumbnailSources($this->listPage());

        $this->assertSame([self::ADMIN_ORIGIN.'/storage/'.$path], $sources, 'Thumbnail tidak satu origin dengan halaman admin.');
        $this->assertStringStartsNotWith(self::DISK_URL, $sources[0]);

        $response = $this->fetchFromDisk($sources[0]);

        $response->assertOk();
        $this->assertStringStartsWith('image/jpeg', (string) $response->headers->get('Content-Type'));
    }

    public function test_a_missing_file_renders_an_empty_cell_not_a_broken_image(): void
    {
        Product::factory()->create([
            'name' => 'Foto Hilang',
            'image_path' => UploadedImage::PRODUCT_DIRECTORY.'/tidak-ada.jpg',
        ]);

        $html = $this->listPage();

        $this->assertStringContainsString('Foto Hilang', $html);
        $this->assertSame([], self::thumbnailSources($html));
        $this->assertDoesNotMatchRegularExpression('/<img\b[^>]*\bsrc=""/i', $html, 'Berkas hilang menghasilkan <img src=""> yang rusak.');
        $this->assertStringContainsString('fi-ta-placeholder', $html);
    }

    public function test_listing_does_not_touch_the_stored_image_data(): void
    {
        $path = Storage::disk('public')->putFileAs(
            UploadedImage::PRODUCT_DIRECTORY,
            UploadedFile::fake()->image('foto.jpg', 300, 300),
            '01THUMBNAILTETAP.jpg',
        );

        $product = Product::factory()->create(['image_path' => $path]);
        $updatedAt = $product->updated_at;

        $this->listPage();

        $product->refresh();

        $this->assertSame($path, $product->image_path);
        $this->assertEquals($updatedAt, $product->updated_at);
        Storage::disk('public')->assertExists($path);
    }

    public function test_the_resolver_only_returns_urls_for_existing_managed_files(): void
    {
        Storage::disk('public')->put(UploadedImage::PRODUCT_DIRECTORY.'/ada.jpg', 'x');

        $this->assertSame(asset('storage/products/ada.jpg'), UploadedImage::existingPreviewUrl('products/ada.jpg'));
        $this->assertNull(UploadedImage::existingPreviewUrl('products/tidak-ada.jpg'));
        $this->assertNull(UploadedImage::existingPreviewUrl('../../.env'));
        $this->assertNull(UploadedImage::existingPreviewUrl(null));
    }
}
