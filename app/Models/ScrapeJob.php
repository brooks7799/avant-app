<?php

namespace App\Models;

use App\Traits\HasProgressLog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScrapeJob extends Model
{
    use HasFactory;
    use HasProgressLog;

    protected $fillable = [
        'document_id',
        'status',
        'started_at',
        'completed_at',
        'duration_ms',
        'http_status',
        'error_message',
        'progress_log',
        'user_agent',
        'ip_address',
        'response_headers',
        'raw_html',
        'extracted_html',
        'request_headers',
        'content_changed',
        'created_version_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'duration_ms' => 'integer',
            'http_status' => 'integer',
            'response_headers' => 'array',
            'request_headers' => 'array',
            'content_changed' => 'boolean',
            'progress_log' => 'array',
            'metadata' => 'array',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function createdVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'created_version_id');
    }

    public function markRunning(): void
    {
        $this->update([
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    public function markCompleted(bool $contentChanged = false, ?int $versionId = null): void
    {
        $this->refresh(); // Ensure started_at is fresh from DB
        $now = now();
        $this->update([
            'status' => 'completed',
            'completed_at' => $now,
            'duration_ms' => $this->started_at ? abs($this->started_at->diffInMilliseconds($now)) : null,
            'content_changed' => $contentChanged,
            'created_version_id' => $versionId,
        ]);
    }

    public function markFailed(string $error, ?int $httpStatus = null): void
    {
        $this->refresh(); // Ensure started_at is fresh from DB
        $now = now();
        $this->update([
            'status' => 'failed',
            'completed_at' => $now,
            'duration_ms' => $this->started_at ? abs($this->started_at->diffInMilliseconds($now)) : null,
            'error_message' => $error,
            'http_status' => $httpStatus,
        ]);

        // Also update the document status
        $this->document?->update([
            'scrape_status' => 'failed',
            'scrape_notes' => $error,
        ]);
    }

    public static function getStatuses(): array
    {
        return [
            'pending' => 'Pending',
            'running' => 'Running',
            'completed' => 'Completed',
            'failed' => 'Failed',
        ];
    }
}
