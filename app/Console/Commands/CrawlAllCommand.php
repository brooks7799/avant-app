<?php

namespace App\Console\Commands;

use App\Jobs\ProcessDueDocumentsJob;
use Illuminate\Console\Command;

class CrawlAllCommand extends Command
{
    protected $signature = 'policies:crawl-all
                            {--force : Force scrape all active documents regardless of schedule}
                            {--sync : Run synchronously instead of queueing}';

    protected $description = 'Queue all documents that are due for scraping';

    public function handle(): int
    {
        $force = $this->option('force');

        $this->info($force
            ? 'Queueing ALL active monitored documents for scraping...'
            : 'Queueing documents due for scraping...'
        );

        if ($this->option('sync')) {
            // Run synchronously
            $job = new ProcessDueDocumentsJob($force);
            $job->handle();
            $this->info('Processing complete.');
        } else {
            // Queue the job
            ProcessDueDocumentsJob::dispatch($force);
            $this->info('ProcessDueDocumentsJob queued.');
        }

        return self::SUCCESS;
    }
}
