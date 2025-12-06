<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Website extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'url',
        'name',
        'is_primary',
        'is_active',
        'discovery_status',
        'last_discovered_at',
        'sitemap_urls',
        'robots_txt',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
            'last_discovered_at' => 'datetime',
            'sitemap_urls' => 'array',
            'robots_txt' => 'array',
            'metadata' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function activeDocuments(): HasMany
    {
        return $this->documents()->where('is_active', true);
    }

    public function discoveryJobs(): HasMany
    {
        return $this->hasMany(DiscoveryJob::class)->orderByDesc('created_at');
    }

    public function latestDiscoveryJob(): HasOne
    {
        return $this->hasOne(DiscoveryJob::class)->latestOfMany();
    }

    /**
     * Get the base URL (scheme + host) of the website.
     */
    public function getBaseUrlAttribute(): string
    {
        $parsed = parse_url($this->url);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? $this->url;

        return "{$scheme}://{$host}";
    }

    /**
     * Get the host portion of the URL.
     */
    public function getHostAttribute(): string
    {
        $parsed = parse_url($this->url);

        return $parsed['host'] ?? $this->url;
    }

    /**
     * Check if discovery is currently running.
     */
    public function isDiscovering(): bool
    {
        return $this->discovery_status === 'running';
    }

    /**
     * Check if discovery has been completed.
     */
    public function hasDiscovered(): bool
    {
        return $this->discovery_status === 'completed';
    }

    /**
     * Mark discovery as running.
     */
    public function markDiscoveryRunning(): void
    {
        $this->update(['discovery_status' => 'running']);
    }

    /**
     * Mark discovery as completed.
     */
    public function markDiscoveryCompleted(): void
    {
        $this->update([
            'discovery_status' => 'completed',
            'last_discovered_at' => now(),
        ]);
    }

    /**
     * Mark discovery as failed.
     */
    public function markDiscoveryFailed(): void
    {
        $this->update(['discovery_status' => 'failed']);
    }

    public static function getDiscoveryStatuses(): array
    {
        return [
            'pending' => 'Pending',
            'running' => 'Running',
            'completed' => 'Completed',
            'failed' => 'Failed',
        ];
    }
}
