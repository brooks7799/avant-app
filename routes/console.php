<?php

use App\Models\AnalysisJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Cleanup stuck scrape jobs every minute
Schedule::command('scraper:cleanup-stuck --minutes=3')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Cleanup stuck analysis jobs every 5 minutes
Schedule::call(function () {
    $staleJobs = AnalysisJob::whereIn('status', ['pending', 'running'])
        ->where('created_at', '<', now()->subMinutes(15))
        ->get();

    foreach ($staleJobs as $job) {
        $job->update([
            'status' => 'failed',
            'error_message' => 'Job timed out after 15 minutes',
            'completed_at' => now(),
        ]);

        Log::warning('Analysis job marked as timed out', [
            'analysis_job_id' => $job->id,
            'document_version_id' => $job->document_version_id,
            'created_at' => $job->created_at->toISOString(),
        ]);
    }

    if ($staleJobs->count() > 0) {
        Log::info("Cleaned up {$staleJobs->count()} stale analysis job(s)");
    }
})->everyFiveMinutes()->name('cleanup-stale-analysis-jobs');
