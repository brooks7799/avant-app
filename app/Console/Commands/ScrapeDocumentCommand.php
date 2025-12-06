<?php

namespace App\Console\Commands;

use App\Jobs\ScrapeDocumentJob;
use App\Models\Document;
use App\Models\ScrapeJob;
use Illuminate\Console\Command;

class ScrapeDocumentCommand extends Command
{
    protected $signature = 'policies:scrape
                            {document : The document ID to scrape}
                            {--sync : Run synchronously instead of queueing}';

    protected $description = 'Scrape a specific policy document';

    public function handle(): int
    {
        $document = Document::find($this->argument('document'));

        if (!$document) {
            $this->error('Document not found.');
            return self::FAILURE;
        }

        $this->info("Scraping document: {$document->url}");

        // Create scrape job record
        $scrapeJob = ScrapeJob::create([
            'document_id' => $document->id,
            'status' => 'pending',
        ]);

        if ($this->option('sync')) {
            // Run synchronously
            $this->info('Running scrape synchronously...');

            $job = new ScrapeDocumentJob($document, $scrapeJob);
            $job->handle(
                app(\App\Services\Scraper\DocumentScraperService::class),
                app(\App\Services\Scraper\VersioningService::class)
            );

            $scrapeJob->refresh();

            if ($scrapeJob->status === 'completed') {
                $this->info("Scrape completed!");
                $this->info("Content changed: " . ($scrapeJob->content_changed ? 'Yes' : 'No'));

                if ($scrapeJob->version_id) {
                    $this->info("New version ID: {$scrapeJob->version_id}");
                }
            } else {
                $this->error("Scrape failed: {$scrapeJob->error_message}");
                return self::FAILURE;
            }
        } else {
            // Queue the job
            ScrapeDocumentJob::dispatch($document, $scrapeJob);
            $this->info("Scrape job queued. Job ID: {$scrapeJob->id}");
        }

        return self::SUCCESS;
    }
}
