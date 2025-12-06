<?php

use App\Http\Controllers\CompanyController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\WebsiteController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('companies', CompanyController::class);

    // Website routes
    Route::post('companies/{company}/websites', [WebsiteController::class, 'store'])->name('companies.websites.store');
    Route::put('websites/{website}', [WebsiteController::class, 'update'])->name('websites.update');
    Route::delete('websites/{website}', [WebsiteController::class, 'destroy'])->name('websites.destroy');
    Route::post('websites/{website}/discover', [WebsiteController::class, 'discover'])->name('websites.discover');
    Route::get('websites/{website}/discovery-status', [WebsiteController::class, 'discoveryStatus'])->name('websites.discovery-status');

    // Document routes
    Route::post('websites/{website}/documents', [DocumentController::class, 'store'])->name('websites.documents.store');
    Route::put('documents/{document}', [DocumentController::class, 'update'])->name('documents.update');
    Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
    Route::post('documents/{document}/scrape', [DocumentController::class, 'scrape'])->name('documents.scrape');
    Route::get('documents/{document}/scrape-status', [DocumentController::class, 'scrapeStatus'])->name('documents.scrape-status');
    Route::post('websites/{website}/documents/from-discovery', [DocumentController::class, 'createFromDiscovery'])->name('websites.documents.from-discovery');

    // Product routes
    Route::post('companies/{company}/products', [ProductController::class, 'store'])->name('companies.products.store');
    Route::put('products/{product}', [ProductController::class, 'update'])->name('products.update');
    Route::delete('products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
    Route::post('products/{product}/documents', [ProductController::class, 'attachDocuments'])->name('products.documents.attach');
    Route::delete('products/{product}/documents/{document}', [ProductController::class, 'detachDocument'])->name('products.documents.detach');
    Route::post('products/{product}/documents/{document}/primary', [ProductController::class, 'setPrimaryDocument'])->name('products.documents.primary');
});

require __DIR__.'/settings.php';
