<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscoveredEmailCompany extends Model
{
    use HasFactory;

    protected $fillable = [
        'email_discovery_job_id',
        'user_id',
        'name',
        'domain',
        'email_address',
        'detection_source',
        'confidence_score',
        'status',
        'company_id',
        'email_metadata',
        'detected_policy_urls',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'confidence_score' => 'float',
            'email_metadata' => 'array',
            'detected_policy_urls' => 'array',
            'metadata' => 'array',
        ];
    }

    public function discoveryJob(): BelongsTo
    {
        return $this->belongsTo(EmailDiscoveryJob::class, 'email_discovery_job_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Mark the company as imported.
     */
    public function markImported(Company $company): void
    {
        $this->update([
            'status' => 'imported',
            'company_id' => $company->id,
        ]);
    }

    /**
     * Mark the company as dismissed.
     */
    public function markDismissed(): void
    {
        $this->update(['status' => 'dismissed']);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isImported(): bool
    {
        return $this->status === 'imported';
    }

    public function isDismissed(): bool
    {
        return $this->status === 'dismissed';
    }

    /**
     * Get the full website URL for this company.
     */
    public function getWebsiteUrl(): string
    {
        return 'https://'.$this->domain;
    }

    /**
     * Get a confidence level string.
     */
    public function getConfidenceLevel(): string
    {
        if ($this->confidence_score >= 0.7) {
            return 'high';
        }
        if ($this->confidence_score >= 0.4) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Get detection source label.
     */
    public function getDetectionSourceLabel(): string
    {
        return match ($this->detection_source) {
            'welcome_email' => 'Welcome Email',
            'tos_update' => 'ToS Update',
            'signup_confirm' => 'Signup Confirmation',
            'subscription' => 'Newsletter Signup',
            'account' => 'Account Email',
            default => ucfirst(str_replace('_', ' ', $this->detection_source)),
        };
    }

    public static function getStatuses(): array
    {
        return [
            'pending' => 'Pending Review',
            'imported' => 'Imported',
            'dismissed' => 'Dismissed',
        ];
    }

    public static function getDetectionSources(): array
    {
        return [
            'welcome_email' => 'Welcome Email',
            'tos_update' => 'ToS Update',
            'signup_confirm' => 'Signup Confirmation',
            'subscription' => 'Newsletter Signup',
            'account' => 'Account Email',
        ];
    }
}
