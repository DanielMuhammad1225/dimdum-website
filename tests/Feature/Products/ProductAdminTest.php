<?php

namespace Tests\Feature\Products;

use App\Enums\PanelPermission;
use App\Enums\ProductCategory;
use App\Enums\ProductType;
use App\Enums\UserRole;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductCatalogService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Admin modul Produk.
 *
 * Yang dijaga: authorization berjalan di SERVER (bukan sekadar menu yang
 * disembunyikan), nilai enum di luar daftar ditolak, nomor urut tidak pernah
 * datang dari request, dan drag-and-drop menaikkan versi cache.
 */
class ProductAdminTest extends TestCase
{
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

    public function test_super_admin_and_admin_reach_every_screen(): void
    {
        foreach ([UserRole::SuperAdmin, UserRole::Admin] as $role) {
            $product = Product::factory()->create();

            $this->actingAsRole($role);

            $this->get('/admin/produk')->assertOk();
            $this->get('/admin/produk/create')->assertOk();
            $this->get("/admin/produk/{$product->getKey()}/edit")->assertOk();
        }
    }

    public function test_operator_may_view_create_and_update(): void
    {
        $product = Product::factory()->create();

        $this->actingAsRole(UserRole::Operator);

        $this->get('/admin/produk')->assertOk();
        $this->get('/admin/produk/create')->assertOk();
        $this->get("/admin/produk/{$product->getKey()}/edit")->assertOk();
    }

    public function test_operator_may_not_delete_or_force_delete(): void
    {
        $operator = $this->actingAsRole(UserRole::Operator);
        $product = Product::factory()->create();

        // Diperiksa lewat policy, bukan lewat tombol yang disembunyikan.
        $this->assertFalse($operator->can('delete', $product));
        $this->assertFalse($operator->can('restore', $product));
        $this->assertFalse($operator->can('forceDelete', $product));

        $this->assertFalse($operator->can(PanelPermission::DeleteProducts->value));
        $this->assertFalse($operator->can(PanelPermission::ForceDeleteProducts->value));
    }

    public function test_admin_may_delete_and_force_delete(): void
    {
        $admin = $this->actingAsRole(UserRole::Admin);
        $product = Product::factory()->create();

        $this->assertTrue($admin->can('delete', $product));
        $this->assertTrue($admin->can('forceDelete', $product));
    }

    public function test_a_user_without_the_module_is_refused_on_a_direct_url(): void
    {
        $product = Product::factory()->create();

        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);

