<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalysisJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_version_id',
        'analysis_type',
        'status',
        'model_used',
        'tokens_used',
        'analysis_cost',
        'analysis_result_id',
        'progress_log',
        'error_message',
        'started_at',
        'completed_at',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'progress_log' => 'array',
            'tokens_used' => 'integer',
            'analysis_cost' => 'decimal:6',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function documentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class);
    }

    public function analysisResult(): BelongsTo
    {
        return $this->belongsTo(AnalysisResult::class);
    }

    public function addProgressLog(string $message): void
    {
        $log = $this->progress_log ?? [];
        $log[] = [
            'timestamp' => now()->toISOString(),
            'message' => $message,
        ];
        $this->update(['progress_log' => $log]);
    }

    public function markAsRunning(): void
    {
        $this->update([
            'status' => 'running',
            'started_at' => now(),
        ]);
        $this->addProgressLog('Analysis started');
    }

    public function markAsCompleted(AnalysisResult $result, int $tokensUsed, float $cost): void
    {
        $this->update([
            'status' => 'completed',
            'analysis_result_id' => $result->id,
            'model_used' => $result->model_used,
            'tokens_used' => $tokensUsed,
            'analysis_cost' => $cost,
            'completed_at' => now(),
            'duration_ms' => $this->started_at ? now()->diffInMilliseconds($this->started_at) : null,
        ]);
        $this->addProgressLog('Analysis completed successfully');
    }

    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
            'completed_at' => now(),
            'duration_ms' => $this->started_at ? now()->diffInMilliseconds($this->started_at) : null,
        ]);
        $this->addProgressLog('Analysis failed: ' . $errorMessage);
    }
}
