<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VersionComparisonAnalysis extends Model
{
    use HasFactory;

    protected $fillable = [
        'version_comparison_id',
        'status',
        'total_chunks',
        'processed_chunks',
        'current_chunk_label',
        'error_message',
        'summary',
        'impact_analysis',
        'impact_score_delta',
        'change_flags',
        'is_suspicious_timing',
        'suspicious_timing_score',
        'timing_context',
        'ai_model_used',
        'ai_tokens_used',
        'ai_analysis_cost',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'total_chunks' => 'integer',
            'processed_chunks' => 'integer',
            'change_flags' => 'array',
            'timing_context' => 'array',
            'is_suspicious_timing' => 'boolean',
            'ai_tokens_used' => 'integer',
            'ai_analysis_cost' => 'decimal:6',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function updateProgress(int $processedChunks, ?string $currentLabel = null): void
    {
        $this->update([
            'processed_chunks' => $processedChunks,
            'current_chunk_label' => $currentLabel,
        ]);
    }

    public function comparison(): BelongsTo
    {
        return $this->belongsTo(VersionComparison::class, 'version_comparison_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function markAsProcessing(): void
    {
        $this->update([
            'status' => 'processing',
            'started_at' => now(),
        ]);
    }

    public function markAsCompleted(array $data): void
    {
        $this->update(array_merge($data, [
            'status' => 'completed',
            'completed_at' => now(),
        ]));
    }

    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
            'completed_at' => now(),
        ]);
    }
}
