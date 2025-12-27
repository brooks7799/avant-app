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
    protected array $policyUrlPatterns;
    protected array $policyLinkKeywords;
    protected array $excludedUrlPatterns;
    protected array $excludedLanguagePatterns;
    protected array $typePatterns;
    protected int $maxDepth;
    protected int $maxPages;

    public function __construct(
        protected HttpClientService $httpClient,
        protected ContentExtractorService $extractor,
        protected BrowserRendererService $browserRenderer,
    ) {
        $this->commonPaths = config('scraper.discovery.common_paths', []);
        $this->policyUrlPatterns = config('scraper.discovery.policy_url_patterns', []);
        $this->policyLinkKeywords = config('scraper.discovery.policy_link_keywords', []);
        $this->excludedUrlPatterns = config('scraper.discovery.excluded_url_patterns', []);
        $this->excludedLanguagePatterns = config('scraper.discovery.excluded_language_patterns', []);
        $this->typePatterns = config('scraper.discovery.type_patterns', []);
        $this->maxDepth = config('scraper.discovery.max_depth', 2);
        $this->maxPages = config('scraper.discovery.max_pages', 50);
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
     * Update job counters in real-time.
     */
    protected function updateJobProgress(?DiscoveryJob $job, int $urlsCrawled, int $policiesFound): void
    {
        if ($job) {
            $job->update([
                'urls_crawled' => $urlsCrawled,
                'policies_found' => $policiesFound,
            ]);
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
            $this->updateJobProgress($job, $urlsCrawled, count($discoveredPolicies));

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
            $this->updateJobProgress($job, $urlsCrawled, count($discoveredPolicies));

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
            $this->updateJobProgress($job, $urlsCrawled, count($discoveredPolicies));

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
            $excludedCount = 0;
            foreach ($urls as $url) {
                // Skip excluded URLs first
                if ($this->isExcludedUrl($url)) {
                    $excludedCount++;
                    continue;
                }

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

            if ($excludedCount > 0) {
                $job?->logInfo("Filtered out {$excludedCount} non-policy URLs from sitemap");
            }
        }

        return $result;
    }

    /**
     * Parse sitemap XML and extract policy-related URLs only.
     * Uses regex instead of DOM to avoid memory issues with large sitemaps.
     * Only extracts URLs that match policy patterns.
     */
    protected function parseSitemapXml(string $xml, int $depth = 0): array
    {
        $urls = [];
        $maxPolicyUrls = 50; // We only care about policy URLs
        $maxSitemapDepth = 1; // Limit recursion to avoid memory issues

        // Quick check - if XML is too large (>5MB), skip to avoid memory issues
        if (strlen($xml) > 5 * 1024 * 1024) {
            return $urls;
        }

        try {
            // Handle sitemap index - look for nested sitemaps
            if ($depth < $maxSitemapDepth && str_contains($xml, '<sitemap>')) {
                // Only look for sitemaps that might contain policy pages
                // e.g., "sitemap-legal", "sitemap-pages", etc.
                $policyRelatedPatterns = ['legal', 'policy', 'policies', 'pages', 'misc', 'other'];

                preg_match_all('/<loc>\s*([^<]+)\s*<\/loc>/i', $xml, $matches);
                $sitemapCount = 0;
                $maxSubSitemaps = 3;

                foreach ($matches[1] ?? [] as $sitemapUrl) {
                    if ($sitemapCount >= $maxSubSitemaps || count($urls) >= $maxPolicyUrls) {
                        break;
                    }

                    $sitemapUrl = trim($sitemapUrl);

                    // Skip non-English/regional sitemaps
                    if ($this->isExcludedUrl($sitemapUrl)) {
                        continue;
                    }

                    // Prioritize policy-related sitemaps
                    $isPolicyRelated = false;
                    foreach ($policyRelatedPatterns as $pattern) {
                        if (stripos($sitemapUrl, $pattern) !== false) {
                            $isPolicyRelated = true;
                            break;
                        }
                    }

                    // Only fetch policy-related sub-sitemaps or first 3
                    if ($isPolicyRelated || $sitemapCount < 3) {
                        $subContent = $this->httpClient->fetchSitemap($sitemapUrl);
                        if ($subContent) {
                            $subUrls = $this->parseSitemapXml($subContent, $depth + 1);
                            $urls = array_merge($urls, $subUrls);
                            $sitemapCount++;
                        }
                    }
                }
            }

            // Extract URLs using regex (much faster than DOM for large files)
            preg_match_all('/<loc>\s*([^<]+)\s*<\/loc>/i', $xml, $matches);

            foreach ($matches[1] ?? [] as $url) {
                if (count($urls) >= $maxPolicyUrls) {
                    break;
                }

                $url = trim($url);

                // Skip if excluded
                if ($this->isExcludedUrl($url)) {
                    continue;
                }

                // Only keep URLs that look like policy pages
                if ($this->isPolicyUrl($url)) {
                    $urls[] = $url;
                }
            }
        } catch (\Exception $e) {
            // Parsing failed - continue without sitemap data
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
     * Check if a URL should be excluded based on patterns.
     */
    protected function isExcludedUrl(string $url): bool
    {
        $url = strtolower($url);

        // Check excluded URL patterns (product pages, categories, etc.)
        foreach ($this->excludedUrlPatterns as $pattern) {
            if (str_contains($url, strtolower($pattern))) {
                return true;
            }
        }

        // Check non-English language patterns
        foreach ($this->excludedLanguagePatterns as $pattern) {
            if (str_contains($url, strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a URL looks like a policy URL.
     * More restrictive - requires matching specific policy path patterns.
     */
    protected function isPolicyUrl(string $url): bool
    {
        $url = strtolower($url);

        // First check if URL should be excluded
        if ($this->isExcludedUrl($url)) {
            return false;
        }

        // Check if URL matches any policy URL pattern
        foreach ($this->policyUrlPatterns as $pattern) {
            if (str_contains($url, strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if link text suggests a policy link.
     * More restrictive - requires matching specific phrases.
     */
    protected function isPolicyLinkText(string $text): bool
    {
        $text = strtolower(trim($text));

        // Must match one of the specific policy link keywords
        foreach ($this->policyLinkKeywords as $keyword) {
            if (str_contains($text, strtolower($keyword))) {
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
