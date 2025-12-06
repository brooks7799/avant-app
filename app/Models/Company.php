<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Company extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'website',
        'logo_url',
        'description',
        'industry',
        'headquarters_country',
        'headquarters_state',
        'is_active',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Company $company) {
            if (empty($company->slug)) {
                $company->slug = Str::slug($company->name);
            }
        });
    }

    public function websites(): HasMany
    {
        return $this->hasMany(Website::class);
    }

    public function primaryWebsite(): HasOne
    {
        return $this->hasOne(Website::class)->where('is_primary', true);
    }

    public function activeWebsites(): HasMany
    {
        return $this->websites()->where('is_active', true);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function activeProducts(): HasMany
    {
        return $this->products()->where('is_active', true);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    public function activeDocuments(): HasMany
    {
        return $this->documents()->where('is_active', true);
    }

    public function getLatestAnalysisAttribute(): ?AnalysisResult
    {
        return $this->documents()
            ->with(['currentVersion.currentAnalysis'])
            ->get()
            ->pluck('currentVersion.currentAnalysis')
            ->filter()
            ->sortByDesc('created_at')
            ->first();
    }
}
