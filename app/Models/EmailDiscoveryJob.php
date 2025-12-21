<?php

namespace App\Models;

use App\Traits\HasProgressLog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailDiscoveryJob extends Model
{
    use HasFactory;
    use HasProgressLog;

    protected $fillable = [
        'user_id',
        'status',
        'started_at',
        'completed_at',
        'duration_ms',
        'emails_scanned',
        'companies_found',
        'error_message',
        'progress_log',
        'search_queries',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'duration_ms' => 'integer',
            'emails_scanned' => 'integer',
            'companies_found' => 'integer',
            'progress_log' => 'array',
            'search_queries' => 'array',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function discoveredCompanies(): HasMany
    {
        return $this->hasMany(DiscoveredEmailCompany::class);
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
    }

    /**
     * Mark the job as completed.
     */
    public function markCompleted(int $emailsScanned, int $companiesFound): void
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'duration_ms' => $this->calculateDurationMs(),
            'emails_scanned' => $emailsScanned,
            'companies_found' => $companiesFound,
        ]);
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
    }

    /**
     * Update scan progress.
     */
    public function updateProgress(int $emailsScanned, int $companiesFound): void
    {
        $this->update([
            'emails_scanned' => $emailsScanned,
            'companies_found' => $companiesFound,
        ]);
    }

    /**
     * Calculate duration in milliseconds.
     */
    protected function calculateDurationMs(): ?int
    {
        if (! $this->started_at) {
            return null;
        }

        $this->refresh();

        $durationMs = abs(now()->diffInMilliseconds($this->started_at));

        return (int) (round($durationMs / 1000) * 1000);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

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

    /**
     * Add a progress message (alias for logProgress).
     */
    public function addProgress(string $message, string $type = 'info', array $data = []): void
    {
        $this->logProgress($message, $type, $data);
    }
}
