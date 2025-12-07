<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'website_id',
        'name',
        'slug',
        'type',
        'url',
        'app_store_url',
        'play_store_url',
        'description',
        'icon_url',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public const TYPES = [
        'product' => 'Product',
        'service' => 'Service',
        'app' => 'Mobile App',
        'game' => 'Game',
        'platform' => 'Platform',
        'website' => 'Website',
        'hardware' => 'Hardware',
        'other' => 'Other',
    ];

    protected static function booted(): void
    {
        static::creating(function (Product $product) {
            if (empty($product->slug)) {
                $product->slug = Str::slug($product->name);
            }
        });

        // Auto-create website when product has a URL
        static::created(function (Product $product) {
            $product->syncWebsite();
        });

        static::updated(function (Product $product) {
            if ($product->isDirty('url')) {
                $product->syncWebsite();
            }
        });
    }

    /**
     * Create or update a website for this product's URL
     */
    public function syncWebsite(): void
    {
        if (empty($this->url)) {
            return;
        }

        $parsedUrl = parse_url($this->url);
        $baseUrl = ($parsedUrl['scheme'] ?? 'https') . '://' . ($parsedUrl['host'] ?? '');

        // Check if website already exists for this base URL
        $existingWebsite = Website::where('company_id', $this->company_id)
            ->where('base_url', $baseUrl)
            ->first();

        if ($existingWebsite) {
            // Link existing website to this product if not already linked
            if (!$this->website_id || $this->website_id !== $existingWebsite->id) {
                $this->update(['website_id' => $existingWebsite->id]);
            }
            return;
        }

        // Create new website
        $website = Website::create([
            'company_id' => $this->company_id,
            'url' => $this->url,
            'base_url' => $baseUrl,
            'name' => $this->name,
            'is_primary' => false,
            'is_active' => true,
        ]);

        $this->update(['website_id' => $website->id]);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'document_product')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function primaryDocuments(): BelongsToMany
    {
        return $this->documents()->wherePivot('is_primary', true);
    }

    public function getTypeLabel(): string
    {
        return self::TYPES[$this->type] ?? 'Other';
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }
}
