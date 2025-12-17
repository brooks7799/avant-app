<?php

namespace App\Services\Scraper;

use App\Models\DiscoveryJob;
use App\Models\DocumentType;
use App\Models\Website;
use App\Services\Scraper\DTO\DiscoveredPolicy;
use App\Services\Scraper\DTO\DiscoveryResult;
use Illuminate\Support\Str;

class PolicyDiscoveryService
{
    protected array $commonPaths;
    protected array $policyKeywords;
    protected array $typePatterns;
    protected int $maxDepth;
    protected int $maxPages;

    public function __construct(
        protected HttpClientService $httpClient,
        protected ContentExtractorService $extractor,
        protected BrowserRendererService $browserRenderer,
    ) {
        $this->commonPaths = config('scraper.discovery.common_paths', []);
        $this->policyKeywords = config('scraper.discovery.policy_keywords', []);
        $this->typePatterns = config('scraper.discovery.type_patterns', []);
        $this->maxDepth = config('scraper.discovery.max_depth', 3);
        $this->maxPages = config('scraper.discovery.max_pages', 100);
    }

    /**
     * Fetch a URL, trying HTTP first then falling back to browser if blocked.
     */
    protected function fetchUrl(string $url, ?DiscoveryJob $job = null): ?string
    {
        // First try HTTP
        try {
            $response = $this->httpClient->get($url);

            if ($response->successful()) {
                return $response->body();
            }

            // If 403/401, try browser rendering
            if ($response->status() === 403 || $response->status() === 401) {
                $job?->logInfo("HTTP blocked (status {$response->status()}), trying headless browser...");
                return $this->fetchWithBrowser($url, $job);
            }

            return null;
        } catch (\Exception $e) {
            // HTTP failed, try browser
            $job?->logInfo("HTTP failed ({$e->getMessage()}), trying headless browser...");
            return $this->fetchWithBrowser($url, $job);
        }
    }

    /**
     * Fetch a URL using headless browser.
     */
    protected function fetchWithBrowser(string $url, ?DiscoveryJob $job = null): ?string
    {
        $browser = $this->browserRenderer->getBestAvailableBrowser();
        $job?->logInfo("Using {$browser} browser to fetch {$url}");

        $result = $this->browserRenderer->render($url, [
            'browser' => $browser,
            'timeout' => 30000,
            'waitUntil' => 'networkidle2',
        ]);

        if ($result->success && $result->html) {
            $job?->logSuccess("Browser fetch successful");
            return $result->html;
        }

        $job?->logError("Browser fetch failed: " . ($result->error ?? 'Unknown error'));
        return null;
    }

