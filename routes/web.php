<?php

use App\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// SEO: dynamic sitemap of public pages + active business profiles.
Route::get('sitemap.xml', SitemapController::class)->name('sitemap');
