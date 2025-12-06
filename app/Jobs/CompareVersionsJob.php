<?php

namespace App\Jobs;

use App\Models\DocumentVersion;
use App\Services\Scraper\VersioningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CompareVersionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(
        public DocumentVersion $oldVersion,
        public DocumentVersion $newVersion,
    ) {
        $this->onQueue(config('scraper.queue.scrape_queue', 'scraping'));
    }

    public function handle(VersioningService $versioning): void
    {
        $versioning->getOrCreateComparison($this->oldVersion, $this->newVersion);
    }
}