        $this->get('/admin/produk')->assertForbidden();
        $this->get('/admin/produk/create')->assertForbidden();
        $this->get("/admin/produk/{$product->getKey()}/edit")->assertForbidden();
    }

    public function test_an_inactive_account_is_refused_even_with_the_permission(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $user->assignRole(UserRole::SuperAdmin->value);

        $this->actingAs($user->fresh());

        $this->get('/admin/produk')->assertForbidden();
    }

    // ------------------------------------------------------------------ CRUD

    public function test_a_product_can_be_created(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Dimsum Ayam Original',
                'category' => ProductCategory::Menu->value,
                'type' => ProductType::Satuan->value,
                'price' => 5000,
                'is_active' => true,
                'show_on_homepage' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('products', [
            'name' => 'Dimsum Ayam Original',
            'category' => ProductCategory::Menu->value,
            'type' => ProductType::Satuan->value,
            'price' => 5000,
            'is_active' => true,
            'show_on_homepage' => true,
        ]);
    }

    public function test_the_creator_and_editor_are_recorded(): void
    {
        $user = $this->actingAsRole(UserRole::SuperAdmin);

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Ekkado',
                'category' => ProductCategory::Varian->value,
                'type' => ProductType::Satuan->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::query()->firstOrFail();

        $this->assertSame($user->getKey(), $product->created_by);
        $this->assertSame($user->getKey(), $product->updated_by);
    }

    public function test_a_product_can_be_edited(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $product = Product::factory()->create(['name' => 'Nama Lama']);

        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->fillForm(['name' => 'Nama Baru', 'price_text' => 'Rp7.000/pcs'])
            ->call('save')
            ->assertHasNoFormErrors();

        $product->refresh();

        $this->assertSame('Nama Baru', $product->name);
        $this->assertSame('Rp7.000/pcs', $product->price_text);
    }

    public function test_a_product_can_be_soft_deleted_and_restored(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $product = Product::factory()->create();

        $product->delete();
        $this->assertSoftDeleted('products', ['id' => $product->getKey()]);

        $product->restore();
        $this->assertDatabaseHas('products', ['id' => $product->getKey(), 'deleted_at' => null]);
    }

    public function test_the_name_is_required(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        Livewire::test(CreateProduct::class)
            ->fillForm(['name' => ''])
            ->call('create')
            ->assertHasFormErrors(['name']);

        $this->assertSame(0, Product::query()->count());
    }

    // ------------------------------------------------------------------ enum

    public function test_every_category_and_type_is_accepted(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        foreach (ProductCategory::cases() as $category) {
            foreach (ProductType::cases() as $type) {
                Livewire::test(CreateProduct::class)
                    ->fillForm([
                        'name' => 'Uji '.$category->value.' '.$type->value,
                        'category' => $category->value,
                        'type' => $type->value,
                    ])
                    ->call('create')
                    ->assertHasNoFormErrors();
            }
        }

        $this->assertSame(
            count(ProductCategory::cases()) * count(ProductType::cases()),
            Product::query()->count(),
        );
    }

    public function test_a_category_outside_the_enum_is_rejected(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Kategori Palsu',
                'category' => 'kategori_karangan',
                'type' => ProductType::Satuan->value,
            ])
            ->call('create')
            ->assertHasFormErrors(['category']);

        $this->assertSame(0, Product::query()->count());
    }

    public function test_a_type_outside_the_enum_is_rejected(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Tipe Palsu',
                'category' => ProductCategory::Menu->value,
                'type' => 'tipe_karangan',
            ])
            ->call('create')
            ->assertHasFormErrors(['type']);

        $this->assertSame(0, Product::query()->count());
    }

    public function test_the_enums_are_cast_on_the_model(): void
    {
        $product = Product::factory()
            ->category(ProductCategory::Frozen)
            ->type(ProductType::FrozenFood)
            ->create();

        $product->refresh();

        $this->assertInstanceOf(ProductCategory::class, $product->category);
        $this->assertInstanceOf(ProductType::class, $product->type);
        $this->assertSame('Frozen', $product->categoryLabel());
        $this->assertSame('Frozen Food', $product->typeLabel());
    }

    // ----------------------------------------------------------------- harga

    public function test_price_text_wins_over_the_numeric_price(): void
    {
        $product = Product::factory()->create(['price' => 5000, 'price_text' => 'Rp5.000/pcs']);

        $this->assertSame('Rp5.000/pcs', $product->priceLabel());
    }

    public function test_a_numeric_price_is_formatted_as_rupiah(): void
    {
        $this->assertSame('Rp5.000', Product::factory()->create(['price' => 5000])->priceLabel());
        $this->assertSame('Rp1.250.000', Product::factory()->create(['price' => 1250000])->priceLabel());
        // Nol adalah harga yang sah dan berbeda dari "tidak ada harga".
        $this->assertSame('Rp0', Product::factory()->create(['price' => 0])->priceLabel());
    }

    public function test_a_product_without_any_price_has_no_label(): void
    {
        $product = Product::factory()->create(['price' => null, 'price_text' => null]);

        $this->assertNull($product->priceLabel());
    }

    public function test_a_blank_price_text_falls_back_to_the_number(): void
    {
        $product = Product::factory()->create(['price' => 3000, 'price_text' => '   ']);

        $this->assertSame('Rp3.000', $product->priceLabel());
    }

    // ---------------------------------------------------------------- urutan

    public function test_the_ordering_field_is_absent_from_both_forms(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $product = Product::factory()->create();

        Livewire::test(CreateProduct::class)->assertFormFieldDoesNotExist('sort_order');
        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->assertFormFieldDoesNotExist('sort_order');
    }

    public function test_each_new_product_is_appended_to_the_end(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        foreach (['Pertama', 'Kedua', 'Ketiga'] as $name) {
            Livewire::test(CreateProduct::class)
                ->fillForm([
                    'name' => $name,
                    'category' => ProductCategory::Menu->value,
                    'type' => ProductType::Satuan->value,
                ])
                ->call('create')
                ->assertHasNoFormErrors();
        }

        $this->assertSame(
            ['Pertama', 'Kedua', 'Ketiga'],
            Product::query()->orderBy('sort_order')->pluck('name')->all(),
        );

        $this->assertSame([1, 2, 3], Product::query()->orderBy('sort_order')->pluck('sort_order')->all());
    }

    public function test_a_sort_order_sent_in_the_request_is_never_trusted(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        Product::factory()->create(['sort_order' => 1]);

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Penyusup',
                'category' => ProductCategory::Menu->value,
                'type' => ProductType::Satuan->value,
            ])
            // Nilai ini tidak punya field-nya; kalaupun sampai ke payload,
            // sort_order tidak fillable dan dihitung ulang oleh server.
            ->set('data.sort_order', 0)
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(2, Product::query()->where('name', 'Penyusup')->value('sort_order'));
    }

    public function test_dragging_rows_saves_the_new_order(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $a = Product::factory()->create(['name' => 'A', 'sort_order' => 1]);
        $b = Product::factory()->create(['name' => 'B', 'sort_order' => 2]);
        $c = Product::factory()->create(['name' => 'C', 'sort_order' => 3]);

        Livewire::test(ListProducts::class)
            ->call('reorderTable', [(string) $c->getKey(), (string) $a->getKey(), (string) $b->getKey()]);

        $this->assertSame(
            ['C', 'A', 'B'],
            Product::query()->orderBy('sort_order')->pluck('name')->all(),
        );
    }

    public function test_reordering_invalidates_the_public_cache(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $a = Product::factory()->create(['sort_order' => 1]);
        $b = Product::factory()->create(['sort_order' => 2]);

        $before = ProductCatalogService::cacheVersion();

        Livewire::test(ListProducts::class)
            ->call('reorderTable', [(string) $b->getKey(), (string) $a->getKey()]);

        $this->assertGreaterThan(
            $before,
            ProductCatalogService::cacheVersion(),
            'Versi cache harus naik supaya halaman publik memakai urutan baru.',
        );
    }

    /**
     * Operator BOLEH menata urutan produk.
     *
     * Reorder mengikuti permission MENGUBAH, dan Operator memang memilikinya
     * -- pola yang sama dipakai gerobak di modul lokasi. Menutupnya di sini
     * juga akan tidak konsisten: Operator sudah bisa menentukan keanggotaan
     * homepage lewat saklar "Tampilkan di homepage", jadi melarangnya menata
     * urutan tidak menambah proteksi apa pun.
     */
    public function test_an_operator_may_reorder(): void
    {
        $this->actingAsRole(UserRole::Operator);

        $a = Product::factory()->create(['name' => 'A', 'sort_order' => 1]);
        $b = Product::factory()->create(['name' => 'B', 'sort_order' => 2]);

        Livewire::test(ListProducts::class)
            ->call('reorderTable', [(string) $b->getKey(), (string) $a->getKey()]);

        $this->assertSame(
            ['B', 'A'],
            Product::query()->orderBy('sort_order')->pluck('name')->all(),
        );
    }

    /**
     * Tanpa permission mengubah, reorder ditolak DI SERVER.
     *
     * Filament menolak lewat isReorderable(), bukan sekadar menyembunyikan
     * tombolnya, sehingga payload yang dikirim langsung pun tidak menulis
     * apa-apa.
     */
    public function test_a_user_without_the_update_permission_cannot_reorder(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(PanelPermission::AccessAdminPanel->value);
        $user->givePermissionTo(PanelPermission::ViewProducts->value);
        $this->actingAs($user->fresh());

        $a = Product::factory()->create(['name' => 'A', 'sort_order' => 1]);
        $b = Product::factory()->create(['name' => 'B', 'sort_order' => 2]);

        Livewire::test(ListProducts::class)
            ->call('reorderTable', [(string) $b->getKey(), (string) $a->getKey()]);

        $this->assertSame(
            ['A', 'B'],
            Product::query()->orderBy('sort_order')->pluck('name')->all(),
            'Urutan berubah padahal user tidak punya izin mengubah produk.',
        );
    }

    /**
     * Reorder ditutup selama pencarian aktif: baris yang tersaring tidak
     * mewakili keseluruhan urutan, jadi hasil seretan akan menghasilkan nomor
     * yang tidak konsisten dengan data yang tidak terlihat.
     */
    public function test_reorder_is_closed_while_a_search_is_active(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $a = Product::factory()->create(['name' => 'Alfa', 'sort_order' => 1]);
        $b = Product::factory()->create(['name' => 'Beta', 'sort_order' => 2]);

        Livewire::test(ListProducts::class)
            ->searchTable('Beta')
            ->call('reorderTable', [(string) $b->getKey(), (string) $a->getKey()]);

        $this->assertSame(
            ['Alfa', 'Beta'],
            Product::query()->orderBy('sort_order')->pluck('name')->all(),
        );
    }

    // ------------------------------------------------------ tabel dan filter

    public function test_the_table_can_be_searched_and_filtered(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $menu = Product::factory()->category(ProductCategory::Menu)->create(['name' => 'Dimsum Ayam']);
        $frozen = Product::factory()
            ->category(ProductCategory::Frozen)
            ->type(ProductType::FrozenFood)
            ->inactive()
            ->create(['name' => 'Frozen Pack']);

        Livewire::test(ListProducts::class)
            ->searchTable('Dimsum')
            ->assertCanSeeTableRecords([$menu])
            ->assertCanNotSeeTableRecords([$frozen]);

        Livewire::test(ListProducts::class)
            ->filterTable('category', ProductCategory::Frozen->value)
            ->assertCanSeeTableRecords([$frozen])
            ->assertCanNotSeeTableRecords([$menu]);

        Livewire::test(ListProducts::class)
            ->filterTable('type', ProductType::FrozenFood->value)
            ->assertCanSeeTableRecords([$frozen])
            ->assertCanNotSeeTableRecords([$menu]);

        Livewire::test(ListProducts::class)
            ->filterTable('is_active', false)
            ->assertCanSeeTableRecords([$frozen])
            ->assertCanNotSeeTableRecords([$menu]);
    }

    public function test_the_homepage_filter_separates_the_two_groups(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $shown = Product::factory()->onHomepage()->create(['name' => 'Tampil']);
        $hidden = Product::factory()->create(['name' => 'Tidak Tampil']);

        Livewire::test(ListProducts::class)
            ->filterTable('show_on_homepage', true)
            ->assertCanSeeTableRecords([$shown])
            ->assertCanNotSeeTableRecords([$hidden]);
    }

    // ----------------------------------------------------------------- cache

    public function test_saving_a_product_invalidates_the_cache(): void
    {
        $product = Product::factory()->create();

        $before = ProductCatalogService::cacheVersion();
        $product->update(['name' => 'Nama Berubah']);

        $this->assertGreaterThan($before, ProductCatalogService::cacheVersion());
    }

    public function test_every_lifecycle_event_invalidates_the_cache(): void
    {
        $product = Product::factory()->create();

        foreach (['delete', 'restore', 'forceDelete'] as $action) {
            $before = ProductCatalogService::cacheVersion();

            $product->{$action}();

            $this->assertGreaterThan(
                $before,
                ProductCatalogService::cacheVersion(),
                "{$action}() tidak menaikkan versi cache.",
            );
        }
    }
}
