<?php

namespace App\Services\Scraper;

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
    ) {
        $this->commonPaths = config('scraper.discovery.common_paths', []);
        $this->policyKeywords = config('scraper.discovery.policy_keywords', []);
        $this->typePatterns = config('scraper.discovery.type_patterns', []);
        $this->maxDepth = config('scraper.discovery.max_depth', 3);
        $this->maxPages = config('scraper.discovery.max_pages', 100);
    }

    /**
     * Discover policy documents on a website.
     */
    public function discover(Website $website): DiscoveryResult
    {
        $discoveredPolicies = [];
        $urlsCrawled = 0;
        $visitedUrls = [];
        $baseUrl = $website->base_url;

        try {
            // 1. Parse robots.txt
            $robotsTxt = $this->parseRobotsTxt($baseUrl);

            // 2. Check sitemap for policy URLs
            $sitemapUrls = $this->discoverFromSitemaps($baseUrl, $robotsTxt);
            foreach ($sitemapUrls['policies'] ?? [] as $policy) {
                $discoveredPolicies[$policy->url] = $policy;
            }

            // 3. Check common paths
            $commonPathResults = $this->checkCommonPaths($baseUrl, $visitedUrls);
            $urlsCrawled += $commonPathResults['crawled'];
            foreach ($commonPathResults['policies'] as $policy) {
                $discoveredPolicies[$policy->url] = $policy;
            }

            // 4. Crawl homepage for links
            $crawlResults = $this->crawlForPolicyLinks($website->url, $baseUrl, $visitedUrls);
            $urlsCrawled += $crawlResults['crawled'];
            foreach ($crawlResults['policies'] as $policy) {
                if (!isset($discoveredPolicies[$policy->url])) {
                    $discoveredPolicies[$policy->url] = $policy;
                }
            }

            // Convert to array
            $policies = array_map(fn ($p) => $p->toArray(), array_values($discoveredPolicies));

            return DiscoveryResult::success(
                discoveredPolicies: $policies,
                urlsCrawled: $urlsCrawled,
                sitemapUrls: $sitemapUrls['all'] ?? [],
                robotsTxt: $robotsTxt,
            );

        } catch (\Exception $e) {
            return DiscoveryResult::failure($e->getMessage());
        }
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
    protected function discoverFromSitemaps(string $baseUrl, ?array $robotsTxt): array
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
    protected function checkCommonPaths(string $baseUrl, array &$visitedUrls): array
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

            try {
                $response = $this->httpClient->get($url);
                $result['crawled']++;

                if ($response->successful()) {
                    $type = $this->detectDocumentType($url);

                    $result['policies'][] = new DiscoveredPolicy(
                        url: $url,
                        detectedType: $type['slug'] ?? null,
                        documentTypeId: $type['id'] ?? null,
                        confidence: 0.9,
                        discoveryMethod: 'common_paths',
                    );
                }
            } catch (\Exception $e) {
                // URL not accessible - continue
            }
        }

        return $result;
    }

    /**
     * Crawl a page for links to policy documents.
     */
    protected function crawlForPolicyLinks(string $url, string $baseUrl, array &$visitedUrls): array
    {
        $result = [
            'crawled' => 0,
            'policies' => [],
        ];

        if (isset($visitedUrls[$url])) {
            return $result;
        }

        $visitedUrls[$url] = true;

        try {
            $response = $this->httpClient->get($url);
            $result['crawled']++;

            if (!$response->successful()) {
                return $result;
            }

            $html = $response->body();
            $links = $this->extractor->extractLinks($html, $baseUrl);

            foreach ($links as $link) {
                $linkUrl = $link['url'];
                $linkText = $link['text'];

                // Check if link is likely a policy link
                if ($this->isPolicyUrl($linkUrl) || $this->isPolicyLinkText($linkText)) {
                    // Verify the URL is accessible
                    if (!isset($visitedUrls[$linkUrl])) {
                        $visitedUrls[$linkUrl] = true;

                        try {
                            $linkResponse = $this->httpClient->get($linkUrl);
                            $result['crawled']++;

                            if ($linkResponse->successful()) {
                                $type = $this->detectDocumentType($linkUrl, $linkText);

                                $result['policies'][] = new DiscoveredPolicy(
                                    url: $linkUrl,
                                    detectedType: $type['slug'] ?? null,
                                    documentTypeId: $type['id'] ?? null,
                                    confidence: 0.8,
                                    discoveryMethod: 'crawl',
                                    linkText: $linkText,
                                );
                            }
                        } catch (\Exception $e) {
                            // Link not accessible
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // Crawl failed
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
