<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
