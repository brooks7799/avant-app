<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class VersionComparison extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_id',
        'old_version_id',
        'new_version_id',
        'diff_html',
        'diff_summary',
        'changes',
        'additions_count',
        'deletions_count',
        'modifications_count',
        'similarity_score',
        'change_severity',
        'is_analyzed',
        // AI analysis fields
        'ai_change_summary',
        'ai_impact_analysis',
        'impact_score_delta',
        'change_flags',
        // Suspicious timing
        'is_suspicious_timing',
        'suspicious_timing_score',
        'timing_context',
        // AI metadata
        'ai_model_used',
        'ai_tokens_used',
        'ai_analysis_cost',
        'ai_analyzed_at',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'additions_count' => 'integer',
            'deletions_count' => 'integer',
            'modifications_count' => 'integer',
            'similarity_score' => 'decimal:4',
            'is_analyzed' => 'boolean',
            'change_flags' => 'array',
            'is_suspicious_timing' => 'boolean',
            'suspicious_timing_score' => 'integer',
            'timing_context' => 'array',
            'ai_tokens_used' => 'integer',
            'ai_analysis_cost' => 'decimal:6',
            'ai_analyzed_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function oldVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'old_version_id');
    }

    public function newVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'new_version_id');
    }

    public function getTotalChangesAttribute(): int
    {
        return $this->additions_count + $this->deletions_count + $this->modifications_count;
    }

    public function analyses(): HasMany
    {
        return $this->hasMany(VersionComparisonAnalysis::class)->orderByDesc('created_at');
    }

    public function chunkSummaries(): HasMany
    {
        return $this->hasMany(VersionComparisonChunkSummary::class)->orderBy('chunk_index');
    }

    public function latestAnalysis(): HasOne
    {
        return $this->hasOne(VersionComparisonAnalysis::class)->latestOfMany();
    }

    public function latestCompletedAnalysis(): HasOne
    {
        return $this->hasOne(VersionComparisonAnalysis::class)
            ->where('status', 'completed')
            ->latestOfMany();
    }
}