    /**
     * Check if a URL is accessible (returns true/false).
     * Uses HTTP only for speed - browser fallback only for homepage.
     */
    protected function isUrlAccessible(string $url, ?DiscoveryJob $job = null): bool
    {
        try {
            $response = $this->httpClient->get($url);
            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Discover policy documents on a website.
     */
    public function discover(Website $website, ?DiscoveryJob $job = null): DiscoveryResult
    {
        $discoveredPolicies = [];
        $urlsCrawled = 0;
        $visitedUrls = [];
        $baseUrl = $website->base_url;

        $job?->logInfo("Starting discovery for {$website->url}");

        try {
            // 1. Parse robots.txt
            $job?->logInfo('Parsing robots.txt...');
            $robotsTxt = $this->parseRobotsTxt($baseUrl);
            if ($robotsTxt) {
                $sitemapCount = count($robotsTxt['sitemaps'] ?? []);
                $job?->logSuccess("Found robots.txt with {$sitemapCount} sitemap(s)");
            } else {
                $job?->logInfo('No robots.txt found');
            }

            // 2. Check sitemap for policy URLs
            $job?->logInfo('Checking sitemaps for policy URLs...');
            $sitemapUrls = $this->discoverFromSitemaps($baseUrl, $robotsTxt, $job);
            foreach ($sitemapUrls['policies'] ?? [] as $policy) {
                $normalizedUrl = $this->normalizeUrl($policy->url);
                if (!isset($discoveredPolicies[$normalizedUrl])) {
                    $discoveredPolicies[$normalizedUrl] = $policy;
                }
            }

            // 3. Check common paths
            $job?->logInfo('Checking common policy paths...');
            $commonPathResults = $this->checkCommonPaths($baseUrl, $visitedUrls, $job);
            $urlsCrawled += $commonPathResults['crawled'];
            foreach ($commonPathResults['policies'] as $policy) {
                $normalizedUrl = $this->normalizeUrl($policy->url);
                if (!isset($discoveredPolicies[$normalizedUrl])) {
                    $discoveredPolicies[$normalizedUrl] = $policy;
                }
            }

            // 4. Crawl homepage for links
            $job?->logInfo('Crawling homepage for policy links...');
            $crawlResults = $this->crawlForPolicyLinks($website->url, $baseUrl, $visitedUrls, $job);
            $urlsCrawled += $crawlResults['crawled'];
            foreach ($crawlResults['policies'] as $policy) {
                $normalizedUrl = $this->normalizeUrl($policy->url);
                if (!isset($discoveredPolicies[$normalizedUrl])) {
                    $discoveredPolicies[$normalizedUrl] = $policy;
                }
            }

            // Convert to array
            $policies = array_map(fn ($p) => $p->toArray(), array_values($discoveredPolicies));

            $policyCount = count($policies);
            $job?->logSuccess("Discovery complete: found {$policyCount} policies, crawled {$urlsCrawled} URLs");

            return DiscoveryResult::success(
                discoveredPolicies: $policies,
                urlsCrawled: $urlsCrawled,
                sitemapUrls: $sitemapUrls['all'] ?? [],
                robotsTxt: $robotsTxt,
            );

        } catch (\Exception $e) {
            $job?->logError("Discovery failed: {$e->getMessage()}");
            return DiscoveryResult::failure($e->getMessage());
        }
    }

    /**
     * Normalize a URL for deduplication.
     * Removes trailing slashes, query strings, fragments, and normalizes protocol.
     */
    protected function normalizeUrl(string $url): string
    {
        // Parse the URL
        $parsed = parse_url($url);

        if (!$parsed || !isset($parsed['host'])) {
            return $url;
        }

        // Normalize scheme to https
        $scheme = 'https';

        // Normalize host (lowercase)
        $host = strtolower($parsed['host']);

        // Remove www prefix for consistency
        $host = preg_replace('/^www\./', '', $host);

        // Normalize path (remove trailing slash, lowercase)
        $path = $parsed['path'] ?? '/';
        $path = rtrim($path, '/');
        if (empty($path)) {
            $path = '/';
        }

        // Rebuild the normalized URL (without query string or fragment)
        return "{$scheme}://{$host}{$path}";
    }

    /**
     * Parse robots.txt for sitemap URLs and disallow rules.
     */
    protected function parseRobotsTxt(string $baseUrl): ?array
    {
        $content = $this->httpClient->fetchRobotsTxt($baseUrl);

        if (!$content) {
            return null;
        }

        $result = [
            'sitemaps' => [],
            'disallow' => [],
            'allow' => [],
        ];

        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $line = trim($line);

            if (str_starts_with(strtolower($line), 'sitemap:')) {
                $result['sitemaps'][] = trim(substr($line, 8));
            } elseif (str_starts_with(strtolower($line), 'disallow:')) {
                $result['disallow'][] = trim(substr($line, 9));
            } elseif (str_starts_with(strtolower($line), 'allow:')) {
                $result['allow'][] = trim(substr($line, 6));
            }
        }

        return $result;
    }

    /**
     * Discover policy URLs from sitemaps.
     */
    protected function discoverFromSitemaps(string $baseUrl, ?array $robotsTxt, ?DiscoveryJob $job = null): array
    {
        $result = [
            'all' => [],
            'policies' => [],
        ];

        // Get sitemap URLs from robots.txt or try default locations
        $sitemapUrls = $robotsTxt['sitemaps'] ?? [];

        if (empty($sitemapUrls)) {
            $sitemapUrls = [
                $baseUrl . '/sitemap.xml',
                $baseUrl . '/sitemap_index.xml',
            ];
        }

        foreach ($sitemapUrls as $sitemapUrl) {
            $content = $this->httpClient->fetchSitemap($sitemapUrl);

            if (!$content) {
                continue;
            }

            $job?->logInfo("Processing sitemap: {$sitemapUrl}");

            // Parse sitemap XML
            $urls = $this->parseSitemapXml($content);
            $result['all'] = array_merge($result['all'], $urls);

            // Check each URL for policy keywords
            foreach ($urls as $url) {
                if ($this->isPolicyUrl($url)) {
                    $type = $this->detectDocumentType($url);
                    $result['policies'][] = new DiscoveredPolicy(
                        url: $url,
                        detectedType: $type['slug'] ?? null,
                        documentTypeId: $type['id'] ?? null,
                        confidence: 0.7,
                        discoveryMethod: 'sitemap',
                    );
                    $typeLabel = $type['slug'] ?? 'unknown';
                    $job?->logSuccess("Found policy in sitemap: {$typeLabel} at {$url}", ['url' => $url, 'type' => $typeLabel]);
                }
            }
        }

        return $result;
    }

    /**
     * Parse sitemap XML and extract URLs.
     */
    protected function parseSitemapXml(string $xml): array
    {
        $urls = [];

        try {
            $doc = new \DOMDocument();
            @$doc->loadXML($xml);

            // Handle sitemap index
            $sitemaps = $doc->getElementsByTagName('sitemap');
            foreach ($sitemaps as $sitemap) {
                $loc = $sitemap->getElementsByTagName('loc')->item(0);
                if ($loc) {
                    // Recursively fetch sub-sitemaps (limited)
                    $subContent = $this->httpClient->fetchSitemap($loc->textContent);
                    if ($subContent) {
                        $urls = array_merge($urls, $this->parseSitemapXml($subContent));
                    }
                }
            }

            // Handle regular sitemap
            $urlNodes = $doc->getElementsByTagName('url');
            foreach ($urlNodes as $urlNode) {
                $loc = $urlNode->getElementsByTagName('loc')->item(0);
                if ($loc) {
                    $urls[] = $loc->textContent;
                }
            }
        } catch (\Exception $e) {
            // XML parsing failed
        }

        return array_unique($urls);
    }

    /**
     * Check common paths for policy documents.
     */
    protected function checkCommonPaths(string $baseUrl, array &$visitedUrls, ?DiscoveryJob $job = null): array
    {
        $result = [
            'crawled' => 0,
            'policies' => [],
        ];

        foreach ($this->commonPaths as $path) {
            $url = rtrim($baseUrl, '/') . $path;

            if (isset($visitedUrls[$url])) {
                continue;
            }

            $visitedUrls[$url] = true;
            $result['crawled']++;

            if ($this->isUrlAccessible($url, $job)) {
                $type = $this->detectDocumentType($url);

                $result['policies'][] = new DiscoveredPolicy(
                    url: $url,
                    detectedType: $type['slug'] ?? null,
                    documentTypeId: $type['id'] ?? null,
                    confidence: 0.9,
                    discoveryMethod: 'common_paths',
                );
                $typeLabel = $type['slug'] ?? 'unknown';
                $job?->logSuccess("Found policy at common path: {$typeLabel} at {$url}", ['url' => $url, 'type' => $typeLabel]);
            }
        }

        return $result;
    }

    /**
     * Crawl a page for links to policy documents.
     */
    protected function crawlForPolicyLinks(string $url, string $baseUrl, array &$visitedUrls, ?DiscoveryJob $job = null): array
    {
        $result = [
            'crawled' => 0,
            'policies' => [],
        ];

        if (isset($visitedUrls[$url])) {
            return $result;
        }

        $visitedUrls[$url] = true;
        $result['crawled']++;

        $html = $this->fetchUrl($url, $job);

        if (!$html) {
            return $result;
        }

        $links = $this->extractor->extractLinks($html, $baseUrl);
        $job?->logInfo("Found " . count($links) . " links on page");

        foreach ($links as $link) {
            $linkUrl = $link['url'];
            $linkText = $link['text'];

            // Check if link is likely a policy link
            if ($this->isPolicyUrl($linkUrl) || $this->isPolicyLinkText($linkText)) {
                // Verify the URL is accessible
                if (!isset($visitedUrls[$linkUrl])) {
                    $visitedUrls[$linkUrl] = true;
                    $result['crawled']++;

                    if ($this->isUrlAccessible($linkUrl, $job)) {
                        $type = $this->detectDocumentType($linkUrl, $linkText);

                        $result['policies'][] = new DiscoveredPolicy(
                            url: $linkUrl,
                            detectedType: $type['slug'] ?? null,
                            documentTypeId: $type['id'] ?? null,
                            confidence: 0.8,
                            discoveryMethod: 'crawl',
                            linkText: $linkText,
                        );
                        $typeLabel = $type['slug'] ?? 'unknown';
                        $job?->logSuccess("Found policy via crawl: {$typeLabel} at {$linkUrl}", ['url' => $linkUrl, 'type' => $typeLabel]);
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Check if a URL looks like a policy URL.
     */
    protected function isPolicyUrl(string $url): bool
    {
        $url = strtolower($url);

        foreach ($this->policyKeywords as $keyword) {
            if (str_contains($url, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if link text suggests a policy link.
     */
    protected function isPolicyLinkText(string $text): bool
    {
        $text = strtolower($text);

        foreach ($this->policyKeywords as $keyword) {
            if (str_contains($text, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect the document type based on URL and text.
     */
    protected function detectDocumentType(string $url, ?string $linkText = null): array
    {
        $url = strtolower($url);
        $text = strtolower($linkText ?? '');

        foreach ($this->typePatterns as $typeSlug => $patterns) {
            foreach ($patterns as $pattern) {
                if (str_contains($url, $pattern) || str_contains($text, $pattern)) {
                    // Look up the document type
                    $type = DocumentType::where('slug', $typeSlug)->first();

                    return [
                        'slug' => $typeSlug,
                        'id' => $type?->id,
                    ];
                }
            }
        }

        return [];
    }
}
