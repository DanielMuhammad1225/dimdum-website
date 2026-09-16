<?php

use App\Http\Controllers\BioController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LocationPageController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

/*
| Katalog produk. Satu halaman daftar saja -- produk TIDAK punya slug maupun
| halaman detail pada fase ini, jadi tidak ada URL per produk yang perlu
| dijaga keunikannya atau dialihkan saat namanya berubah.
*/
Route::get('/produk', ProductController::class)->name('products.index');

/*
| URL publik lokasi:
|
|   /alamat/{slug}   -> satu Halaman Slug Lokasi
|
| Slug dimiliki HANYA oleh LocationPage. Provinsi, Kota/Grup, Area, dan
| Gerobak adalah master data internal dan tidak punya URL sendiri.
|
| Route hierarki lama (/lokasi, /lokasi/{province}, /lokasi/{province}/{area})
| DIHAPUS, bukan dialihkan: keempat tabelnya tidak pernah berisi data nyata dan
| tidak ada satu pun URL yang sudah terbit, sehingga redirect apa pun hanya
| akan mengarang tujuan.
|
| Slug diterima sebagai STRING biasa, bukan route-model binding, supaya
| controller sempat memeriksa tabel redirect sebelum memutuskan 404. Binding
| implisit akan langsung 404 dan mematikan URL lama yang masih beredar.
|
| Pola slug dibatasi huruf kecil, angka, dan tanda hubung -- nama view, path,
| dan karakter aneh tidak pernah sampai ke controller.
|
| Route detail gerobak SENGAJA tidak dibuat.
*/
$slug = '[a-z0-9]+(?:-[a-z0-9]+)*';

Route::get('/alamat/{slug}', [LocationPageController::class, 'show'])
    ->where('slug', $slug)
    ->name('location-pages.show');

Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');

/*
| Halaman Bio -- tautan yang dipasang di profil social media.
|
| Nama route sengaja STABIL: tombol Lokasi dan Menu di /bio menaut lewat nama
| ini, bukan lewat URL yang disimpan di database. Mengganti mode sumber lokasi
| tidak mengganti URL /bio/lokasi.
|
| Ketiganya noindex dan TIDAK masuk sitemap.
*/
Route::prefix('bio')->name('bio.')->group(function (): void {
    Route::get('/', [BioController::class, 'home'])->name('home');
    Route::get('/lokasi', [BioController::class, 'locations'])->name('locations');
    Route::get('/produk', [BioController::class, 'products'])->name('products');
});
