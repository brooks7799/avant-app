<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class UserGmailConnection extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'email',
        'status',
        'last_sync_at',
        'scopes',
        'metadata',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime',
            'last_sync_at' => 'datetime',
            'scopes' => 'array',
            'metadata' => 'array',
        ];
    }

    /**
     * Encrypt the access token when setting.
     */
    protected function accessToken(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? Crypt::decryptString($value) : null,
            set: fn (?string $value) => $value ? Crypt::encryptString($value) : null,
        );
    }

    /**
     * Encrypt the refresh token when setting.
     */
    protected function refreshToken(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? Crypt::decryptString($value) : null,
            set: fn (?string $value) => $value ? Crypt::encryptString($value) : null,
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function discoveryJobs(): HasMany
    {
        return $this->hasMany(EmailDiscoveryJob::class, 'user_id', 'user_id');
    }

    /**
     * Check if the access token is expired.
     */
    public function isExpired(): bool
    {
        if (! $this->token_expires_at) {
            return false;
        }

        return $this->token_expires_at->isPast();
    }

    /**
     * Check if the token needs to be refreshed soon.
     */
    public function needsRefresh(): bool
    {
        if (! $this->token_expires_at) {
            return false;
        }

        $threshold = config('gmail.tokens.refresh_threshold_minutes', 5);

        return $this->token_expires_at->subMinutes($threshold)->isPast();
    }

    /**
     * Check if the connection is active and usable.
     */
    public function isActive(): bool
    {
        return $this->status === 'active' && ! $this->isExpired();
    }

    /**
     * Mark the connection as revoked.
     */
    public function markRevoked(): void
    {
        $this->update(['status' => 'revoked']);
    }

    /**
     * Mark the connection as expired.
     */
    public function markExpired(): void
    {
        $this->update(['status' => 'expired']);
    }

    /**
     * Update tokens after a refresh.
     */
    public function updateTokens(string $accessToken, ?string $refreshToken, ?\DateTimeInterface $expiresAt): void
    {
        $data = [
            'access_token' => $accessToken,
            'token_expires_at' => $expiresAt,
            'status' => 'active',
        ];

        if ($refreshToken) {
            $data['refresh_token'] = $refreshToken;
        }

        $this->update($data);
    }

    /**
     * Record a sync operation.
     */
    public function recordSync(): void
    {
        $this->update(['last_sync_at' => now()]);
    }

    public static function getStatuses(): array
    {
        return [
            'active' => 'Active',
            'revoked' => 'Revoked',
            'expired' => 'Expired',
        ];
    }
}
