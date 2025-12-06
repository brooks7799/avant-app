<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentScore extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_version_id',
        'scoring_criteria_id',
        'score',
        'rating',
        'explanation',
        'evidence',
        'confidence',
        'model_used',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
            'evidence' => 'array',
            'confidence' => 'decimal:4',
        ];
    }

    public function documentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class);
    }

    public function scoringCriteria(): BelongsTo
    {
        return $this->belongsTo(ScoringCriteria::class);
    }

    public static function getRatings(): array
    {
        return [
            'good' => ['label' => 'Good', 'color' => 'green', 'min_score' => 70],
            'neutral' => ['label' => 'Neutral', 'color' => 'yellow', 'min_score' => 40],
            'bad' => ['label' => 'Bad', 'color' => 'orange', 'min_score' => 20],
            'ugly' => ['label' => 'Ugly', 'color' => 'red', 'min_score' => 0],
        ];
    }

    public static function getRatingForScore(float $score): string
    {
        return match (true) {
            $score >= 70 => 'good',
            $score >= 40 => 'neutral',
            $score >= 20 => 'bad',
            default => 'ugly',
        };
    }
}
