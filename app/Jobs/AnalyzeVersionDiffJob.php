<?php

namespace App\Jobs;

use App\Models\VersionComparison;
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

    public int $timeout = 180; // 3 minutes

    public int $backoff = 60;

    public int $maxExceptions = 2;

    public function __construct(
        public VersionComparison $comparison,
    ) {
        $this->onQueue('ai');
    }

    /**
     * Calculate the number of seconds to wait before retrying.
     */
    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(10);
    }

    public function handle(PolicyDiffAnalysisService $diffService): void
    {
        // Skip if already analyzed
        if ($this->comparison->is_analyzed) {
            Log::info('Diff already analyzed, skipping', [
                'comparison_id' => $this->comparison->id,
            ]);

            return;
        }

        Log::info('Starting version diff analysis job', [
            'comparison_id' => $this->comparison->id,
            'old_version_id' => $this->comparison->old_version_id,
            'new_version_id' => $this->comparison->new_version_id,
        ]);

        try {
            $result = $diffService->analyzeDiff($this->comparison);

            Log::info('Version diff analysis completed', [
                'comparison_id' => $this->comparison->id,
                'impact_delta' => $result->impact_score_delta,
                'is_suspicious' => $result->is_suspicious_timing,
            ]);

        } catch (\Exception $e) {
            Log::error('Version diff analysis failed', [
                'comparison_id' => $this->comparison->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Version diff analysis job failed permanently', [
            'comparison_id' => $this->comparison->id,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'diff-analyze',
            'comparison:'.$this->comparison->id,
            'document:'.$this->comparison->document_id,
        ];
    }
}
