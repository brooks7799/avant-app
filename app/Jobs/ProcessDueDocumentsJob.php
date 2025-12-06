<?php

namespace App\Jobs;

use App\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessDueDocumentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public function __construct(
        public bool $force = false,
    ) {}

    public function handle(): void
    {
        $query = Document::query()
            ->where('is_active', true)
            ->where('is_monitored', true);

        if (!$this->force) {
            // Only get documents that need scraping based on their schedule
            $documents = $query->get()->filter(fn ($doc) => $doc->needsScraping());
        } else {
            $documents = $query->get();
        }

        $count = $documents->count();
        Log::info("Processing {$count} documents due for scraping");

        foreach ($documents as $document) {
            ScrapeDocumentJob::dispatch($document);
        }
    }
}
