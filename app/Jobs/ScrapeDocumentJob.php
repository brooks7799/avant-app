<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\ScrapeJob;
use App\Services\Scraper\DocumentScraperService;
use App\Services\Scraper\VersioningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ScrapeDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public int $backoff = 60;

    public function __construct(
        public Document $document,
        public ?ScrapeJob $scrapeJob = null,
    ) {
        $this->onQueue(config('scraper.queue.scrape_queue', 'scraping'));
    }

    public function handle(
        DocumentScraperService $scraper,
        VersioningService $versioning
    ): void {
        // Create scrape job if not provided
        $job = $this->scrapeJob ?? ScrapeJob::create([
            'document_id' => $this->document->id,
            'status' => 'pending',
        ]);

        try {
            // Scrape the document
            $result = $scraper->scrapeWithJob($this->document, $job);

            if (!$result->success) {
                $job->markFailed($result->error ?? 'Unknown error', $result->httpStatus);
                $this->document->update([
                    'scrape_status' => 'failed',
                    'scrape_notes' => $result->error,
                ]);
                return;
            }

            // Create version if content changed
            $version = $versioning->createVersion($this->document, $result);
            $contentChanged = $version !== null;

            // Mark job as completed
            $job->markCompleted($contentChanged, $version?->id);

            // Queue comparison if content changed and there's a previous version
            if ($contentChanged && $version) {
                $previousVersion = $version->previousVersion();
                if ($previousVersion) {
                    CompareVersionsJob::dispatch($previousVersion, $version);
                }
            }

        } catch (\Exception $e) {
            $job->markFailed($e->getMessage());
            $this->document->update([
                'scrape_status' => 'failed',
                'scrape_notes' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        // Update document status on final failure
        $this->document->update([
            'scrape_status' => 'failed',
            'scrape_notes' => 'Job failed after all retries: ' . $exception->getMessage(),
        ]);
    }
}
