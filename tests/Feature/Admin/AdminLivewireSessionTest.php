<?php

namespace Tests\Feature\Admin;

use App\Enums\BioLocationMode;
use App\Enums\ProductCategory;
use App\Enums\ProductType;
use App\Enums\SocialIcon;
use App\Enums\UserRole;
use App\Models\BioSetting;
use App\Models\Location;
use App\Models\LocationArea;
use App\Models\LocationGroup;
use App\Models\LocationPage;
use App\Models\Product;
use App\Models\Province;
use App\Models\SocialLink;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Tests\TestCase;

/**
 * Session admin harus bertahan melewati request update Livewire.
 *
 * KENAPA TEST INI PERLU JALUR HTTP PENUH, BUKAN Livewire::test().
 *
 * Bug yang ditutup di sini hidup di middleware "persistent" Livewire.
 * Livewire hanya menjalankan middleware persistent bila request benar-benar
 * mengenai endpoint update-nya -- lihat PersistentMiddleware::boot(), yang
 * secara eksplisit melewati "any fake requests such as a test". Artinya
 * Livewire::test() TIDAK PERNAH melewati jalur itu, dan bug ini tidak mungkin
 * terlihat dari sana.
 *
 * Dua hal lain yang juga wajib nyata di sini:
 *
 *  1. DRIVER SESSION. phpunit.xml menyetel SESSION_DRIVER=array, sehingga
 *     session hidup di memori satu request dan seluruh persoalan persistensi
 *     tidak terlihat. Test ini memaksa driver `database` seperti aplikasi.
 *
 *  2. COOKIE. Session hanya berpindah antar request lewat cookie, jadi cookie
 *     response disimpan dan dikirim ulang pada request berikutnya -- persis
 *     seperti browser. Tanpa ini, setiap request memulai session baru dan
 *     test akan lulus meskipun aplikasinya rusak.
 *
 * Login pun dilakukan sungguhan lewat komponen login Filament, bukan
 * actingAs(): actingAs menyetel user langsung pada guard, sehingga auth tetap
 * "true" walaupun session-nya sudah hilang -- justru menyembunyikan bug ini.
 */
class AdminLivewireSessionTest extends TestCase
{
    use RefreshDatabase;

    protected const PASSWORD = 'kata-sandi-uji-yang-panjang';

    /** @var array<string, string> nilai cookie polos, seperti isi cookie jar browser */
    protected array $cookieJar = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    // ---------------------------------------------------------------- helpers

    protected function superAdmin(): User
    {
        $user = User::factory()->create([
            'password' => Hash::make(self::PASSWORD),
            'is_active' => true,
        ]);

        $user->assignRole(UserRole::SuperAdmin->value);

        return $user;
    }

    /**
     * Simpan cookie response ke dalam jar dalam bentuk polos.
     *
     * Test client Laravel mengenkripsi ulang isi jar saat mengirim, jadi yang
     * disimpan di sini harus nilai yang sudah didekripsi.
     */
    protected function rememberCookies(TestResponse $response): void
    {
        foreach ($response->baseResponse->headers->getCookies() as $cookie) {
            $name = $cookie->getName();
            $value = $cookie->getValue();

            if ($value === '' || $value === null) {
                unset($this->cookieJar[$name]);

                continue;
            }

            try {
                $this->cookieJar[$name] = CookieValuePrefix::remove(decrypt($value, false));
            } catch (\Throwable) {
                $this->cookieJar[$name] = $value;
            }
        }
    }

    protected function browserGet(string $uri): TestResponse
    {
        $response = $this->withCookies($this->cookieJar)->get($uri);

        $this->rememberCookies($response);

        return $response;
    }

    protected function sessionId(): ?string
    {
        return $this->cookieJar[config('session.cookie')] ?? null;
    }

    protected function updateUri(): string
    {
        return app(HandleRequests::class)->getUpdateUri();
    }

