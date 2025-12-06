<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'website_id',
        'document_type_id',
        'source_url',
        'discovery_method',
        'canonical_url',
        'is_active',
        'is_monitored',
        'scrape_frequency',
        'last_scraped_at',
        'last_changed_at',
        'scrape_status',
        'scrape_notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_monitored' => 'boolean',
            'last_scraped_at' => 'datetime',
            'last_changed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('scraped_at');
    }

    public function currentVersion(): HasOne
    {
        return $this->hasOne(DocumentVersion::class)->where('is_current', true);
    }

    public function scrapeJobs(): HasMany
    {
        return $this->hasMany(ScrapeJob::class)->orderByDesc('created_at');
    }

    public function comparisons(): HasMany
    {
        return $this->hasMany(VersionComparison::class);
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'document_product')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function latestScrapeJob(): HasOne
    {
        return $this->hasOne(ScrapeJob::class)->latestOfMany();
    }

    public function needsScraping(): bool
    {
        if (!$this->is_active || !$this->is_monitored) {
            return false;
        }

        if (!$this->last_scraped_at) {
            return true;
        }

        $interval = match ($this->scrape_frequency) {
            'hourly' => now()->subHour(),
            'daily' => now()->subDay(),
            'weekly' => now()->subWeek(),
            'monthly' => now()->subMonth(),
            default => now()->subDay(),
        };

        return $this->last_scraped_at->lt($interval);
    }

    public static function getDiscoveryMethods(): array
    {
        return [
            'manual' => 'Manual Entry',
            'crawl' => 'Crawled',
            'sitemap' => 'Sitemap',
            'common_paths' => 'Common Paths',
        ];
    }

    public static function getScrapeStatuses(): array
    {
        return [
            'pending' => 'Pending',
            'success' => 'Success',
            'failed' => 'Failed',
            'blocked' => 'Blocked',
        ];
    }
}
