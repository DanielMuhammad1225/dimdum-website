<?php

namespace Tests\Feature\Cms;

use App\Enums\UserRole;
use App\Filament\Pages\ManageSiteSettings;
use App\Models\HomepageSetting;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\HomepageContentService;
use App\Services\SiteSettingsService;
use Database\Seeders\HomepageContentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ContentCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(HomepageContentSeeder::class);
    }

    public function test_reading_content_populates_both_cache_keys(): void
    {
        Cache::forget(SiteSetting::CACHE_KEY);
        Cache::forget(HomepageSetting::CACHE_KEY);

        $this->get('/')->assertOk();

        $this->assertTrue(Cache::has(SiteSetting::CACHE_KEY), 'Cache site settings harus terisi.');
        $this->assertTrue(Cache::has(HomepageSetting::CACHE_KEY), 'Cache homepage settings harus terisi.');
    }

    public function test_cached_content_is_served_without_further_queries(): void
    {
        Cache::forget(SiteSetting::CACHE_KEY);
        Cache::forget(HomepageSetting::CACHE_KEY);

        // Permintaan pertama mengisi cache.
        $this->get('/')->assertOk();

        $queries = [];

        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        // Permintaan kedua sepenuhnya dari cache.
        $this->get('/')->assertOk();

        $this->assertSame(
            [],
            $queries,
            'Permintaan kedua seharusnya tidak menyentuh database sama sekali.'
        );
    }

    public function test_the_cache_stores_plain_arrays_not_eloquent_models(): void
    {
        $this->get('/')->assertOk();

        $this->assertIsArray(Cache::get(SiteSetting::CACHE_KEY));
        $this->assertIsArray(Cache::get(HomepageSetting::CACHE_KEY));
    }

    // ------------------------------------------------------- invalidasi

    public function test_saving_the_model_forgets_only_its_own_cache_key(): void
    {
        $this->get('/')->assertOk();

        Cache::put('kunci-lain-yang-tidak-boleh-hilang', 'nilai', 600);

        SiteSetting::query()->first()->update(['brand_name' => 'DIMDUM Baru']);

        $this->assertFalse(Cache::has(SiteSetting::CACHE_KEY), 'Cache site settings harus dibuang.');
        $this->assertTrue(
            Cache::has(HomepageSetting::CACHE_KEY),
            'Cache homepage settings tidak boleh ikut dibuang.'
        );
        $this->assertSame(
            'nilai',
            Cache::get('kunci-lain-yang-tidak-boleh-hilang'),
            'Cache aplikasi lain tidak boleh terpengaruh.'
        );
    }

    public function test_saving_homepage_settings_forgets_only_its_own_cache_key(): void
    {
        $this->get('/')->assertOk();

        $setting = HomepageSetting::query()->first();
        $setting->update(['budget' => [...$setting->budget, 'title' => 'Judul Budget Baru']]);

        $this->assertFalse(Cache::has(HomepageSetting::CACHE_KEY));
        $this->assertTrue(Cache::has(SiteSetting::CACHE_KEY));
    }

    public function test_changes_appear_immediately_after_saving(): void
    {
        $this->get('/')->assertSee(config('homepage.budget.title'), false);

        $setting = HomepageSetting::query()->first();
        $setting->update(['budget' => [...$setting->budget, 'title' => 'Langsung Tampil']]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Langsung Tampil', false)
            ->assertDontSee(config('homepage.budget.title'), false);
    }

    public function test_saving_through_the_admin_page_invalidates_the_cache(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(UserRole::SuperAdmin->value);
        $this->actingAs($user->fresh());

        $this->get('/')->assertOk();
        $this->assertTrue(Cache::has(SiteSetting::CACHE_KEY));

        Livewire::test(ManageSiteSettings::class)
            ->fillForm([
                'brand_name' => 'DIMDUM',
                'tagline' => 'Tagline setelah simpan.',
                'positioning' => config('dimdum.positioning'),
                'default_meta_title' => config('dimdum.seo.title'),
                'default_meta_description' => config('dimdum.seo.description'),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse(
            Cache::has(SiteSetting::CACHE_KEY),
            'Cache harus dibuang setelah penyimpanan berhasil dari admin panel.'
        );

        $this->get('/')->assertSee('Tagline setelah simpan.', false);
    }

    public function test_deleting_a_singleton_also_forgets_its_cache(): void
    {
        $this->get('/')->assertOk();

        SiteSetting::query()->first()->delete();

        $this->assertFalse(Cache::has(SiteSetting::CACHE_KEY));

        // Baris hilang -> jatuh ke fallback config, bukan HTTP 500.
        $this->get('/')
            ->assertOk()
            ->assertSee(config('dimdum.tagline'), false);
    }

    public function test_an_empty_cache_falls_back_to_the_database(): void
    {
        HomepageSetting::query()->first()->update([
            'budget' => ['title' => 'Judul Dari Database', 'copy' => 'Copy.', 'support_copy' => 'Support.'],
        ]);

        Cache::forget(HomepageSetting::CACHE_KEY);
        Cache::forget(SiteSetting::CACHE_KEY);

        $this->assertFalse(Cache::has(HomepageSetting::CACHE_KEY));

        $this->get('/')
            ->assertOk()
            ->assertSee('Judul Dari Database', false);
    }

    // --------------------------------------------------- larangan flush

    public function test_the_codebase_never_flushes_the_whole_cache(): void
    {
        $files = array_merge(
            $this->phpFilesIn(app_path()),
            $this->phpFilesIn(database_path('seeders')),
        );

        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $source = file_get_contents($file);

            foreach (['Cache::flush(', 'cache()->flush(', 'cache:clear', 'Artisan::call'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $source,
                    "Kode tidak boleh membuang seluruh cache aplikasi: {$file}"
                );
            }
        }
    }

    public function test_services_expose_distinct_cache_keys(): void
    {
        $this->assertSame('site_settings.default', SiteSetting::CACHE_KEY);
        $this->assertSame('homepage_settings.homepage', HomepageSetting::CACHE_KEY);
        $this->assertNotSame(SiteSetting::CACHE_KEY, HomepageSetting::CACHE_KEY);

        // Service memang membaca lewat key tersebut.
        Cache::forget(SiteSetting::CACHE_KEY);
        app(SiteSettingsService::class)->brand();
        $this->assertTrue(Cache::has(SiteSetting::CACHE_KEY));

        Cache::forget(HomepageSetting::CACHE_KEY);
        app(HomepageContentService::class)->homepage();
        $this->assertTrue(Cache::has(HomepageSetting::CACHE_KEY));
    }

    /**
     * @return list<string>
     */
    protected function phpFilesIn(string $directory): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
