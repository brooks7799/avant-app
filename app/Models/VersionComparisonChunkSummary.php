<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VersionComparisonChunkSummary extends Model
{
    protected $fillable = [
        'version_comparison_id',
        'chunk_index',
        'content_hash',
        'title',
        'summary',
        'impact',
        'grade',
        'reason',
        'ai_model_used',
        'ai_tokens_used',
    ];

    protected $casts = [
        'chunk_index' => 'integer',
        'ai_tokens_used' => 'integer',
    ];

    public function comparison(): BelongsTo
    {
        return $this->belongsTo(VersionComparison::class, 'version_comparison_id');
    }

    /**
     * Generate a content hash for deduplication.
     */
    public static function generateContentHash(string $removedText, string $addedText): string
    {
        return hash('sha256', $removedText . '|||' . $addedText);
    }

    /**
     * Convert to frontend-friendly format.
     */
    public function toFrontendArray(): array
    {
        return [
            'title' => $this->title,
            'summary' => $this->summary,
            'impact' => $this->impact,
            'grade' => $this->grade,
            'reason' => $this->reason,
        ];
    }
}
