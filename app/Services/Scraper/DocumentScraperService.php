<?php

namespace App\Services\Scraper;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\ScrapeJob;
use App\Services\Scraper\DTO\ScrapeResult;

class DocumentScraperService
{
    public function __construct(
        protected HttpClientService $httpClient,
        protected ContentExtractorService $extractor,
        protected MarkdownConverterService $markdownConverter,
        protected ?BrowserRendererService $browserRenderer = null,
    ) {
        $this->browserRenderer = $browserRenderer ?? new BrowserRendererService();
    }

    /**
     * Scrape a document and return the result.
     */
    public function scrape(Document $document, ?ScrapeJob $job = null): ScrapeResult
    {
        try {
            $job?->logInfo("Starting scrape for {$document->documentType?->name}");
            $job?->logInfo("Fetching content from {$document->source_url}");

            // Fetch the page
            $response = $this->httpClient->get($document->source_url);

            // Store request headers for debugging
            $requestHeaders = [
                'User-Agent' => config('scraper.user_agent'),
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
            ];

            if (!$response->successful()) {
                $job?->logError("HTTP request failed with status {$response->status()}");
                // Store debug info even on failure
                $job?->update([
                    'raw_html' => $response->body(),
                    'request_headers' => $requestHeaders,
                    'response_headers' => $response->headers(),
                ]);
                return ScrapeResult::failure(
                    'HTTP request failed with status ' . $response->status(),
                    $response->status()
                );
            }

            $html = $response->body();
            $contentLength = strlen($html);
            $job?->logSuccess("Content fetched: {$contentLength} bytes");

            // Store raw HTML for debugging
            $job?->update([
                'raw_html' => $html,
                'request_headers' => $requestHeaders,
                'response_headers' => $response->headers(),
            ]);

            // Get final URL and check for geo-restriction redirects
            $finalUrl = $this->httpClient->getFinalUrl($response) ?? $document->source_url;
            $redirectIssue = $this->detectRedirectIssue($document->source_url, $finalUrl);
            if ($redirectIssue) {
                $job?->logError("Redirect issue: {$redirectIssue}");
                return ScrapeResult::failure($redirectIssue, $response->status());
            }

            // Validate response is HTML
            $contentType = $response->header('Content-Type') ?? '';
            if (!str_contains($contentType, 'html') && !str_contains($contentType, 'text')) {
                $job?->logError("Response is not HTML: {$contentType}");
                return ScrapeResult::failure(
                    'Response is not HTML: ' . $contentType,
                    $response->status()
                );
            }

            // Extract main content
            $job?->logInfo('Extracting main content...');
            $extractedHtml = $this->extractor->extract($html);

            // Store extracted HTML for debugging
            $job?->update(['extracted_html' => $extractedHtml]);

            // Convert to plain text
            $text = $this->extractor->toPlainText($extractedHtml);
            $job?->logInfo("Extracted " . strlen($text) . " characters of text");

            // Validate content length
            $minLength = config('scraper.extraction.min_content_length', 500);
            if (strlen($text) < $minLength) {
                $job?->logWarning("Extracted content too short ({$minLength} chars minimum), trying browser rendering...");

                // Try browser rendering as fallback
                if (config('scraper.browser.enabled', true)) {
                    $browserResult = $this->scrapeWithBrowser($document, $job);
                    if ($browserResult->success) {
                        return $browserResult;
                    }
                    // Browser also failed, return original error
                    $job?->logError("Browser rendering also failed: {$browserResult->error}");
                }

                return ScrapeResult::failure(
                    "Extracted content too short ({$minLength} chars minimum)",
                    $response->status()
                );
            }

            // Convert to markdown
            $job?->logInfo('Converting to markdown...');
            $markdown = $this->markdownConverter->convert($extractedHtml);

            // Generate content hash
            $job?->logInfo('Computing content hash...');
            $hash = DocumentVersion::generateContentHash($text);

            // Detect language (basic detection)
            $language = $this->detectLanguage($text);

            $wordCount = str_word_count($text);
            $job?->logSuccess("Scrape complete: {$wordCount} words, language: {$language}");

            return ScrapeResult::success(
                contentRaw: $html,
                contentText: $text,
                contentMarkdown: $markdown,
                contentHash: $hash,
                wordCount: $wordCount,
                characterCount: strlen($text),
                language: $language,
                httpStatus: $response->status(),
                responseHeaders: $response->headers(),
                finalUrl: $finalUrl,
            );

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $job?->logError("Connection failed: {$e->getMessage()}");
            return ScrapeResult::failure('Connection failed: ' . $e->getMessage());
        } catch (\Exception $e) {
            $job?->logError("Scraping failed: {$e->getMessage()}");
            return ScrapeResult::failure('Scraping failed: ' . $e->getMessage());
        }
    }

    /**
     * Scrape a document and record the job.
     */
    public function scrapeWithJob(Document $document, ScrapeJob $job): ScrapeResult
    {
        $job->markRunning();
        $job->initializeProgressLog();

        $result = $this->scrape($document, $job);

        // Update job with final HTTP status and user agent
        $job->update([
            'http_status' => $result->httpStatus,
            'user_agent' => config('scraper.user_agent'),
        ]);

        return $result;
    }

