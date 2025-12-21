<?php

namespace App\Jobs;

use App\Models\EmailDiscoveryJob;
use App\Models\UserGmailConnection;
use App\Services\Gmail\EmailDiscoveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ScanEmailsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 2;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 600; // 10 minutes

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 60;

    public function __construct(
        public UserGmailConnection $gmailConnection,
        public EmailDiscoveryJob $discoveryJob,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(EmailDiscoveryService $discoveryService): void
    {
        Log::info('Starting email scan job', [
            'job_id' => $this->discoveryJob->id,
            'user_id' => $this->gmailConnection->user_id,
        ]);

        try {
            $discoveryService->discover($this->gmailConnection, $this->discoveryJob);

            Log::info('Email scan job completed', [
                'job_id' => $this->discoveryJob->id,
                'emails_scanned' => $this->discoveryJob->emails_scanned,
                'companies_found' => $this->discoveryJob->companies_found,
            ]);
        } catch (\Exception $e) {
            Log::error('Email scan job failed', [
                'job_id' => $this->discoveryJob->id,
                'error' => $e->getMessage(),
            ]);

            // Mark the job as failed if not already
            if (! $this->discoveryJob->isFailed()) {
                $this->discoveryJob->markFailed($e->getMessage());
            }

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Email scan job failed permanently', [
            'job_id' => $this->discoveryJob->id,
            'error' => $exception->getMessage(),
        ]);

        if (! $this->discoveryJob->isFailed()) {
            $this->discoveryJob->markFailed($exception->getMessage());
        }
    }
}
