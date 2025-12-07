<?php

namespace App\Jobs;

use App\Models\DiscoveryJob;
use App\Models\Website;
use App\Services\Scraper\PolicyDiscoveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DiscoverPoliciesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 300; // 5 minutes

    public function __construct(
        public Website $website,
        public DiscoveryJob $discoveryJob,
    ) {
        $this->onQueue(config('scraper.queue.discovery_queue', 'discovery'));
    }

    public function handle(PolicyDiscoveryService $discoveryService): void
    {
        $this->discoveryJob->markRunning();
        $this->discoveryJob->initializeProgressLog();

        try {
            $result = $discoveryService->discover($this->website, $this->discoveryJob);

            if ($result->success) {
                $this->discoveryJob->markCompleted(
                    $result->discoveredPolicies,
                    $result->urlsCrawled
                );

                // Update website with sitemap and robots data
                $this->website->update([
                    'sitemap_urls' => $result->sitemapUrls,
                    'robots_txt' => $result->robotsTxt,
                ]);
            } else {
                $this->discoveryJob->markFailed($result->error ?? 'Discovery failed');
            }

        } catch (\Exception $e) {
            $this->discoveryJob->logError("Job exception: {$e->getMessage()}");
            $this->discoveryJob->markFailed($e->getMessage());
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->discoveryJob->markFailed('Job failed: ' . $exception->getMessage());
    }
}
