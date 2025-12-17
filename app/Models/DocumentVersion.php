<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class DocumentVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_id',
        'version_number',
        'content_raw',
        'content_text',
        'content_markdown',
        'content_hash',
        'word_count',
        'character_count',
        'language',
        'effective_date',
        'scraped_at',
        'extraction_metadata',
        'metadata',
        'is_current',
    ];

    protected function casts(): array
    {
        return [
            'word_count' => 'integer',
            'character_count' => 'integer',
            'effective_date' => 'datetime',
            'scraped_at' => 'datetime',
            'extraction_metadata' => 'array',
            'metadata' => 'array',
            'is_current' => 'boolean',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function scores(): HasMany
    {
        return $this->hasMany(DocumentScore::class);
    }

    public function analyses(): HasMany
    {
        return $this->hasMany(AnalysisResult::class);
    }

    public function analysisResults(): HasMany
    {
        return $this->hasMany(AnalysisResult::class);
    }

    public function currentAnalysis(): HasOne
    {
        return $this->hasOne(AnalysisResult::class)
            ->where('is_current', true)
            ->where('analysis_type', 'full_analysis');
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    public function comparisonsAsOld(): HasMany
    {
        return $this->hasMany(VersionComparison::class, 'old_version_id');
    }

    public function comparisonsAsNew(): HasMany
    {
        return $this->hasMany(VersionComparison::class, 'new_version_id');
    }

    public function chats(): HasMany
    {
        return $this->hasMany(DocumentChat::class);
    }

    public function previousVersion(): ?DocumentVersion
    {
        return DocumentVersion::where('document_id', $this->document_id)
            ->where('scraped_at', '<', $this->scraped_at)
            ->orderByDesc('scraped_at')
            ->first();
    }

    public function nextVersion(): ?DocumentVersion
    {
        return DocumentVersion::where('document_id', $this->document_id)
            ->where('scraped_at', '>', $this->scraped_at)
            ->orderBy('scraped_at')
            ->first();
    }

    public function markAsCurrent(): void
    {
        // Remove current flag from other versions
        DocumentVersion::where('document_id', $this->document_id)
            ->where('id', '!=', $this->id)
            ->update(['is_current' => false]);

        $this->update(['is_current' => true]);
    }

    public static function generateContentHash(string $content): string
    {
        return hash('sha256', $content);
    }
}
