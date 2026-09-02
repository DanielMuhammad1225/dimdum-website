<?php

namespace Tests\Feature\Locations;

use App\Enums\UserRole;
use App\Filament\Resources\Locations\Pages\CreateLocation;
use App\Filament\Resources\Locations\Pages\EditLocation;
use App\Models\Location;
use App\Models\LocationArea;
use App\Models\LocationImage;
use App\Models\LocationPage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Foto gerobak bersifat OPSIONAL.
 *
 * Banyak gerobak didaftarkan lebih dulu di lapangan dan fotonya menyusul.
 * Form karena itu tidak boleh memaksa satu baris foto kosong yang wajib
 * diisi -- Repeater Filament secara bawaan membuka satu item, dan item itu
 * mewajibkan berkas serta alt text.
 */
class LocationOptionalImageTest extends TestCase
{
    use RefreshDatabase;

    protected LocationArea $area;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(UserRole::SuperAdmin->value);
        $this->actingAs($user->fresh());

        $this->area = LocationArea::factory()->create(['name' => 'Cianjur']);
    }

    // ------------------------------------------------------------- membuat

    public function test_the_photo_repeater_opens_empty(): void
    {
        $state = Livewire::test(CreateLocation::class)->assertSuccessful()->get('data');

        $this->assertSame(
            [],
            $state['images'] ?? [],
            'Form gerobak baru tidak boleh membuka baris foto kosong yang wajib diisi.',
        );
    }

    public function test_a_location_can_be_created_without_any_photo(): void
    {
        Livewire::test(CreateLocation::class)
            ->fillForm([
                'location_area_id' => $this->area->id,
                'name' => 'Gerobak Tanpa Foto',
                'full_address' => 'Jalan Uji Nomor 1',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $location = Location::query()->where('name', 'Gerobak Tanpa Foto')->firstOrFail();

        $this->assertSame(0, $location->images()->count());
    }

    public function test_a_location_can_be_saved_without_any_photo(): void
    {
        $location = Location::factory()->for($this->area, 'area')->create();

        Livewire::test(EditLocation::class, ['record' => $location->getRouteKey()])
            ->fillForm(['name' => 'Nama Baru', 'images' => []])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Nama Baru', $location->fresh()->name);
        $this->assertSame(0, $location->images()->count());
    }

    // ---------------------------------------------------------- alt text

    public function test_alt_text_is_required_once_a_photo_is_uploaded(): void
    {
        $location = Location::factory()->for($this->area, 'area')->create();

        Livewire::test(EditLocation::class, ['record' => $location->getRouteKey()])
            ->fillForm([
                'images' => [[
                    'image_path' => [UploadedFile::fake()->image('foto.jpg', 800, 600)],
                    'alt_text' => '',
                    'caption' => null,
                    'is_cover' => false,
                ]],
            ])
            ->call('save')
            ->assertHasFormErrors();

        $this->assertSame(0, $location->images()->count());
    }

    public function test_alt_text_is_not_demanded_when_no_photo_is_attached(): void
    {
        $location = Location::factory()->for($this->area, 'area')->create();

        Livewire::test(EditLocation::class, ['record' => $location->getRouteKey()])
            ->fillForm(['name' => 'Tetap Tersimpan', 'images' => []])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Tetap Tersimpan', $location->fresh()->name);
    }

    // ---------------------------------------------- edit tanpa upload baru

    public function test_editing_other_fields_keeps_the_existing_photos(): void
    {
        $location = Location::factory()->for($this->area, 'area')->create();

        // Berkasnya benar-benar ada di disk, seperti kondisi nyata.
        $path = 'locations/'.Str::ulid().'/foto.png';
        Storage::disk('public')->put($path, UploadedFile::fake()->image('foto.png', 800, 600)->get());

        $image = LocationImage::factory()->for($location, 'location')->create([
            'image_path' => $path,
            'alt_text' => 'Foto lama',
            'width' => 800,
            'height' => 600,
            'is_cover' => true,
        ]);

        // Form dibuka lalu disimpan TANPA menyentuh galeri sama sekali.
        Livewire::test(EditLocation::class, ['record' => $location->getRouteKey()])
            ->fillForm(['name' => 'Nama Diperbarui'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Nama Diperbarui', $location->fresh()->name);
        $this->assertDatabaseHas('location_images', [
            'id' => $image->id,
            'location_id' => $location->id,
            'alt_text' => 'Foto lama',
        ]);
        $this->assertSame(1, $location->fresh()->images()->count());
    }

    // ----------------------------------------------------- halaman publik

    public function test_a_location_without_photos_renders_without_a_broken_image(): void
    {
        $location = Location::factory()->for($this->area, 'area')
            ->create(['name' => 'Gerobak Polos']);

        $page = LocationPage::factory()->published()->create();
        $page->groups()->attach($this->area->location_group_id);
        $page->locations()->attach($location);

        $html = $this->get(route('location-pages.show', $page->slug))
            ->assertOk()
            ->assertSee('Gerobak Polos')
            ->getContent();

        // Tidak ada <img> tanpa sumber, dan tidak ada src kosong.
        $this->assertStringNotContainsString('src=""', $html);
        $this->assertStringNotContainsString("src=''", $html);
        $this->assertStringNotContainsString('storage/"', $html);
    }

    /**
     * Baris foto yang berkasnya sudah tidak ada di disk tidak boleh menjadi
     * <img> rusak: payload membuangnya lebih dulu.
     */
    public function test_a_photo_row_without_a_file_never_reaches_the_page(): void
    {
        $location = Location::factory()->for($this->area, 'area')
            ->create(['name' => 'Gerobak Yatim']);

        LocationImage::factory()->for($location, 'location')->create([
            'image_path' => 'locations/hilang/tidak-ada.png',
            'alt_text' => 'Foto hilang',
        ]);

        $page = LocationPage::factory()->published()->create();
        $page->groups()->attach($this->area->location_group_id);
        $page->locations()->attach($location);

        $html = $this->get(route('location-pages.show', $page->slug))
            ->assertOk()
            ->assertSee('Gerobak Yatim')
            ->getContent();

        $this->assertStringNotContainsString('tidak-ada.png', $html);
        $this->assertStringNotContainsString('src=""', $html);
    }
}
