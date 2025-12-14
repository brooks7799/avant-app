<?php

use App\Http\Controllers\CompanyController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\QueueController;
use App\Http\Controllers\VersionComparisonController;
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
    Route::get('companies/{company}/policy/{index}', [CompanyController::class, 'showPolicy'])->name('companies.policy.show');

    // Website routes
    Route::post('companies/{company}/websites', [WebsiteController::class, 'store'])->name('companies.websites.store');
    Route::put('websites/{website}', [WebsiteController::class, 'update'])->name('websites.update');
    Route::delete('websites/{website}', [WebsiteController::class, 'destroy'])->name('websites.destroy');
    Route::post('websites/{website}/discover', [WebsiteController::class, 'discover'])->name('websites.discover');
    Route::get('websites/{website}/discovery-status', [WebsiteController::class, 'discoveryStatus'])->name('websites.discovery-status');
    Route::post('websites/{website}/analyze-all', [WebsiteController::class, 'analyzeAll'])->name('websites.analyze-all');

    // Document routes
    Route::get('documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
    Route::post('websites/{website}/documents', [DocumentController::class, 'store'])->name('websites.documents.store');
    Route::put('documents/{document}', [DocumentController::class, 'update'])->name('documents.update');
    Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
    Route::post('documents/{document}/scrape', [DocumentController::class, 'scrape'])->name('documents.scrape');
    Route::get('documents/{document}/scrape-status', [DocumentController::class, 'scrapeStatus'])->name('documents.scrape-status');
    Route::post('documents/{document}/extract-metadata', [DocumentController::class, 'extractMetadata'])->name('documents.extract-metadata');
    Route::post('documents/{document}/analyze', [DocumentController::class, 'analyze'])->name('documents.analyze');
    Route::get('documents/{document}/analysis-status', [DocumentController::class, 'analysisStatus'])->name('documents.analysis-status');
    Route::post('websites/{website}/documents/from-discovery', [DocumentController::class, 'createFromDiscovery'])->name('websites.documents.from-discovery');

    // Product routes
    Route::post('companies/{company}/products', [ProductController::class, 'store'])->name('companies.products.store');
    Route::put('products/{product}', [ProductController::class, 'update'])->name('products.update');
    Route::delete('products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
    Route::post('products/{product}/documents', [ProductController::class, 'attachDocuments'])->name('products.documents.attach');
    Route::delete('products/{product}/documents/{document}', [ProductController::class, 'detachDocument'])->name('products.documents.detach');
    Route::post('products/{product}/documents/{document}/primary', [ProductController::class, 'setPrimaryDocument'])->name('products.documents.primary');

    // Queue management routes
    Route::get('queue', [QueueController::class, 'index'])->name('queue.index');
    Route::get('queue/status', [QueueController::class, 'status'])->name('queue.status');
    Route::post('queue/process-all', [QueueController::class, 'processAll'])->name('queue.process-all');
    Route::post('queue/discover-all', [QueueController::class, 'discoverAll'])->name('queue.discover-all');
    Route::post('queue/retry-failed', [QueueController::class, 'retryFailed'])->name('queue.retry-failed');
    Route::post('queue/flush-failed', [QueueController::class, 'flushFailed'])->name('queue.flush-failed');
    Route::post('queue/clear-pending', [QueueController::class, 'clearPending'])->name('queue.clear-pending');
    Route::get('queue/failed-jobs', [QueueController::class, 'failedJobs'])->name('queue.failed-jobs');
    Route::post('queue/retry/{uuid}', [QueueController::class, 'retryJob'])->name('queue.retry-job');
    Route::delete('queue/failed/{uuid}', [QueueController::class, 'deleteFailedJob'])->name('queue.delete-failed-job');
    Route::post('queue/scrape/{document}', [QueueController::class, 'scrapeDocument'])->name('queue.scrape-document');
    Route::post('queue/discover/{website}', [QueueController::class, 'discoverWebsite'])->name('queue.discover-website');

    // Job detail routes
    Route::get('queue/discovery/{discoveryJob}', [QueueController::class, 'showDiscoveryJob'])->name('queue.discovery.show');
    Route::get('queue/discovery/{discoveryJob}/status', [QueueController::class, 'discoveryJobStatus'])->name('queue.discovery.status');
    Route::get('queue/discovery/{discoveryJob}/policy/{index}', [QueueController::class, 'showDiscoveredPolicy'])->name('queue.discovery.policy');
    Route::post('queue/discovery/{discoveryJob}/policy/{index}/retrieve', [QueueController::class, 'retrieveDiscoveredPolicy'])->name('queue.discovery.policy.retrieve');
    Route::post('queue/discovery/{discoveryJob}/retrieve-all', [QueueController::class, 'retrieveAllDiscoveredPolicies'])->name('queue.discovery.retrieve-all');
    Route::get('queue/scrape/{scrapeJob}', [QueueController::class, 'showScrapeJob'])->name('queue.scrape.show');
    Route::get('queue/scrape/{scrapeJob}/status', [QueueController::class, 'scrapeJobStatus'])->name('queue.scrape.status');
    Route::post('queue/scrape/{scrapeJob}/retry', [QueueController::class, 'retryScrapeJob'])->name('queue.scrape.retry');
    Route::get('queue/version/{version}', [QueueController::class, 'showDocumentVersion'])->name('queue.version.show');
    Route::post('queue/version/{version}/extract-metadata', [QueueController::class, 'extractVersionMetadata'])->name('queue.version.extract-metadata');

    // Version comparison routes
    Route::get('documents/{document}/compare/{oldVersion}/{newVersion}', [VersionComparisonController::class, 'show'])->name('documents.compare');
    Route::post('documents/{document}/compare/{oldVersion}/{newVersion}/analyze', [VersionComparisonController::class, 'summary'])->name('documents.compare.analyze');
    Route::get('documents/{document}/compare/analysis/{analysis}/status', [VersionComparisonController::class, 'analysisStatus'])->name('documents.compare.analysis-status');
    Route::post('documents/{document}/compare/chunk-summary', [VersionComparisonController::class, 'chunkSummary'])->name('documents.compare.chunk-summary');

    // Queue worker control routes
    Route::post('queue/worker/start', [QueueController::class, 'startWorker'])->name('queue.worker.start');
    Route::post('queue/worker/stop', [QueueController::class, 'stopWorker'])->name('queue.worker.stop');
    Route::post('queue/worker/restart', [QueueController::class, 'restartWorker'])->name('queue.worker.restart');
});

require __DIR__.'/settings.php';
