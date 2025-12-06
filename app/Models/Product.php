<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
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
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
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
