<?php

namespace App\Services\Gmail;

use App\Models\User;
use App\Models\UserGmailConnection;
use Illuminate\Support\Facades\Log;

class GmailOAuthService
{
    public function __construct(
        protected GmailApiClient $client,
    ) {}

    /**
     * Get the OAuth authorization URL.
     */
    public function getAuthorizationUrl(?string $state = null): string
    {
        $googleClient = $this->client->getClient();

        if ($state) {
            $googleClient->setState($state);
        }

        return $googleClient->createAuthUrl();
    }

    /**
     * Exchange the authorization code for tokens and create/update the connection.
     */
    public function handleCallback(User $user, string $code): UserGmailConnection
    {
        // Exchange code for tokens
        $token = $this->client->exchangeCode($code);

        // Set token to get user email
        $this->client->setAccessToken($token['access_token']);
        $email = $this->client->getUserEmail();

        // Calculate expiration time
        $expiresAt = now()->addSeconds($token['expires_in'] ?? 3600);

        // Create or update the connection
        $connection = UserGmailConnection::updateOrCreate(
            ['user_id' => $user->id],
            [
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'] ?? null,
                'token_expires_at' => $expiresAt,
                'email' => $email,
                'status' => 'active',
                'scopes' => config('gmail.scopes'),
            ]
        );

        Log::info('Gmail connection established', [
            'user_id' => $user->id,
            'email' => $email,
        ]);

        return $connection;
    }

    /**
     * Refresh the access token if needed.
     */
    public function refreshIfNeeded(UserGmailConnection $connection): bool
    {
        if (! $connection->needsRefresh()) {
            return true;
        }

        return $this->refreshToken($connection);
    }

    /**
     * Refresh the access token.
     */
    public function refreshToken(UserGmailConnection $connection): bool
    {
        if (! $connection->refresh_token) {
            Log::warning('Cannot refresh Gmail token - no refresh token', [
                'user_id' => $connection->user_id,
            ]);

            return false;
        }

        try {
            $newToken = $this->client->refreshToken($connection->refresh_token);

            $expiresAt = now()->addSeconds($newToken['expires_in'] ?? 3600);

            $connection->updateTokens(
                $newToken['access_token'],
                $newToken['refresh_token'] ?? null,
                $expiresAt
            );

            Log::info('Gmail token refreshed', [
                'user_id' => $connection->user_id,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Gmail token refresh failed', [
                'user_id' => $connection->user_id,
                'error' => $e->getMessage(),
            ]);

            // Mark as expired if refresh fails
            $connection->markExpired();

            return false;
        }
    }

    /**
     * Disconnect Gmail and revoke tokens.
     */
    public function disconnect(UserGmailConnection $connection): bool
    {
        try {
            // Try to revoke the token
            $this->client->revokeToken($connection->access_token);
        } catch (\Exception $e) {
            // Continue even if revoke fails - token might already be invalid
            Log::warning('Gmail token revoke failed', [
                'user_id' => $connection->user_id,
                'error' => $e->getMessage(),
            ]);
        }

        // Delete the connection
        $connection->delete();

        Log::info('Gmail disconnected', [
            'user_id' => $connection->user_id,
        ]);

        return true;
    }

    /**
     * Validate that a connection is still usable.
     */
    public function validateConnection(UserGmailConnection $connection): bool
    {
        // Refresh if needed
        if (! $this->refreshIfNeeded($connection)) {
            return false;
        }

        try {
            // Try to get user email to verify token works
            $this->client->setAccessToken($connection->access_token);
            $this->client->getUserEmail();

            return true;
        } catch (\Exception $e) {
            Log::warning('Gmail connection validation failed', [
                'user_id' => $connection->user_id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get a configured Gmail client for a connection.
     */
    public function getClientForConnection(UserGmailConnection $connection): GmailApiClient
    {
        $this->refreshIfNeeded($connection);
        $this->client->setAccessToken($connection->access_token);

        return $this->client;
    }
}
