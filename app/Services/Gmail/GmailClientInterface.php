<?php

namespace App\Services\Gmail;

interface GmailClientInterface
{
    /**
     * Set the access token for API requests.
     */
    public function setAccessToken(string $accessToken): void;

    /**
     * Search for messages matching the query.
     *
     * @return array Array of message metadata (id, threadId)
     */
    public function searchMessages(string $query, int $maxResults = 100): array;

    /**
     * Get full message details by ID.
     *
     * @return array Message data including headers and body
     */
    public function getMessage(string $messageId): array;

    /**
     * Get the authenticated user's email address.
     */
    public function getUserEmail(): string;

    /**
     * Refresh an expired access token.
     *
     * @return array New token data (access_token, expires_in, etc.)
     */
    public function refreshToken(string $refreshToken): array;

    /**
     * Revoke OAuth access.
     */
    public function revokeToken(string $token): bool;
}
