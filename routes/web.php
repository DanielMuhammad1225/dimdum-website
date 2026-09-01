<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

/*
| Hierarki URL lokasi:
|
|   /lokasi                       -> daftar provinsi
|   /lokasi/{province}            -> Kota/Grup sebagai heading + kartu Area
|   /lokasi/{province}/{area}     -> landing Area + daftar gerobak
|
| Kota/Grup SENGAJA tidak punya URL sendiri: ia hanya pengelompokan di
| halaman provinsi, sehingga tidak ada halaman tipis yang bersaing di
| pencarian dengan halaman Area.
|
| Slug diterima sebagai STRING biasa, bukan route-model binding, supaya
| controller sempat memeriksa tabel redirect sebelum memutuskan 404.
| Binding implisit akan langsung 404 dan mematikan URL lama yang masih
| beredar di iklan.
|
| Pola slug dibatasi huruf kecil, angka, dan tanda hubung -- nama view,
| path, dan karakter aneh tidak pernah sampai ke controller.
|
| Route detail gerobak (/lokasi/{province}/{area}/{location}) SENGAJA belum
| didaftarkan pada fase ini, meskipun slug gerobak sudah tersimpan dan unik
| di dalam areanya.
*/
$slug = '[a-z0-9]+(?:-[a-z0-9]+)*';

Route::get('/lokasi', [LocationController::class, 'index'])->name('locations.index');

Route::get('/lokasi/{province}', [LocationController::class, 'province'])
    ->where('province', $slug)
    ->name('locations.province');

Route::get('/lokasi/{province}/{area}', [LocationController::class, 'area'])
    ->where(['province' => $slug, 'area' => $slug])
    ->name('locations.area');

Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');