    /**
     * Scrape a document using browser rendering.
     */
    public function scrapeWithBrowser(Document $document, ?ScrapeJob $job = null): ScrapeResult
    {
        $job?->logInfo('Attempting browser rendering...');

        // Select browser
        $browser = config('scraper.browser.auto_select', true)
            ? $this->browserRenderer->getBestAvailableBrowser()
            : config('scraper.browser.default', 'chromium');

        $job?->logInfo("Using browser: {$browser}");

        $result = $this->browserRenderer->render($document->source_url, [
            'browser' => $browser,
            'timeout' => config('scraper.browser.timeout', 30000),
            'waitUntil' => config('scraper.browser.wait_until', 'networkidle'),
        ]);

        if (!$result->success) {
            $job?->logError("Browser rendering failed: {$result->error}");
            return ScrapeResult::failure($result->error ?? 'Browser rendering failed');
        }

        $job?->logSuccess("Browser rendered page: " . strlen($result->html) . " bytes");

        // Store browser HTML for debugging
        $job?->update([
            'raw_html' => $result->html,
            'extracted_html' => $result->html, // For browser, this is the full rendered HTML
            'metadata' => array_merge($job->metadata ?? [], [
                'browser' => $result->browser,
                'fallback' => $result->fallback,
            ]),
        ]);

        // Extract text from the browser result
        $text = $result->text;
        $job?->logInfo("Browser extracted " . strlen($text) . " characters of text");

        // Validate content length
        $minLength = config('scraper.extraction.min_content_length', 500);
        if (strlen($text) < $minLength) {
            $job?->logError("Browser extracted content still too short ({$minLength} chars minimum)");
            return ScrapeResult::failure(
                "Browser extracted content too short ({$minLength} chars minimum)",
                $result->httpStatus
            );
        }

        // Convert to markdown
        $job?->logInfo('Converting browser content to markdown...');
        $markdown = $this->markdownConverter->convert($result->html);

        // Generate content hash
        $hash = DocumentVersion::generateContentHash($text);

        // Detect language
        $language = $result->metadata['language'] ?? $this->detectLanguage($text);

        $wordCount = str_word_count($text);
        $job?->logSuccess("Browser scrape complete: {$wordCount} words, language: {$language}");

        return ScrapeResult::success(
            contentRaw: $result->html,
            contentText: $text,
            contentMarkdown: $markdown,
            contentHash: $hash,
            wordCount: $wordCount,
            characterCount: strlen($text),
            language: $language,
            httpStatus: $result->httpStatus,
            responseHeaders: [],
            finalUrl: $result->finalUrl ?? $document->source_url,
        );
    }

    /**
     * Basic language detection from text.
     */
    protected function detectLanguage(string $text): string
    {
        // Simple heuristic - check for common words in different languages
        $sample = strtolower(substr($text, 0, 1000));

        $languages = [
            'en' => ['the', 'and', 'you', 'that', 'for', 'are', 'with', 'have', 'this', 'will'],
            'de' => ['der', 'die', 'und', 'ist', 'von', 'mit', 'den', 'des', 'auf', 'für'],
            'fr' => ['les', 'des', 'que', 'est', 'vous', 'dans', 'qui', 'pour', 'sur', 'sont'],
            'es' => ['que', 'los', 'las', 'del', 'por', 'para', 'con', 'una', 'son', 'sus'],
        ];

        $scores = [];

        foreach ($languages as $lang => $words) {
            $scores[$lang] = 0;
            foreach ($words as $word) {
                $scores[$lang] += substr_count($sample, " {$word} ");
            }
        }

        arsort($scores);

        return array_key_first($scores) ?? 'en';
    }

    /**
     * Detect redirect issues like geo-restrictions or region redirects.
     */
    protected function detectRedirectIssue(string $originalUrl, string $finalUrl): ?string
    {
        $originalParsed = parse_url($originalUrl);
        $finalParsed = parse_url($finalUrl);

        $originalHost = $originalParsed['host'] ?? '';
        $finalHost = $finalParsed['host'] ?? '';

        // Check for cross-domain redirect (common with geo-restrictions)
        $originalDomain = $this->extractRootDomain($originalHost);
        $finalDomain = $this->extractRootDomain($finalHost);

        if ($originalDomain !== $finalDomain) {
            return "Redirected to different domain ({$finalHost}) - possible geo-restriction or block";
        }

        // Check for region code changes in the URL path
        $originalPath = $originalParsed['path'] ?? '';
        $finalPath = $finalParsed['path'] ?? '';

        // Common region patterns: /en-us/, /ko-kr/, /ja-jp/, /de-de/, etc.
        $regionPattern = '/\/([a-z]{2})-([a-z]{2})\//i';

        preg_match($regionPattern, $originalPath, $originalRegion);
        preg_match($regionPattern, $finalPath, $finalRegion);

        // If original had a region and we got redirected to a different region
        if (!empty($originalRegion[0]) && !empty($finalRegion[0]) && $originalRegion[0] !== $finalRegion[0]) {
            return "Region redirect detected ({$originalRegion[0]} -> {$finalRegion[0]}) - content may differ or be geo-restricted";
        }

        // Check if we were redirected to a welcome/home page (common with blocks)
        $blockIndicators = ['/welcome', '/home', '/unavailable', '/error', '/blocked', '/region'];
        foreach ($blockIndicators as $indicator) {
            if (str_contains(strtolower($finalPath), $indicator) && !str_contains(strtolower($originalPath), $indicator)) {
                return "Redirected to {$indicator} page - possible geo-restriction or access block";
            }
        }

        return null;
    }

    /**
     * Extract the root domain from a hostname.
     */
    protected function extractRootDomain(string $host): string
    {
        // Remove www. prefix
        $host = preg_replace('/^www\./i', '', $host);

        // Get last two parts (handles co.uk, com.au etc. approximately)
        $parts = explode('.', $host);
        if (count($parts) >= 2) {
            return implode('.', array_slice($parts, -2));
        }

        return $host;
    }
}
