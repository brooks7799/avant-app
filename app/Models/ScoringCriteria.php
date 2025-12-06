<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ScoringCriteria extends Model
{
    use HasFactory;

    protected $table = 'scoring_criteria';

    protected $fillable = [
        'name',
        'slug',
        'category',
        'description',
        'evaluation_prompt',
        'weight',
        'is_active',
        'sort_order',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ScoringCriteria $criteria) {
            if (empty($criteria->slug)) {
                $criteria->slug = Str::slug($criteria->name);
            }
        });
    }

    public function scores(): HasMany
    {
        return $this->hasMany(DocumentScore::class);
    }

    public static function getCategories(): array
    {
        return [
            'data_collection' => 'Data Collection',
            'data_sharing' => 'Data Sharing',
            'data_retention' => 'Data Retention',
            'user_rights' => 'User Rights',
            'transparency' => 'Transparency',
            'security' => 'Security',
            'consent' => 'Consent',
            'children_privacy' => 'Children\'s Privacy',
            'international' => 'International Transfers',
            'advertising' => 'Advertising & Tracking',
        ];
    }
}
