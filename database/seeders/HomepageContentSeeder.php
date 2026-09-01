<?php

namespace Database\Seeders;

use App\Models\HomepageSetting;
use App\Models\SiteSetting;
use App\Services\HomepageContentService;
use App\Services\SiteSettingsService;
use Illuminate\Database\Seeder;

/**
 * Bootstrap konten homepage dari config ke database.
 *
 * Sifat seeder ini:
 *   - IDEMPOTEN. Dijalankan berapa kali pun hasilnya sama.
 *   - ADITIF. Hanya membuat baris singleton bila belum ada.
 *   - TIDAK MENIMPA. Perubahan yang sudah dilakukan admin lewat panel tidak
 *     pernah dikembalikan ke nilai config.
 *   - Tidak membuat user, tidak menyentuh role/permission, tidak menghapus apa pun.
 *
 * Setelah seeder ini dijalankan, homepage publik harus tampil identik dengan
 * sebelum CMS ada, karena nilai awalnya memang dibaca dari config yang sama.
 */
class HomepageContentSeeder extends Seeder
{
    public function run(): void
    {
        /*
         | firstOrCreate: kolom 'key' yang unik dipakai sebagai penanda
         | singleton. Bila barisnya sudah ada, atribut di bawah DIABAIKAN --
         | inilah yang membuat seeder tidak menimpa pekerjaan admin.
         */
        SiteSetting::query()->firstOrCreate(
            ['key' => SiteSetting::SINGLETON_KEY],
            SiteSettingsService::defaultsFromConfig(),
        );

        HomepageSetting::query()->firstOrCreate(
            ['key' => HomepageSetting::SINGLETON_KEY],
            HomepageContentService::defaultsFromConfig(),
        );
    }
}
