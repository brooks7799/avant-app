<?php

namespace App\Services\Gmail;

use Google\Client as GoogleClient;
use Google\Service\Gmail;
use Google\Service\Gmail\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class GmailApiClient implements GmailClientInterface
{
    protected GoogleClient $client;

    protected ?Gmail $gmail = null;

    public function __construct()
    {
        $this->client = new GoogleClient;
        $this->client->setClientId(config('gmail.client_id'));
        $this->client->setClientSecret(config('gmail.client_secret'));
        $this->client->setRedirectUri(config('gmail.redirect_uri'));
        $this->client->setAccessType('offline');
        $this->client->setPrompt('consent');
        $this->client->setScopes(config('gmail.scopes'));
    }

    public function setAccessToken(string $accessToken): void
    {
        $this->client->setAccessToken($accessToken);
        $this->gmail = new Gmail($this->client);
    }

    public function searchMessages(string $query, int $maxResults = 100): array
    {
        $this->ensureGmailService();
        $this->checkRateLimit();

        $messages = [];
        $pageToken = null;
        $fetched = 0;

        try {
            do {
                $params = [
                    'q' => $query,
                    'maxResults' => min($maxResults - $fetched, 100),
                ];

                if ($pageToken) {
                    $params['pageToken'] = $pageToken;
                }

                $response = $this->gmail->users_messages->listUsersMessages('me', $params);

                if ($response->getMessages()) {
                    foreach ($response->getMessages() as $message) {
                        $messages[] = [
                            'id' => $message->getId(),
                            'threadId' => $message->getThreadId(),
                        ];
                        $fetched++;

                        if ($fetched >= $maxResults) {
                            break 2;
                        }
                    }
                }

                $pageToken = $response->getNextPageToken();
            } while ($pageToken && $fetched < $maxResults);

        } catch (\Exception $e) {
            Log::error('Gmail search failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return $messages;
    }

    public function getMessage(string $messageId): array
    {
        $this->ensureGmailService();
        $this->checkRateLimit();

        try {
            $message = $this->gmail->users_messages->get('me', $messageId, [
                'format' => 'full',
            ]);

            return $this->parseMessage($message);
        } catch (\Exception $e) {
            Log::error('Gmail get message failed', [
                'messageId' => $messageId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function getUserEmail(): string
    {
        $this->ensureGmailService();

        try {
            $profile = $this->gmail->users->getProfile('me');

            return $profile->getEmailAddress();
        } catch (\Exception $e) {
            Log::error('Gmail get profile failed', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function refreshToken(string $refreshToken): array
    {
        try {
            $this->client->fetchAccessTokenWithRefreshToken($refreshToken);
            $token = $this->client->getAccessToken();

            return [
                'access_token' => $token['access_token'],
                'expires_in' => $token['expires_in'] ?? 3600,
                'refresh_token' => $token['refresh_token'] ?? $refreshToken,
            ];
        } catch (\Exception $e) {
            Log::error('Gmail token refresh failed', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function revokeToken(string $token): bool
    {
        try {
            return $this->client->revokeToken($token);
        } catch (\Exception $e) {
            Log::error('Gmail token revoke failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get the Google Client for OAuth flow.
     */
    public function getClient(): GoogleClient
    {
        return $this->client;
    }

    /**
     * Exchange authorization code for tokens.
     */
    public function exchangeCode(string $code): array
    {
        try {
            $token = $this->client->fetchAccessTokenWithAuthCode($code);

            if (isset($token['error'])) {
                throw new \Exception($token['error_description'] ?? $token['error']);
            }

            return $token;
        } catch (\Exception $e) {
            Log::error('Gmail code exchange failed', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Parse a Gmail message into a usable array.
     */
    protected function parseMessage(Message $message): array
    {
        $headers = [];
        $payload = $message->getPayload();

        if ($payload && $payload->getHeaders()) {
            foreach ($payload->getHeaders() as $header) {
                $headers[$header->getName()] = $header->getValue();
            }
        }

        $body = '';
        if ($payload) {
            $body = $this->extractMessageBody($payload);
        }

        return [
            'id' => $message->getId(),
            'threadId' => $message->getThreadId(),
            'snippet' => $message->getSnippet(),
            'internalDate' => $message->getInternalDate(),
            'headers' => $headers,
            'from' => $headers['From'] ?? '',
            'to' => $headers['To'] ?? '',
            'subject' => $headers['Subject'] ?? '',
            'date' => $headers['Date'] ?? '',
            'body' => $body,
        ];
    }

    /**
     * Extract the message body from payload.
     */
    protected function extractMessageBody($payload): string
    {
        $body = '';

        // Try to get body from payload directly
        if ($payload->getBody() && $payload->getBody()->getData()) {
            $body = $this->decodeBody($payload->getBody()->getData());
        }

        // If multipart, look for text/html or text/plain parts
        if ($payload->getParts()) {
            foreach ($payload->getParts() as $part) {
                $mimeType = $part->getMimeType();

                if ($mimeType === 'text/html' && $part->getBody() && $part->getBody()->getData()) {
                    $body = $this->decodeBody($part->getBody()->getData());
                    break;
                }

                if ($mimeType === 'text/plain' && empty($body) && $part->getBody() && $part->getBody()->getData()) {
                    $body = $this->decodeBody($part->getBody()->getData());
                }

                // Handle nested multipart
                if ($part->getParts()) {
                    foreach ($part->getParts() as $subPart) {
                        $subMimeType = $subPart->getMimeType();
                        if ($subMimeType === 'text/html' && $subPart->getBody() && $subPart->getBody()->getData()) {
                            $body = $this->decodeBody($subPart->getBody()->getData());
                            break 2;
                        }
                    }
                }
            }
        }

        return $body;
    }

    /**
     * Decode base64url encoded body.
     */
    protected function decodeBody(string $data): string
    {
        $data = str_replace(['-', '_'], ['+', '/'], $data);

        return base64_decode($data);
    }

    protected function ensureGmailService(): void
    {
        if (! $this->gmail) {
            throw new \RuntimeException('Gmail service not initialized. Call setAccessToken() first.');
        }
    }

    protected function checkRateLimit(): void
    {
        $key = 'gmail-api:'.auth()->id();

        $executed = RateLimiter::attempt(
            $key,
            config('gmail.rate_limits.requests_per_second', 10),
            fn () => true,
            1
        );

        if (! $executed) {
            $seconds = RateLimiter::availableIn($key);
            usleep($seconds * 1000000);
        }
    }
}
