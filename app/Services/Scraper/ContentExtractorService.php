<?php

namespace App\Services\Scraper;

use Symfony\Component\DomCrawler\Crawler;

class ContentExtractorService
{
    protected array $contentSelectors;
    protected array $removeSelectors;
    protected int $minContentLength;
    protected int $maxContentLength;

    public function __construct()
    {
        $this->contentSelectors = config('scraper.extraction.content_selectors', ['main', 'article', 'body']);
        $this->removeSelectors = config('scraper.extraction.remove_selectors', []);
        $this->minContentLength = config('scraper.extraction.min_content_length', 500);
        $this->maxContentLength = config('scraper.extraction.max_content_length', 5000000);
    }

    /**
     * Extract main content from HTML.
     */
    public function extract(string $html): string
    {
        $crawler = new Crawler($html);

        // Remove unwanted elements first
        $this->removeUnwantedElements($crawler);

        // Try to find main content using selectors
        $content = $this->findMainContent($crawler);

        // Clean up the content
        $content = $this->cleanContent($content);

        // Truncate if too long
        if (strlen($content) > $this->maxContentLength) {
            $content = substr($content, 0, $this->maxContentLength);
        }

        return $content;
    }

    /**
     * Convert extracted HTML to plain text.
     */
    public function toPlainText(string $html): string
    {
        $crawler = new Crawler($html);

        // Get text content, preserving some structure
        $text = $crawler->filter('body')->count() > 0
            ? $crawler->filter('body')->text()
            : strip_tags($html);

        // Clean up whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        return $text;
    }

    /**
     * Remove unwanted elements from the DOM.
     */
    protected function removeUnwantedElements(Crawler $crawler): void
    {
        foreach ($this->removeSelectors as $selector) {
            try {
                $crawler->filter($selector)->each(function (Crawler $node) {
                    $domNode = $node->getNode(0);
                    if ($domNode && $domNode->parentNode) {
                        $domNode->parentNode->removeChild($domNode);
                    }
                });
            } catch (\Exception $e) {
                // Selector not found or invalid - continue
            }
        }
    }

    /**
     * Find main content using configured selectors.
     */
    protected function findMainContent(Crawler $crawler): string
    {
        // Try each selector in order
        foreach ($this->contentSelectors as $selector) {
            try {
                $nodes = $crawler->filter($selector);
                if ($nodes->count() > 0) {
                    $html = $nodes->first()->html();
                    if (strlen(strip_tags($html)) >= $this->minContentLength) {
                        return $html;
                    }
                }
            } catch (\Exception $e) {
                // Selector not found - try next
            }
        }

        // Fallback to body content
        try {
            $body = $crawler->filter('body');
            if ($body->count() > 0) {
                return $body->html();
            }
        } catch (\Exception $e) {
            // No body element
        }

        // Last resort - return full HTML
        return $crawler->html();
    }

    /**
     * Clean up extracted HTML content.
     */
    protected function cleanContent(string $html): string
    {
        // Remove excessive whitespace
        $html = preg_replace('/\s+/', ' ', $html);

        // Remove empty elements
        $html = preg_replace('/<([a-z]+)>\s*<\/\1>/i', '', $html);

        // Remove comments
        $html = preg_replace('/<!--.*?-->/s', '', $html);

        return trim($html);
    }

    /**
     * Extract the page title.
     */
    public function extractTitle(string $html): ?string
    {
        $crawler = new Crawler($html);

        try {
            // Try <title> tag first
            $title = $crawler->filter('title');
            if ($title->count() > 0) {
                return trim($title->text());
            }

            // Try <h1> as fallback
            $h1 = $crawler->filter('h1');
            if ($h1->count() > 0) {
                return trim($h1->first()->text());
            }
        } catch (\Exception $e) {
            // Title not found
        }

        return null;
    }

    /**
     * Extract meta description.
     */
    public function extractDescription(string $html): ?string
    {
        $crawler = new Crawler($html);

        try {
            $meta = $crawler->filter('meta[name="description"]');
            if ($meta->count() > 0) {
                return $meta->attr('content');
            }
        } catch (\Exception $e) {
            // Description not found
        }

        return null;
    }

    /**
     * Extract all links from HTML.
     */
    public function extractLinks(string $html, string $baseUrl): array
    {
        $crawler = new Crawler($html);
        $links = [];

        try {
            $crawler->filter('a[href]')->each(function (Crawler $node) use (&$links, $baseUrl) {
                $href = $node->attr('href');
                $text = trim($node->text());

                if ($href) {
                    $absoluteUrl = $this->resolveUrl($href, $baseUrl);
                    if ($absoluteUrl) {
                        $links[] = [
                            'url' => $absoluteUrl,
                            'text' => $text,
                        ];
                    }
                }
            });
        } catch (\Exception $e) {
            // Error extracting links
        }

        return $links;
    }

    /**
     * Resolve a relative URL to absolute.
     */
    protected function resolveUrl(string $url, string $baseUrl): ?string
    {
        // Already absolute
        if (preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }

        // Protocol-relative
        if (str_starts_with($url, '//')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?? 'https';
            return $scheme . ':' . $url;
        }

        // Skip anchors, javascript, mailto, etc.
        if (str_starts_with($url, '#') || str_starts_with($url, 'javascript:') ||
            str_starts_with($url, 'mailto:') || str_starts_with($url, 'tel:')) {
            return null;
        }

        // Parse base URL
        $parsed = parse_url($baseUrl);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';

        if (!$host) {
            return null;
        }

        // Absolute path
        if (str_starts_with($url, '/')) {
            return "{$scheme}://{$host}{$url}";
        }

        // Relative path
        $basePath = $parsed['path'] ?? '/';
        $basePath = dirname($basePath);
        if ($basePath === '.') {
            $basePath = '/';
        }

        return "{$scheme}://{$host}" . rtrim($basePath, '/') . '/' . $url;
    }
}
