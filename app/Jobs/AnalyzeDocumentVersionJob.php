<?php

namespace App\Jobs;

use App\Models\AnalysisJob;
use App\Models\DocumentVersion;
use App\Services\AI\PolicyAiAnalysisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AnalyzeDocumentVersionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300; // 5 minutes for large documents

    public int $backoff = 60;

    public int $maxExceptions = 2;

    /**
     * The AnalysisJob model instance (not serialized - fetched fresh in handle).
     */
    protected ?AnalysisJob $analysisJob = null;

    public function __construct(
        public DocumentVersion $version,
        public string $analysisType = 'full_analysis',
        public ?int $analysisJobId = null,
    ) {
        $this->onQueue('ai');
    }

    /**
     * Calculate the number of seconds to wait before retrying.
     */
    public function retryUntil(): \DateTime
    {
        // Give up after 10 minutes total
        return now()->addMinutes(10);
    }

    /**
     * Fetch the AnalysisJob model fresh from the database.
     */
    protected function loadAnalysisJob(): void
    {
        // Handle legacy jobs that were serialized before the analysisJobId property existed
        $jobId = property_exists($this, 'analysisJobId') && isset($this->analysisJobId)
            ? $this->analysisJobId
            : null;

        if ($jobId) {
            $this->analysisJob = AnalysisJob::find($jobId);

            if (!$this->analysisJob) {
                Log::warning('AnalysisJob not found in database', [
                    'analysis_job_id' => $jobId,
                    'version_id' => $this->version->id,
                ]);
            } else {
                Log::debug('AnalysisJob loaded from database', [
                    'analysis_job_id' => $jobId,
                    'status' => $this->analysisJob->status,
                ]);
            }
        }
    }

    public function handle(PolicyAiAnalysisService $analysisService): void
    {
        // Fetch AnalysisJob fresh from database to avoid serialization issues
        $this->loadAnalysisJob();

        $jobId = property_exists($this, 'analysisJobId') && isset($this->analysisJobId)
            ? $this->analysisJobId
            : null;

        Log::info('Starting document version analysis job', [
            'version_id' => $this->version->id,
            'document_id' => $this->version->document_id,
            'analysis_type' => $this->analysisType,
            'analysis_job_id' => $jobId,
            'analysis_job_found' => $this->analysisJob !== null,
        ]);

        // Mark job as running
        if ($this->analysisJob) {
            $this->analysisJob->markAsRunning();
        }

        try {
            if ($this->analysisJob) {
                $this->analysisJob->addProgressLog('Starting AI analysis');
            }

            $result = $analysisService->analyze($this->version, $this->analysisType);

            // Mark job as completed with stats
            if ($this->analysisJob) {
                $this->analysisJob->markAsCompleted(
                    $result,
                    $result->tokens_used,
                    (float) $result->analysis_cost
                );
            }

            Log::info('Document version analysis completed', [
                'version_id' => $this->version->id,
                'analysis_id' => $result->id,
                'score' => $result->overall_score,
                'rating' => $result->overall_rating,
            ]);

        } catch (\Exception $e) {
            Log::error('Document version analysis failed', [
                'version_id' => $this->version->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            if ($this->analysisJob) {
                $this->analysisJob->markAsFailed($e->getMessage());
            }

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        // Fetch AnalysisJob fresh in case handle() wasn't called
        $this->loadAnalysisJob();

        $jobId = property_exists($this, 'analysisJobId') && isset($this->analysisJobId)
            ? $this->analysisJobId
            : null;

        Log::error('Document version analysis job failed permanently', [
            'version_id' => $this->version->id,
            'document_id' => $this->version->document_id,
            'analysis_job_id' => $jobId,
            'error' => $exception->getMessage(),
        ]);

        if ($this->analysisJob) {
            $this->analysisJob->markAsFailed($exception->getMessage());
        }
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'analyze',
            'version:'.$this->version->id,
            'document:'.$this->version->document_id,
        ];
    }
}
