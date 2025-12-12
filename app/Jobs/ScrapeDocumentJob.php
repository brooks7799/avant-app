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

    public int $tries = 2;
    public int $timeout = 90; // 90 seconds max per attempt
    public int $backoff = 30;
    public int $maxExceptions = 2;

    public function __construct(
        public Document $document,
        public ?ScrapeJob $scrapeJob = null,
    ) {
        $this->onQueue(config('scraper.queue.scrape_queue', 'scraping'));
    }

    /**
     * Calculate the number of seconds to wait before retrying.
     */
    public function retryUntil(): \DateTime
    {
        // Give up after 5 minutes total
        return now()->addMinutes(5);
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
            $job->logInfo('Checking for content changes...');
            $version = $versioning->createVersion($this->document, $result);
            $contentChanged = $version !== null;

            if ($contentChanged) {
                $job->logSuccess('Content changed - created new version', ['version_id' => $version->id]);
            } else {
                $job->logInfo('No content changes detected');
            }

            // Mark job as completed
            $job->markCompleted($contentChanged, $version?->id);

            // Queue comparison if content changed and there's a previous version
            if ($contentChanged && $version) {
                $previousVersion = $version->previousVersion();
                if ($previousVersion) {
                    $job->logInfo('Queuing version comparison');
                    CompareVersionsJob::dispatch($previousVersion, $version);
                }
            }

        } catch (\Exception $e) {
            $job->logError("Job exception: {$e->getMessage()}");
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
        // Determine the error message
        $isTimeout = $exception instanceof \Illuminate\Queue\MaxAttemptsExceededException
            || str_contains($exception->getMessage(), 'timeout')
            || str_contains($exception->getMessage(), 'timed out');

        $errorMessage = $isTimeout
            ? 'Job timed out after ' . $this->timeout . ' seconds'
            : 'Job failed after all retries: ' . $exception->getMessage();

        // Update the scrape job record if it exists
        if ($this->scrapeJob) {
            $this->scrapeJob->refresh();
            if ($this->scrapeJob->status === 'running') {
                $this->scrapeJob->markFailed($errorMessage);
            }
        }

        // Update document status on final failure
        $this->document->update([
            'scrape_status' => 'failed',
            'scrape_notes' => $errorMessage,
        ]);
    }
}
