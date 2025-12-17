<?php

namespace App\Models;

use App\Traits\HasProgressLog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscoveryJob extends Model
{
    use HasFactory;
    use HasProgressLog;

    protected $fillable = [
        'website_id',
        'status',
        'started_at',
        'completed_at',
        'duration_ms',
        'urls_crawled',
        'policies_found',
        'discovered_urls',
        'error_message',
        'progress_log',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'duration_ms' => 'integer',
            'urls_crawled' => 'integer',
            'policies_found' => 'integer',
            'discovered_urls' => 'array',
            'progress_log' => 'array',
            'metadata' => 'array',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /**
     * Mark the job as running.
     */
    public function markRunning(): void
    {
        $this->update([
            'status' => 'running',
            'started_at' => now(),
        ]);

        $this->website->markDiscoveryRunning();
    }

    /**
     * Mark the job as completed.
     */
    public function markCompleted(array $discoveredUrls, int $urlsCrawled): void
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'duration_ms' => $this->calculateDurationMs(),
            'urls_crawled' => $urlsCrawled,
            'policies_found' => count($discoveredUrls),
            'discovered_urls' => $discoveredUrls,
        ]);

        $this->website->markDiscoveryCompleted();
    }

    /**
     * Mark the job as failed.
     */
    public function markFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'completed_at' => now(),
            'duration_ms' => $this->calculateDurationMs(),
            'error_message' => $error,
        ]);

        $this->website->markDiscoveryFailed();
    }

    /**
     * Calculate duration in milliseconds (always positive, rounded to seconds).
     */
    protected function calculateDurationMs(): ?int
    {
        if (!$this->started_at) {
            return null;
        }

        // Refresh to get accurate started_at from DB
        $this->refresh();

        $durationMs = abs(now()->diffInMilliseconds($this->started_at));

        // Round to nearest second (1000ms)
        return (int) (round($durationMs / 1000) * 1000);
    }

    /**
     * Check if the job is still pending.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if the job is running.
     */
    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    /**
     * Check if the job has completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if the job has failed.
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
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
