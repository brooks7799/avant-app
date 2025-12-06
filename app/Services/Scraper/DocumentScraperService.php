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
    ) {}

    /**
     * Scrape a document and return the result.
     */
    public function scrape(Document $document): ScrapeResult
    {
        try {
            // Fetch the page
            $response = $this->httpClient->get($document->source_url);

            if (!$response->successful()) {
                return ScrapeResult::failure(
                    'HTTP request failed with status ' . $response->status(),
                    $response->status()
                );
            }

            $html = $response->body();

            // Validate response is HTML
            $contentType = $response->header('Content-Type') ?? '';
            if (!str_contains($contentType, 'html') && !str_contains($contentType, 'text')) {
                return ScrapeResult::failure(
                    'Response is not HTML: ' . $contentType,
                    $response->status()
                );
            }

            // Extract main content
            $extractedHtml = $this->extractor->extract($html);

            // Convert to plain text
            $text = $this->extractor->toPlainText($extractedHtml);

            // Validate content length
            $minLength = config('scraper.extraction.min_content_length', 500);
            if (strlen($text) < $minLength) {
                return ScrapeResult::failure(
                    "Extracted content too short ({$minLength} chars minimum)",
                    $response->status()
                );
            }

            // Convert to markdown
            $markdown = $this->markdownConverter->convert($extractedHtml);

            // Generate content hash
            $hash = DocumentVersion::generateContentHash($text);

            // Detect language (basic detection)
            $language = $this->detectLanguage($text);

            // Get final URL (after redirects)
            $finalUrl = $this->httpClient->getFinalUrl($response) ?? $document->source_url;

            return ScrapeResult::success(
                contentRaw: $html,
                contentText: $text,
                contentMarkdown: $markdown,
                contentHash: $hash,
                wordCount: str_word_count($text),
                characterCount: strlen($text),
                language: $language,
                httpStatus: $response->status(),
                responseHeaders: $response->headers(),
                finalUrl: $finalUrl,
            );

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return ScrapeResult::failure('Connection failed: ' . $e->getMessage());
        } catch (\Exception $e) {
            return ScrapeResult::failure('Scraping failed: ' . $e->getMessage());
        }
    }

    /**
     * Scrape a document and record the job.
     */
    public function scrapeWithJob(Document $document, ScrapeJob $job): ScrapeResult
    {
        $job->markRunning();

        $result = $this->scrape($document);

        // Update job with response details
        if ($result->httpStatus) {
            $job->update([
                'http_status' => $result->httpStatus,
                'response_headers' => $result->responseHeaders,
                'user_agent' => config('scraper.user_agent'),
            ]);
        }

        return $result;
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
}
