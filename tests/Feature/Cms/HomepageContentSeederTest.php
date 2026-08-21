<?php

namespace Tests\Feature\Cms;

use App\Models\HomepageSetting;
use App\Models\SiteSetting;
use App\Models\User;
use Database\Seeders\HomepageContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HomepageContentSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_both_singletons_from_config(): void
    {
        $this->seed(HomepageContentSeeder::class);

        $site = SiteSetting::query()->where('key', SiteSetting::SINGLETON_KEY)->first();
        $homepage = HomepageSetting::query()->where('key', HomepageSetting::SINGLETON_KEY)->first();

        $this->assertNotNull($site);
        $this->assertNotNull($homepage);

        $this->assertSame(config('dimdum.name'), $site->brand_name);
        $this->assertSame(config('dimdum.tagline'), $site->tagline);
        $this->assertSame(config('dimdum.seo.title'), $site->default_meta_title);

        $this->assertSame(config('homepage.hero.headline'), $homepage->hero['headline']);
        $this->assertSame(config('homepage.usp.title'), $homepage->usp['title']);
    }

    public function test_json_columns_are_cast_to_arrays(): void
    {
        $this->seed(HomepageContentSeeder::class);

        $homepage = HomepageSetting::query()->first();

        foreach (['hero', 'usp', 'products', 'how', 'budget', 'locations', 'cta', 'sections'] as $column) {
            $this->assertIsArray($homepage->{$column}, "Kolom {$column} harus di-cast menjadi array.");
        }

        $this->assertIsArray($homepage->usp['items']);
        $this->assertIsArray($homepage->sections[0]);
        $this->assertArrayHasKey('key', $homepage->sections[0]);
        $this->assertArrayHasKey('enabled', $homepage->sections[0]);
    }

    public function test_it_only_stores_allowlisted_section_keys(): void
    {
        config(['homepage.sections' => ['hero', 'section-palsu', 'cta']]);

        $this->seed(HomepageContentSeeder::class);

        $keys = array_column(HomepageSetting::query()->first()->sections, 'key');

        $this->assertSame(['hero', 'cta'], $keys);
    }

    public function test_it_is_idempotent(): void
    {
        $this->seed(HomepageContentSeeder::class);
        $this->seed(HomepageContentSeeder::class);
        $this->seed(HomepageContentSeeder::class);

        $this->assertSame(1, SiteSetting::query()->count());
        $this->assertSame(1, HomepageSetting::query()->count());
    }

    public function test_it_never_overwrites_admin_changes(): void
    {
        $this->seed(HomepageContentSeeder::class);

        SiteSetting::query()->first()->update(['brand_name' => 'Nama Baru Dari Admin']);
        HomepageSetting::query()->first()->update([
            'hero' => ['headline' => 'Headline Baru Dari Admin'],
        ]);

        $this->seed(HomepageContentSeeder::class);

        $this->assertSame('Nama Baru Dari Admin', SiteSetting::query()->first()->brand_name);
        $this->assertSame('Headline Baru Dari Admin', HomepageSetting::query()->first()->hero['headline']);
    }

    public function test_it_does_not_create_users_roles_or_permissions(): void
    {
        $this->seed(HomepageContentSeeder::class);

        $this->assertSame(0, User::query()->count(), 'Seeder konten tidak boleh membuat user.');
        $this->assertSame(0, Role::query()->count(), 'Seeder konten tidak boleh membuat role.');
        $this->assertSame(0, Permission::query()->count(), 'Seeder konten tidak boleh membuat permission.');
    }

    public function test_it_does_not_delete_rows(): void
    {
        $this->seed(HomepageContentSeeder::class);

        $siteId = SiteSetting::query()->first()->id;
        $homepageId = HomepageSetting::query()->first()->id;

        $this->seed(HomepageContentSeeder::class);

        $this->assertSame($siteId, SiteSetting::query()->first()->id);
        $this->assertSame($homepageId, HomepageSetting::query()->first()->id);
    }
}
