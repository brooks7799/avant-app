<?php

namespace App\Jobs;

use App\Models\VersionComparison;
use App\Models\VersionComparisonAnalysis;
use App\Services\AI\PolicyDiffAnalysisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AnalyzeVersionDiffJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 1800; // 30 minutes for full analysis with many chunks

    public int $backoff = 60;

    public int $maxExceptions = 2;

    public function __construct(
        public VersionComparisonAnalysis $analysis,
    ) {
        $this->onQueue('ai');
    }

    /**
     * Calculate the number of seconds to wait before retrying.
     */
    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(15);
    }

    public function handle(PolicyDiffAnalysisService $diffService): void
    {
        // Skip if already completed or failed
        if ($this->analysis->isCompleted() || $this->analysis->isFailed()) {
            Log::info('Analysis already processed, skipping', [
                'analysis_id' => $this->analysis->id,
                'status' => $this->analysis->status,
            ]);

            return;
        }

        $comparison = $this->analysis->comparison;

        Log::info('Starting version diff analysis job', [
            'analysis_id' => $this->analysis->id,
            'comparison_id' => $comparison->id,
            'old_version_id' => $comparison->old_version_id,
            'new_version_id' => $comparison->new_version_id,
        ]);

        $this->analysis->markAsProcessing();

        try {
            $result = $diffService->analyzeForHistory($comparison, $this->analysis);

            Log::info('Version diff analysis completed', [
                'analysis_id' => $this->analysis->id,
                'comparison_id' => $comparison->id,
                'impact_delta' => $result['impact_score_delta'] ?? null,
                'is_suspicious' => $result['is_suspicious_timing'] ?? false,
            ]);

        } catch (\Exception $e) {
            Log::error('Version diff analysis failed', [
                'analysis_id' => $this->analysis->id,
                'comparison_id' => $comparison->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->analysis->markAsFailed($e->getMessage());

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Version diff analysis job failed permanently', [
            'analysis_id' => $this->analysis->id,
            'error' => $exception->getMessage(),
        ]);

        $this->analysis->markAsFailed($exception->getMessage());
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'diff-analyze',
            'analysis:'.$this->analysis->id,
            'comparison:'.$this->analysis->version_comparison_id,
        ];
    }
}
