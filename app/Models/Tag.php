<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Str;

class Tag extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'type',
        'color',
        'description',
    ];

    protected static function booted(): void
    {
        static::creating(function (Tag $tag) {
            if (empty($tag->slug)) {
                $tag->slug = Str::slug($tag->name);
            }
        });
    }

    public function companies(): MorphToMany
    {
        return $this->morphedByMany(Company::class, 'taggable');
    }

    public function documents(): MorphToMany
    {
        return $this->morphedByMany(Document::class, 'taggable');
    }

    public function documentVersions(): MorphToMany
    {
        return $this->morphedByMany(DocumentVersion::class, 'taggable');
    }

    public static function getTypes(): array
    {
        return [
            'general' => 'General',
            'industry' => 'Industry',
            'feature' => 'Feature',
            'concern' => 'Concern',
            'compliance' => 'Compliance',
        ];
    }
}
