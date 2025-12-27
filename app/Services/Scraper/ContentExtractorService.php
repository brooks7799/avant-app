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
     * Extract policy links from footer BEFORE footer is removed.
     * This captures links like "Privacy Policy", "Terms of Service" from the footer.
     */
    public function extractFooterPolicyLinks(string $html, string $baseUrl): array
    {
        $crawler = new Crawler($html);
        $policyLinks = [];

        // Policy link text patterns
        $policyPatterns = config('scraper.discovery.policy_link_keywords', [
            'privacy policy', 'privacy notice', 'terms of service', 'terms of use',
            'terms and conditions', 'cookie policy', 'legal', 'ccpa',
        ]);

        // Footer selectors to check
        $footerSelectors = ['footer', '.footer', '#footer', '[role="contentinfo"]'];

        foreach ($footerSelectors as $selector) {
            try {
                $footerNodes = $crawler->filter($selector);
                if ($footerNodes->count() === 0) continue;

                $footerNodes->filter('a[href]')->each(function (Crawler $node) use (&$policyLinks, $baseUrl, $policyPatterns) {
                    $href = $node->attr('href');
                    $text = strtolower(trim($node->text()));

                    if (!$href || strlen($text) < 3) return;

                    // Check if link text matches policy patterns
                    foreach ($policyPatterns as $pattern) {
                        if (str_contains($text, strtolower($pattern))) {
                            $absoluteUrl = $this->resolveUrl($href, $baseUrl);
                            if ($absoluteUrl) {
                                $policyLinks[] = [
                                    'url' => $absoluteUrl,
                                    'text' => trim($node->text()),
                                    'pattern_matched' => $pattern,
                                ];
                            }
                            return; // Found a match, don't check other patterns
                        }
                    }
                });
            } catch (\Exception $e) {
                // Selector not found - continue
            }
        }

        // Remove duplicates by URL
        $seen = [];
        return array_filter($policyLinks, function ($link) use (&$seen) {
            if (isset($seen[$link['url']])) return false;
            $seen[$link['url']] = true;
            return true;
        });
    }

    /**
     * Check if page appears to be a homepage rather than a policy document.
     * Returns array with 'is_homepage' bool and 'indicators' array of reasons.
     */
    public function detectHomepage(string $html, string $expectedUrl): array
    {
        $crawler = new Crawler($html);
        $indicators = [];
        $title = $this->extractTitle($html) ?? '';
        $titleLower = strtolower($title);
        $text = strip_tags($html);
        $wordCount = str_word_count($text);

        // Check 1: Page title doesn't contain policy keywords
        $policyTitleKeywords = ['privacy', 'terms', 'policy', 'legal', 'cookie', 'ccpa', 'agreement'];
        $hasPolicyTitle = false;
        foreach ($policyTitleKeywords as $keyword) {
            if (str_contains($titleLower, $keyword)) {
                $hasPolicyTitle = true;
                break;
            }
        }
        if (!$hasPolicyTitle && !empty($title)) {
            $indicators[] = "Page title '{$title}' doesn't mention policy/terms";
        }

        // Check 2: Very low word count (homepages are often visual with little text)
        if ($wordCount < 300) {
            $indicators[] = "Very low word count ({$wordCount} words)";
        }

        // Check 3: Multiple navigation menus (common on homepages)
        try {
            $navCount = $crawler->filter('nav')->count();
            $navCount += $crawler->filter('[role="navigation"]')->count();
            if ($navCount >= 3) {
                $indicators[] = "Multiple navigation elements ({$navCount} found)";
            }
        } catch (\Exception $e) {}

        // Check 4: Hero sections or carousels (homepage indicators)
        try {
            $heroIndicators = 0;
            $heroSelectors = ['.hero', '.carousel', '.slider', '.banner', '[class*="hero"]', '[class*="carousel"]'];
            foreach ($heroSelectors as $selector) {
                if ($crawler->filter($selector)->count() > 0) {
                    $heroIndicators++;
                }
            }
            if ($heroIndicators >= 2) {
                $indicators[] = "Homepage elements detected (hero/carousel/banner)";
            }
        } catch (\Exception $e) {}

        // Check 5: URL path suggests policy but content doesn't match
        $urlPath = strtolower(parse_url($expectedUrl, PHP_URL_PATH) ?? '');
        $expectsPolicy = str_contains($urlPath, 'privacy') || str_contains($urlPath, 'terms') || str_contains($urlPath, 'legal');

        if ($expectsPolicy) {
            // Check if content has policy structure
            $policyStructureIndicators = 0;
            $textLower = strtolower($text);

            // Check for section headers
            if (preg_match_all('/\b(section|article|chapter)\s*\d/i', $text) >= 2) {
                $policyStructureIndicators++;
            }
            // Check for legal terms
            $legalTerms = ['agree', 'consent', 'liability', 'privacy', 'collect', 'data', 'terms', 'conditions'];
            $foundTerms = 0;
            foreach ($legalTerms as $term) {
                if (str_contains($textLower, $term)) $foundTerms++;
            }
            if ($foundTerms >= 4) {
                $policyStructureIndicators++;
            }

            if ($policyStructureIndicators === 0 && $wordCount < 500) {
                $indicators[] = "URL suggests policy but content lacks legal structure";
            }
        }

        // Determine if it's likely a homepage
        $isHomepage = count($indicators) >= 2;

        return [
            'is_homepage' => $isHomepage,
            'indicators' => $indicators,
            'title' => $title,
            'word_count' => $wordCount,
        ];
    }

    /**
     * Validate that content matches expected document type.
     * Returns array with 'valid' bool, 'confidence' score, and 'details'.
     */
    public function validateContentType(string $text, string $documentTypeSlug): array
    {
        $textLower = strtolower($text);
        $wordCount = str_word_count($text);

        // Get validation keywords for this document type
        $keywords = config("scraper.discovery.content_validation_keywords.{$documentTypeSlug}", []);
        $minWords = config("scraper.discovery.min_word_counts.{$documentTypeSlug}",
                          config('scraper.discovery.min_word_counts.default', 300));

        // If no keywords configured, assume valid
        if (empty($keywords)) {
            return [
                'valid' => true,
                'confidence' => 0.5,
                'keyword_matches' => 0,
                'word_count' => $wordCount,
                'min_words' => $minWords,
                'details' => 'No validation keywords configured for this document type',
            ];
        }

        // Count keyword matches
        $matches = 0;
        $foundKeywords = [];
        foreach ($keywords as $keyword) {
            if (str_contains($textLower, strtolower($keyword))) {
                $matches++;
                $foundKeywords[] = $keyword;
            }
        }

        // Calculate confidence
        $keywordRatio = $matches / count($keywords);
        $wordCountOk = $wordCount >= $minWords;

        // Valid if: >= 3 keyword matches OR (>= 2 matches AND word count OK)
        $isValid = $matches >= 3 || ($matches >= 2 && $wordCountOk);

        // Confidence: 0-1 based on matches and word count
        $confidence = min(1.0, ($matches / 5) + ($wordCountOk ? 0.3 : 0));

        return [
            'valid' => $isValid,
            'confidence' => round($confidence, 2),
            'keyword_matches' => $matches,
            'found_keywords' => $foundKeywords,
            'word_count' => $wordCount,
            'min_words' => $minWords,
            'details' => $isValid
                ? "Content appears valid for {$documentTypeSlug}"
                : "Content may not be a valid {$documentTypeSlug} (only {$matches} keyword matches, {$wordCount} words)",
        ];
    }

    /**
     * Detect if the page is a "table of contents" landing page.
     * These pages have minimal content and primarily consist of links to sections.
     */
    public function isLandingPage(string $html): bool
    {
        $text = strip_tags($html);
        $wordCount = str_word_count($text);

        // Landing pages typically have low word count
        if ($wordCount > 1500) {
            return false; // Too much content to be just a TOC
        }

        // Check for numbered section patterns (1., 2., 3... or Section 1, etc.)
        $numberedPatterns = preg_match_all('/\b(?:section\s*)?\d+[.:]\s/i', $text);

        // If there are multiple numbered sections and low word count, it's likely a TOC
        if ($numberedPatterns >= 3 && $wordCount < 800) {
            return true;
        }

        // Check for multiple anchor links to same page (common in TOC pages)
        $crawler = new Crawler($html);
        try {
            $anchorLinks = $crawler->filter('a[href^="#"]')->count();
            if ($anchorLinks >= 5 && $wordCount < 1000) {
                return true;
            }
        } catch (\Exception $e) {
            // Continue with other checks
        }

        return false;
    }

    /**
     * Find a "full document" link on a landing/TOC page.
     * Looks for links with text like "Read the full...", "View complete...", etc.
     */
    public function findFullDocumentLink(string $html, string $baseUrl): ?string
    {
        // Patterns for "full version" link text
        $textPatterns = [
            'read the full',
            'view full',
            'full terms',
            'complete terms',
            'full agreement',
            'complete agreement',
            'full policy',
            'view complete',
            'read complete',
            'see full',
            'entire agreement',
            'all terms',
        ];

        $links = $this->extractLinks($html, $baseUrl);

        foreach ($links as $link) {
            $linkText = strtolower($link['text']);
            foreach ($textPatterns as $pattern) {
                if (str_contains($linkText, $pattern)) {
                    return $link['url'];
                }
            }
        }

        return null;
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