    /**
     * Header request Livewire.
     *
     * Harus lewat server var: call() tidak menggabungkan header dari
     * withHeaders(), dan tanpa X-Livewire route update dijawab 404 oleh
     * middleware RequireLivewireHeaders.
     *
     * @return array<string, string>
     */
    protected function livewireServerVars(): array
    {
        return $this->transformHeadersToServerVars([
            'X-Livewire' => '1',
            'Content-Type' => 'application/json',
            'Accept' => 'text/html, application/xhtml+xml',
        ]);
    }

    protected static function snapshotFrom(string $html, string $componentContains): ?string
    {
        if (! preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches)) {
            return null;
        }

        foreach ($matches[1] as $encoded) {
            $json = html_entity_decode($encoded, ENT_QUOTES, 'UTF-8');

            if (str_contains($json, $componentContains)) {
                return $json;
            }
        }

        return null;
    }

    protected static function csrfFrom(string $html): ?string
    {
        if (preg_match('/<meta name="csrf-token" content="([^"]+)"/', $html, $m)) {
            return $m[1];
        }

        return preg_match('/"csrf"\s*:\s*"([^"]+)"/', $html, $m) ? $m[1] : null;
    }

    /**
     * Kirim satu request update Livewire yang sebenarnya, lengkap dengan
     * cookie dan token CSRF, ke URI update yang benar-benar terdaftar.
     *
     * $origin kosong berarti host bawaan aplikasi; isi dengan host lain untuk
     * meniru panel yang dibuka lewat host berbeda dari APP_URL.
     *
     * @param  array<string, mixed>  $updates
     * @param  array<int, array<string, mixed>>  $calls
     */
    protected function livewireUpdate(string $pageHtml, string $component, array $updates, array $calls, string $origin = ''): TestResponse
    {
        $snapshot = static::snapshotFrom($pageHtml, $component);

        $this->assertNotNull($snapshot, "Snapshot Livewire untuk {$component} tidak ditemukan.");

        $payload = [
            '_token' => static::csrfFrom($pageHtml),
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => (object) $updates,
                'calls' => $calls,
            ]],
        ];

        $response = $this->withCookies($this->cookieJar)->call(
            'POST',
            $origin.$this->updateUri(),
            [],
            $this->prepareCookiesForRequest(),
            [],
            $this->livewireServerVars(),
            json_encode($payload),
        );

        $this->rememberCookies($response);

        return $response;
    }

    /** Login sungguhan melalui komponen login Filament. */
    protected function logIn(User $user): void
    {
        $page = $this->browserGet('/admin/login');
        $page->assertOk();

        $response = $this->livewireUpdate($page->getContent(), 'Login', [
            'data.email' => $user->email,
            'data.password' => self::PASSWORD,
            'data.remember' => false,
        ], [['path' => '', 'method' => 'authenticate', 'params' => []]]);

        $response->assertOk();

        $this->assertNotNull($this->sessionId(), 'Login tidak menghasilkan cookie session.');
    }

    protected function assertStillSignedIn(string $context): void
    {
        $response = $this->browserGet('/admin/provinsi');

        $this->assertSame(
            200,
            $response->getStatusCode(),
            "{$context}: /admin/provinsi menjawab {$response->getStatusCode()}"
            .($response->headers->get('Location') ? ' menuju '.$response->headers->get('Location') : '')
            .' -- pengguna terlempar keluar.'
        );
    }

    // ------------------------------------------------------------------ tests

    public function test_a_livewire_request_keeps_the_very_same_session(): void
    {
        $this->logIn($this->superAdmin());

        $page = $this->browserGet('/admin/provinsi/create');
        $page->assertOk();

        $before = $this->sessionId();

        // Hanya mengisi field, tanpa memanggil aksi apa pun. Ini pun cukup
        // untuk memicu bug: penyebabnya ada di middleware, bukan di CRUD.
        $this->livewireUpdate($page->getContent(), 'CreateProvince', [
            'data.name' => 'Sekadar mengetik',
        ], [])->assertOk();

        $this->assertSame(
            $before,
            $this->sessionId(),
            'Request Livewire menukar session pengguna dengan session lain.'
        );

        $this->assertStillSignedIn('Setelah request Livewire biasa');
    }

    public function test_creating_a_province_keeps_the_user_signed_in(): void
    {
        $this->logIn($this->superAdmin());

        $page = $this->browserGet('/admin/provinsi/create');
        $before = $this->sessionId();

        $this->livewireUpdate($page->getContent(), 'CreateProvince', [
            'data.name' => 'Jawa Barat',
            'data.is_active' => true,
        ], [['path' => '', 'method' => 'create', 'params' => []]])->assertOk();

        $this->assertSame($before, $this->sessionId(), 'Session berganti setelah membuat provinsi.');
        $this->assertDatabaseHas('provinces', ['name' => 'Jawa Barat']);
        $this->assertStillSignedIn('Setelah create');
    }

    public function test_updating_a_province_keeps_the_user_signed_in(): void
    {
        $this->logIn($this->superAdmin());

        $province = Province::factory()->create(['name' => 'Nama Lama']);

        $page = $this->browserGet("/admin/provinsi/{$province->getKey()}/edit");
        $page->assertOk();
        $before = $this->sessionId();

        $this->livewireUpdate($page->getContent(), 'EditProvince', [
            'data.name' => 'Nama Baru',
        ], [['path' => '', 'method' => 'save', 'params' => []]])->assertOk();

        $this->assertSame($before, $this->sessionId(), 'Session berganti setelah menyimpan perubahan.');
        $this->assertSame('Nama Baru', $province->fresh()->name);
        $this->assertStillSignedIn('Setelah update');
    }

    public function test_failed_validation_keeps_the_user_signed_in(): void
    {
        $this->logIn($this->superAdmin());

        $page = $this->browserGet('/admin/provinsi/create');
        $before = $this->sessionId();

        $this->livewireUpdate($page->getContent(), 'CreateProvince', [
            'data.name' => '',
        ], [['path' => '', 'method' => 'create', 'params' => []]])->assertOk();

        $this->assertSame($before, $this->sessionId(), 'Session berganti saat validasi gagal.');
        $this->assertSame(0, Province::query()->count());
        $this->assertStillSignedIn('Setelah validasi gagal');
    }

    public function test_deleting_a_province_keeps_the_user_signed_in(): void
    {
        $this->logIn($this->superAdmin());

        $province = Province::factory()->create();

        $page = $this->browserGet("/admin/provinsi/{$province->getKey()}/edit");
        $before = $this->sessionId();

        $this->livewireUpdate($page->getContent(), 'EditProvince', [], [
            ['path' => '', 'method' => 'mountAction', 'params' => ['delete']],
        ])->assertOk();

        $this->assertSame($before, $this->sessionId(), 'Session berganti saat membuka aksi hapus.');
        $this->assertStillSignedIn('Setelah aksi hapus');
    }

    /**
     * Tombol "Batal" tidak boleh mengirim apa pun.
     *
     * Pada laporan bug, Batal ikut mendarat di halaman login. Penyebabnya
     * bukan tombolnya: Batal hanya memanggil history.back() di browser, dan
     * session sudah hilang lebih dulu karena request Livewire sebelumnya.
     * Test ini mengunci sifat tombolnya supaya tidak berubah diam-diam.
     */
    public function test_the_cancel_button_only_navigates_back(): void
    {
        $this->logIn($this->superAdmin());

        $html = $this->browserGet('/admin/provinsi/create')->getContent();

        $this->assertStringContainsString('Batal', $html);

        $position = strpos($html, 'Batal');
        $button = substr($html, max(0, $position - 800), 800);

        $this->assertStringContainsString('type="button"', $button, 'Batal seharusnya bukan tombol submit.');
        $this->assertStringContainsString('window.history.back()', $button, 'Batal seharusnya sekadar kembali.');
        $this->assertStringNotContainsString('wire:click', $button, 'Batal tidak boleh memanggil aksi Livewire.');
        $this->assertStringNotContainsString('/admin/login', $button, 'Batal tidak boleh mengarah ke halaman login.');
    }

    /**
     * URL yang dihasilkan panel harus mengikuti host request.
     *
     * Kalau sebuah URL memakai host lain (mis. APP_URL localhost padahal
     * pengguna membuka 127.0.0.1), browser berpindah origin, cookie session
     * tidak ikut terkirim, dan hasilnya terlihat persis seperti logout.
     */
    public function test_generated_admin_urls_follow_the_request_host(): void
    {
        $this->logIn($this->superAdmin());

        $page = $this->browserGet('/admin/provinsi/create');

        $response = $this->livewireUpdate($page->getContent(), 'CreateProvince', [
            'data.name' => 'Jawa Barat',
            'data.is_active' => true,
        ], [['path' => '', 'method' => 'create', 'params' => []]]);

        $redirect = $response->json('components.0.effects.redirect');

        $this->assertIsString($redirect, 'Create tidak menghasilkan redirect.');

        // Origin acuan diambil dari halaman itu sendiri, bukan dari konstanta:
        // yang menentukan cookie ikut terkirim adalah kesamaan origin antara
        // halaman dan tujuan redirect-nya.
        $this->assertSame(
            1,
            preg_match('#(https?://[^/"]+)/admin#', $page->getContent(), $m),
            'Tidak ada URL admin absolut pada halaman untuk dijadikan acuan origin.',
        );

        $origin = $m[1];

        $this->assertStringStartsWith(
            $origin.'/admin/',
            $redirect,
            "Redirect setelah create ({$redirect}) berpindah origin dari halaman ({$origin}). "
            .'Cookie session tidak akan ikut terkirim dan pengguna akan terlihat logout.',
        );
    }

    /**
     * CSRF harus tetap menolak request tanpa token.
     *
     * PreventRequestForgery melewati pemeriksaan saat runningUnitTests(),
     * sehingga di PHPUnit token tidak pernah divalidasi. Environment
     * dikembalikan ke `local` khusus di test ini supaya penegakan yang
     * sesungguhnya ikut teruji, bukan dilewati.
     */
    public function test_a_livewire_request_without_a_csrf_token_is_rejected(): void
    {
        $this->logIn($this->superAdmin());

        $page = $this->browserGet('/admin/provinsi/create');
        $snapshot = static::snapshotFrom($page->getContent(), 'CreateProvince');

        $this->app['env'] = 'local';

        try {
            $response = $this->withCookies($this->cookieJar)->call(
                'POST',
                $this->updateUri(),
                [],
                $this->prepareCookiesForRequest(),
                [],
                $this->livewireServerVars(),
                json_encode([
                    'components' => [[
                        'snapshot' => $snapshot,
                        'updates' => (object) [],
                        'calls' => [['path' => '', 'method' => 'create', 'params' => []]],
                    ]],
                ]),
            );
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertSame(419, $response->getStatusCode(), 'Perlindungan CSRF tidak lagi aktif.');
        $this->assertSame(0, Province::query()->count());
    }

    public function test_an_inactive_account_cannot_reach_the_panel(): void
    {
        $user = $this->superAdmin();

        $this->logIn($user);
        $this->assertStillSignedIn('Sebelum dinonaktifkan');

        $user->forceFill(['is_active' => false])->save();

        // Guard menyimpan instance user-nya, dan container test dipakai ulang
        // antar request. Tanpa ini, request berikutnya masih memakai user
        // versi lama dan test lolos padahal tidak menguji apa pun.
        $this->app['auth']->forgetGuards();

        $this->browserGet('/admin/provinsi')->assertForbidden();
    }

    /**
     * Modul Halaman Slug Lokasi menempuh alur Livewire terpanjang di panel:
     * mengetik, memilih Kota/Grup, memuat kandidat gerobak, lalu menyimpan.
     * Seluruhnya harus berjalan di atas SATU session.
     */
    public function test_the_location_page_form_keeps_the_session_through_its_whole_flow(): void
    {
        $this->logIn($this->superAdmin());

        $group = LocationGroup::factory()->create();
        $area = LocationArea::factory()->for($group, 'group')->create();
        $location = Location::factory()->for($area, 'area')->create();

        $page = $this->browserGet('/admin/halaman-lokasi/create');
        $page->assertOk();

        $before = $this->sessionId();

        // 1. Mengetik judul (sinkronisasi field, tanpa memanggil aksi).
        $this->livewireUpdate($page->getContent(), 'CreateLocationPage', [
            'data.title' => 'Alamat Uji Session',
        ], [])->assertOk();

        $this->assertSame($before, $this->sessionId(), 'Session berganti saat mengetik judul.');

        // 2. Memilih Kota/Grup -- memicu pemuatan kandidat gerobak.
        $this->livewireUpdate($page->getContent(), 'CreateLocationPage', [
            'data.group_ids' => [$group->getKey()],
        ], [])->assertOk();

        $this->assertSame($before, $this->sessionId(), 'Session berganti saat memilih Kota/Grup.');

        // 3. Menyimpan halaman.
        $this->livewireUpdate($page->getContent(), 'CreateLocationPage', [
            'data.title' => 'Alamat Uji Session',
            'data.slug' => 'alamat-uji-session',
            'data.group_ids' => [$group->getKey()],
            'data.location_ids' => [$location->getKey()],
        ], [['path' => '', 'method' => 'create', 'params' => []]])->assertOk();

        $this->assertSame($before, $this->sessionId(), 'Session berganti setelah menyimpan halaman.');
        $this->assertDatabaseHas('location_pages', ['slug' => 'alamat-uji-session']);

        // 4. Navigasi setelahnya tetap sebagai pengguna yang sama.
        $this->browserGet('/admin/halaman-lokasi')->assertOk();
        $this->assertSame($before, $this->sessionId());
    }

    public function test_reordering_location_pages_keeps_the_session(): void
    {
        $this->logIn($this->superAdmin());

        $first = LocationPage::factory()->create(['sort_order' => 1]);
        $second = LocationPage::factory()->create(['sort_order' => 2]);

        $list = $this->browserGet('/admin/halaman-lokasi');
        $list->assertOk();

        $before = $this->sessionId();

        $this->livewireUpdate($list->getContent(), 'ListLocationPages', [], [
            ['path' => '', 'method' => 'reorderTable', 'params' => [
                [(string) $second->getKey(), (string) $first->getKey()],
            ]],
        ])->assertOk();

        $this->assertSame($before, $this->sessionId(), 'Session berganti setelah menata urutan.');
        $this->assertSame(1, (int) $second->fresh()->sort_order);
        $this->assertStillSignedIn('Setelah reorder');
    }

    // ------------------------------------------------------------- produk

    public function test_creating_a_product_keeps_the_user_signed_in(): void
    {
        $this->logIn($this->superAdmin());

        $page = $this->browserGet('/admin/produk/create');
        $page->assertOk();

        $before = $this->sessionId();

        $this->livewireUpdate($page->getContent(), 'CreateProduct', [
            'data.name' => 'Dimsum Uji Session',
            'data.category' => ProductCategory::Menu->value,
            'data.type' => ProductType::Satuan->value,
        ], [['path' => '', 'method' => 'create', 'params' => []]])->assertOk();

        $this->assertSame($before, $this->sessionId(), 'Session berganti setelah membuat produk.');
        $this->assertDatabaseHas('products', ['name' => 'Dimsum Uji Session']);
        $this->assertStillSignedIn('Setelah create produk');
    }

    public function test_updating_a_product_keeps_the_user_signed_in(): void
    {
        $this->logIn($this->superAdmin());

        $product = Product::factory()->create(['name' => 'Nama Lama']);

        $page = $this->browserGet("/admin/produk/{$product->getKey()}/edit");
        $page->assertOk();

        $before = $this->sessionId();

        $this->livewireUpdate($page->getContent(), 'EditProduct', [
            'data.name' => 'Nama Baru',
        ], [['path' => '', 'method' => 'save', 'params' => []]])->assertOk();

        $this->assertSame($before, $this->sessionId(), 'Session berganti setelah menyimpan produk.');
        $this->assertSame('Nama Baru', $product->fresh()->name);
        $this->assertStillSignedIn('Setelah update produk');
    }

    /**
     * Membuka Edit produk berfoto lewat host yang BERBEDA dari URL disk --
     * kondisi development: APP_URL localhost:8000, panel dibuka lewat
     * dimdum_website.test. FilePond harus menerima URL foto satu origin
     * dengan halaman, dan memuat serta menyimpan tidak boleh mengganti
     * session.
     */
    public function test_editing_a_product_image_from_another_host_keeps_session_and_origin(): void
    {
        Storage::fake('public', ['url' => 'http://app-url.test:8000/storage']);

        $path = Storage::disk('public')->putFileAs(
            'products',
            UploadedFile::fake()->image('foto.jpg', 300, 300),
            '01TESTFOTOPRODUK.jpg',
        );

        $this->logIn($this->superAdmin());

        $product = Product::factory()->create(['name' => 'Berfoto', 'image_path' => $path]);

        $origin = 'http://dimdum-admin.test';

        $page = $this->browserGet("{$origin}/admin/produk/{$product->getKey()}/edit");
        $page->assertOk();

        $before = $this->sessionId();

        // 1. Panggilan yang dipakai FilePond untuk memuat foto lama.
        $files = $this->livewireUpdate($page->getContent(), 'EditProduct', [], [
            ['path' => '', 'method' => 'callSchemaComponentMethod', 'params' => ['form.image_path', 'getUploadedFiles']],
        ], $origin)->assertOk()->json('components.0.effects.returns.0');

        $urls = array_column(array_filter((array) $files), 'url');

        $this->assertSame(["{$origin}/storage/{$path}"], $urls, 'URL foto tidak satu origin dengan halaman admin.');
        $this->assertSame($before, $this->sessionId(), 'Session berganti saat memuat foto produk.');

        // 2. Menyimpan tanpa mengganti foto.
        $this->livewireUpdate($page->getContent(), 'EditProduct', [
            'data.name' => 'Berfoto Diubah',
        ], [['path' => '', 'method' => 'save', 'params' => []]], $origin)->assertOk();

        $this->assertSame($before, $this->sessionId(), 'Session berganti setelah menyimpan produk berfoto.');
        $this->assertSame('Berfoto Diubah', $product->fresh()->name);
        $this->assertSame($path, $product->fresh()->image_path);
        Storage::disk('public')->assertExists($path);
        $this->assertStillSignedIn('Setelah menyimpan produk berfoto');
    }

    public function test_reordering_products_keeps_the_session(): void
    {
        $this->logIn($this->superAdmin());

        $first = Product::factory()->create(['sort_order' => 1]);
        $second = Product::factory()->create(['sort_order' => 2]);

        $list = $this->browserGet('/admin/produk');
        $list->assertOk();

        $before = $this->sessionId();

        $this->livewireUpdate($list->getContent(), 'ListProducts', [], [
            ['path' => '', 'method' => 'reorderTable', 'params' => [
                [(string) $second->getKey(), (string) $first->getKey()],
            ]],
        ])->assertOk();

        $this->assertSame($before, $this->sessionId(), 'Session berganti setelah menata urutan produk.');
        $this->assertSame(1, (int) $second->fresh()->sort_order);
        $this->assertStillSignedIn('Setelah reorder produk');
    }

    // ---------------------------------------------------------------- bio

    public function test_saving_the_bio_settings_keeps_the_user_signed_in(): void
    {
        $this->logIn($this->superAdmin());

        $page = $this->browserGet('/admin/pengaturan-bio');
        $page->assertOk();

        $before = $this->sessionId();

        $this->livewireUpdate($page->getContent(), 'ManageBioSettings', [
            'data.is_active' => true,
            'data.title' => 'Bio Uji Session',
            'data.location_mode' => BioLocationMode::AllActiveLocations->value,
        ], [['path' => '', 'method' => 'save', 'params' => []]])->assertOk();

        $this->assertSame($before, $this->sessionId(), 'Session berganti setelah menyimpan Pengaturan Bio.');
        $this->assertSame('Bio Uji Session', BioSetting::query()->value('title'));
        $this->assertStillSignedIn('Setelah simpan Pengaturan Bio');
    }

    public function test_creating_a_social_link_keeps_the_user_signed_in(): void
    {
        $this->logIn($this->superAdmin());

        $page = $this->browserGet('/admin/social-media/create');
        $page->assertOk();

        $before = $this->sessionId();

        $this->livewireUpdate($page->getContent(), 'CreateSocialLink', [
            'data.name' => 'Instagram Uji Session',
            'data.url' => 'https://www.instagram.test/dimdum',
            'data.icon' => SocialIcon::Instagram->value,
        ], [['path' => '', 'method' => 'create', 'params' => []]])->assertOk();

        $this->assertSame($before, $this->sessionId(), 'Session berganti setelah membuat Social Media.');
        $this->assertDatabaseHas('social_links', ['name' => 'Instagram Uji Session']);
        $this->assertStillSignedIn('Setelah create Social Media');
    }

    public function test_reordering_social_links_keeps_the_session(): void
    {
        $this->logIn($this->superAdmin());

        $first = SocialLink::factory()->create(['sort_order' => 1]);
        $second = SocialLink::factory()->create(['sort_order' => 2]);

        $list = $this->browserGet('/admin/social-media');
        $list->assertOk();

        $before = $this->sessionId();

        $this->livewireUpdate($list->getContent(), 'ListSocialLinks', [], [
            ['path' => '', 'method' => 'reorderTable', 'params' => [
                [(string) $second->getKey(), (string) $first->getKey()],
            ]],
        ])->assertOk();

        $this->assertSame($before, $this->sessionId(), 'Session berganti setelah menata urutan Social Media.');
        $this->assertSame(1, (int) $second->fresh()->sort_order);
        $this->assertStillSignedIn('Setelah reorder Social Media');
    }

    /**
     * Penjaga struktural terhadap penyebab bug ini.
     *
     * Middleware cookie/session TIDAK BOLEH ditandai persistent. Livewire
     * menjalankan middleware persistent sekali lagi di tengah request update,
     * di atas duplikat request yang cookie-nya sudah didekripsi. EncryptCookies
     * kedua gagal mendekripsi nilai polos itu lalu membuang cookie session,
     * dan StartSession kedua menerima id kosong sehingga membuat session baru.
     * Session pengguna hilang pada setiap interaksi panel.
     */
    public function test_cookie_and_session_middleware_are_never_persistent(): void
    {
        $persistent = Livewire::getPersistentMiddleware();

        foreach ([EncryptCookies::class, StartSession::class] as $middleware) {
            $this->assertNotContains(
                $middleware,
                $persistent,
                $middleware.' ditandai persistent. Livewire akan menjalankannya dua kali '
                .'dalam satu request update dan session pengguna akan hilang.'
            );
        }
    }
}
