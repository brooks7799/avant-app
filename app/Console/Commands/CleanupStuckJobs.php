<?php

namespace App\Console\Commands;

use App\Models\ScrapeJob;
use Illuminate\Console\Command;

class CleanupStuckJobs extends Command
{
    protected $signature = 'scraper:cleanup-stuck
                            {--minutes=10 : Mark jobs as failed if running longer than this many minutes}
                            {--dry-run : Show what would be cleaned up without making changes}';

    protected $description = 'Mark scrape jobs that have been running too long as failed';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');
        $dryRun = $this->option('dry-run');

        $cutoff = now()->subMinutes($minutes);

        $stuckJobs = ScrapeJob::where('status', 'running')
            ->where('started_at', '<', $cutoff)
            ->get();

        if ($stuckJobs->isEmpty()) {
            $this->info("No stuck jobs found (running > {$minutes} minutes).");
            return self::SUCCESS;
        }

        $this->info("Found {$stuckJobs->count()} stuck job(s):");

        foreach ($stuckJobs as $job) {
            $runningMinutes = $job->started_at->diffInMinutes(now());
            $url = $job->document?->source_url ?? 'Unknown URL';

            $this->line("  Job #{$job->id}: {$url}");
            $this->line("    Running for {$runningMinutes} minutes");

            if (!$dryRun) {
                $job->markFailed("Job timed out after {$runningMinutes} minutes");
                $job->document?->update(['scrape_status' => 'failed']);
                $this->info("    -> Marked as failed");
            } else {
                $this->warn("    -> Would mark as failed (dry run)");
            }
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn("Dry run complete. Run without --dry-run to apply changes.");
        } else {
            $this->newLine();
            $this->info("Cleanup complete. Marked {$stuckJobs->count()} job(s) as failed.");
        }

        return self::SUCCESS;
    }
}
