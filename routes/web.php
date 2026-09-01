<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::get('/lokasi', [LocationController::class, 'index'])->name('locations.index');

/*
| Slug diterima sebagai STRING biasa, bukan route-model binding, supaya
| controller sempat memeriksa tabel redirect sebelum memutuskan 404.
| Binding implisit akan langsung 404 dan mematikan URL lama yang masih
| beredar di iklan.
|
| Pola slug dibatasi huruf kecil, angka, dan tanda hubung -- nama view,
| path, dan karakter aneh tidak pernah sampai ke controller.
|
| Route detail gerobak (/lokasi/{area}/{location}) SENGAJA belum didaftarkan
| pada fase ini, meskipun slug gerobak sudah tersimpan di database.
*/
Route::get('/lokasi/{area}', [LocationController::class, 'area'])
    ->where('area', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->name('locations.area');

Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');
