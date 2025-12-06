<?php

namespace App\Services\Scraper;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

class HttpClientService
{
    protected string $userAgent;
    protected int $connectTimeout;
    protected int $requestTimeout;
    protected int $rateLimitPerSecond;
    protected int $delayMs;

    public function __construct()
    {
        $this->userAgent = config('scraper.user_agent');
        $this->connectTimeout = config('scraper.timeouts.connect', 10);
        $this->requestTimeout = config('scraper.timeouts.request', 30);
        $this->rateLimitPerSecond = config('scraper.rate_limiting.requests_per_second', 2);
        $this->delayMs = config('scraper.rate_limiting.delay_between_requests_ms', 500);
    }

    /**
     * Make a GET request with rate limiting and proper headers.
     */
    public function get(string $url, array $headers = []): Response
    {
        $this->applyRateLimit($url);

        return Http::withHeaders(array_merge([
            'User-Agent' => $this->userAgent,
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.5',
            'Accept-Encoding' => 'gzip, deflate',
            'Connection' => 'keep-alive',
        ], $headers))
            ->connectTimeout($this->connectTimeout)
            ->timeout($this->requestTimeout)
            ->withOptions([
                'allow_redirects' => [
                    'max' => 5,
                    'track_redirects' => true,
                ],
            ])
            ->retry(
                config('scraper.retry.max_attempts', 3),
                config('scraper.retry.delay_ms', 1000),
                function ($exception, $request) {
                    // Retry on connection errors and 5xx responses
                    return $exception instanceof \Illuminate\Http\Client\ConnectionException
                        || ($exception instanceof \Illuminate\Http\Client\RequestException
                            && $exception->response?->serverError());
                }
            )
            ->get($url);
    }

    /**
     * Fetch robots.txt for a domain.
     */
    public function fetchRobotsTxt(string $baseUrl): ?string
    {
        try {
            $response = $this->get(rtrim($baseUrl, '/') . '/robots.txt');

            if ($response->successful()) {
                return $response->body();
            }
        } catch (\Exception $e) {
            // Robots.txt not found or error - that's okay
        }

        return null;
    }

    /**
     * Fetch sitemap.xml for a domain.
     */
    public function fetchSitemap(string $url): ?string
    {
        try {
            $response = $this->get($url);

            if ($response->successful() && str_contains($response->header('Content-Type') ?? '', 'xml')) {
                return $response->body();
            }
        } catch (\Exception $e) {
            // Sitemap not found or error
        }

        return null;
    }

    /**
     * Apply rate limiting per domain.
     */
    protected function applyRateLimit(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        $key = 'scraper:' . $host;

        // Simple rate limiting using Laravel's RateLimiter
        RateLimiter::attempt(
            $key,
            $this->rateLimitPerSecond,
            function () {
                // Request allowed
            },
            60 // Decay time in seconds
        );

        // Add delay between requests
        if ($this->delayMs > 0) {
            usleep($this->delayMs * 1000);
        }
    }

    /**
     * Extract the final URL after redirects.
     */
    public function getFinalUrl(Response $response): ?string
    {
        $redirects = $response->handlerStats()['redirect_url'] ?? null;

        if ($redirects) {
            return $redirects;
        }

        return $response->effectiveUri()?->__toString();
    }
}
