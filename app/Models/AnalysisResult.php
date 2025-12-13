<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalysisResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_version_id',
        'analysis_type',
        'overall_score',
        'overall_rating',
        'summary',
        'key_concerns',
        'positive_aspects',
        'recommendations',
        'extracted_data',
        'flags',
        'behavioral_signals',
        'tags',
        'model_used',
        'tokens_used',
        'analysis_cost',
        'is_current',
        'processing_errors',
    ];

    protected function casts(): array
    {
        return [
            'overall_score' => 'decimal:2',
            'extracted_data' => 'array',
            'flags' => 'array',
            'behavioral_signals' => 'array',
            'tags' => 'array',
            'tokens_used' => 'integer',
            'analysis_cost' => 'decimal:6',
            'is_current' => 'boolean',
            'processing_errors' => 'array',
        ];
    }

    public function hasErrors(): bool
    {
        return !empty($this->processing_errors);
    }

    public function documentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class);
    }

    public function markAsCurrent(): void
    {
        // Remove current flag from other analyses of same type for this version
        AnalysisResult::where('document_version_id', $this->document_version_id)
            ->where('analysis_type', $this->analysis_type)
            ->where('id', '!=', $this->id)
            ->update(['is_current' => false]);

        $this->update(['is_current' => true]);
    }

    public static function getAnalysisTypes(): array
    {
        return [
            'full_analysis' => 'Full Analysis',
            'change_analysis' => 'Change Analysis',
            'summary' => 'Summary',
            'quick_scan' => 'Quick Scan',
        ];
    }

    public static function getRatings(): array
    {
        return [
            'A' => ['label' => 'Excellent', 'color' => 'green', 'min_score' => 80],
            'B' => ['label' => 'Good', 'color' => 'lime', 'min_score' => 60],
            'C' => ['label' => 'Fair', 'color' => 'yellow', 'min_score' => 40],
            'D' => ['label' => 'Poor', 'color' => 'orange', 'min_score' => 20],
            'F' => ['label' => 'Failing', 'color' => 'red', 'min_score' => 0],
        ];
    }

    public static function getRatingForScore(float $score): string
    {
        return match (true) {
            $score >= 80 => 'A',
            $score >= 60 => 'B',
            $score >= 40 => 'C',
            $score >= 20 => 'D',
            default => 'F',
        };
    }
}
