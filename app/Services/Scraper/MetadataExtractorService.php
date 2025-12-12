<?php

namespace App\Services\Scraper;

use Carbon\Carbon;

class MetadataExtractorService
{
    /**
     * Patterns for extracting update/last modified dates.
     */
    protected array $updateDatePatterns = [
        // "Updated: October 17, 2025" or "Last Updated: Oct 17, 2025"
        '/(?:last\s+)?updated(?:\s+on)?[:\s]+([A-Za-z]+\s+\d{1,2},?\s+\d{4})/i',

        // "Last Modified: January 1, 2024"
        '/last\s+modified(?:\s+on)?[:\s]+([A-Za-z]+\s+\d{1,2},?\s+\d{4})/i',

        // "Updated: 10/17/2025" or "Last Updated: 01/01/2024"
        '/(?:last\s+)?updated(?:\s+on)?[:\s]+(\d{1,2}\/\d{1,2}\/\d{2,4})/i',

        // "Last Modified: 2024-01-01"
        '/last\s+modified(?:\s+on)?[:\s]+(\d{4}-\d{2}-\d{2})/i',

        // "Updated 17 October 2025"
        '/updated\s+(\d{1,2}\s+[A-Za-z]+\s+\d{4})/i',

        // "Revised: October 2025" or "Revised October 17, 2025"
        '/revised(?:\s+on)?[:\s]+([A-Za-z]+(?:\s+\d{1,2},?)?\s+\d{4})/i',

        // "Date of last revision: October 17, 2025"
        '/date\s+of\s+last\s+revision[:\s]+([A-Za-z]+\s+\d{1,2},?\s+\d{4})/i',

        // "Last update: October 17, 2025"
        '/last\s+update[:\s]+([A-Za-z]+\s+\d{1,2},?\s+\d{4})/i',

        // "Updated: October 2025" (month and year only)
        '/(?:last\s+)?updated(?:\s+on)?[:\s]+([A-Za-z]+\s+\d{4})/i',
    ];

    /**
     * Patterns for extracting effective dates.
     */
    protected array $effectiveDatePatterns = [
        // "Effective: March 1, 2025" or "Effective Date: March 1, 2025"
        '/effective(?:\s+date)?[:\s]+([A-Za-z]+\s+\d{1,2},?\s+\d{4})/i',

        // "Effective as of March 1, 2025"
        '/effective\s+as\s+of\s+([A-Za-z]+\s+\d{1,2},?\s+\d{4})/i',

        // "In effect from 03/01/2025" or "In effect since..."
        '/in\s+effect\s+(?:from|since)\s+(\d{1,2}\/\d{1,2}\/\d{2,4})/i',

        // "Effective 2025-03-01"
        '/effective(?:\s+date)?[:\s]+(\d{4}-\d{2}-\d{2})/i',

        // "This policy is effective March 1, 2025"
        '/(?:this\s+)?(?:policy|agreement|terms|document)\s+(?:is|are|becomes?)\s+effective\s+([A-Za-z]+\s+\d{1,2},?\s+\d{4})/i',

        // "Takes effect on March 1, 2025"
        '/takes?\s+effect\s+(?:on\s+)?([A-Za-z]+\s+\d{1,2},?\s+\d{4})/i',

        // "Effective: March 2025" (month and year only)
        '/effective(?:\s+date)?[:\s]+([A-Za-z]+\s+\d{4})/i',

        // "Effective from March 1, 2025"
        '/effective\s+from\s+([A-Za-z]+\s+\d{1,2},?\s+\d{4})/i',
    ];

    /**
     * Patterns for extracting version numbers.
     */
    protected array $versionPatterns = [
        // "Version 2.1" or "Version 2.1.0"
        '/version[:\s]+(\d+(?:\.\d+)*)/i',

        // "v3.0" or "v3.0.1"
        '/\bv(\d+(?:\.\d+)+)\b/i',

        // "Revision 4" or "Rev. 4"
        '/(?:revision|rev\.?)[:\s]+(\d+(?:\.\d+)*)/i',

        // "Release 2.0"
        '/release[:\s]+(\d+(?:\.\d+)*)/i',

        // "Edition 3"
        '/edition[:\s]+(\d+)/i',
    ];

    /**
     * Extract all metadata from content text.
     */
    public function extract(string $contentText): array
    {
        if (empty(trim($contentText))) {
            return [];
        }

        $result = [];

        // Extract update date
        $updateDate = $this->extractUpdateDate($contentText);
        if ($updateDate) {
            $result['update_date'] = $updateDate;
        }

        // Extract effective date
        $effectiveDate = $this->extractEffectiveDate($contentText);
        if ($effectiveDate) {
            $result['effective_date'] = $effectiveDate;
        }

        // Extract version
        $version = $this->extractVersion($contentText);
        if ($version) {
            $result['version'] = $version;
        }

        if (!empty($result)) {
            $result['extracted_at'] = now()->toISOString();
        }

        return $result;
    }

    /**
     * Extract update/last modified date from content.
     */
    public function extractUpdateDate(string $contentText): ?array
    {
        return $this->extractWithPatterns($contentText, $this->updateDatePatterns, 'date');
    }

    /**
     * Extract effective date from content.
     */
    public function extractEffectiveDate(string $contentText): ?array
    {
        return $this->extractWithPatterns($contentText, $this->effectiveDatePatterns, 'date');
    }

    /**
     * Extract version number from content.
     */
    public function extractVersion(string $contentText): ?array
    {
        return $this->extractWithPatterns($contentText, $this->versionPatterns, 'version');
    }

    /**
     * Extract using an array of patterns, returning the best match.
     */
    protected function extractWithPatterns(string $contentText, array $patterns, string $type): ?array
    {
        $contentLength = strlen($contentText);
        $headerContent = $this->getContentHead($contentText, 0.2);
        $matches = [];

        foreach ($patterns as $patternIndex => $pattern) {
            // First try to find matches in the header (first 20%)
            if (preg_match($pattern, $headerContent, $match, PREG_OFFSET_CAPTURE)) {
                $matches[] = $this->createMatchResult($match, $patternIndex, $contentLength, $type, true);
            }

            // Also search the full content for additional matches
            if (preg_match($pattern, $contentText, $match, PREG_OFFSET_CAPTURE)) {
                $isInHeader = $match[0][1] < strlen($headerContent);
                if (!$isInHeader) {
                    $matches[] = $this->createMatchResult($match, $patternIndex, $contentLength, $type, false);
                }
            }
        }

        if (empty($matches)) {
            return null;
        }

        // Sort by confidence (highest first)
        usort($matches, fn ($a, $b) => $b['confidence'] <=> $a['confidence']);

        return $matches[0];
    }

    /**
     * Create a match result array with parsed value and confidence.
     */
    protected function createMatchResult(array $match, int $patternIndex, int $contentLength, string $type, bool $isInHeader): ?array
    {
        $rawMatch = trim($match[0][0]);
        $capturedValue = trim($match[1][0]);
        $position = $match[0][1];

        if ($type === 'date') {
            $parsed = $this->parseDate($capturedValue);
            if (!$parsed) {
                return null;
            }
            $value = $parsed->toDateString();
        } else {
            $value = $capturedValue;
        }

        $confidence = $this->calculateConfidence($position, $contentLength, $patternIndex, $isInHeader);

        return [
            'value' => $value,
            'raw_match' => $rawMatch,
            'confidence' => round($confidence, 2),
            'position' => $isInHeader ? 'header' : ($position < $contentLength * 0.5 ? 'body' : 'footer'),
        ];
    }

    /**
     * Parse various date formats into Carbon instance.
     */
    protected function parseDate(string $dateString): ?Carbon
    {
        $dateString = trim($dateString);

        // Remove ordinal suffixes (1st, 2nd, 3rd, 4th, etc.)
        $dateString = preg_replace('/(\d+)(st|nd|rd|th)\b/i', '$1', $dateString);

        $formats = [
            'F j, Y',      // October 17, 2025
            'F j Y',       // October 17 2025
            'M j, Y',      // Oct 17, 2025
            'M j Y',       // Oct 17 2025
            'j F Y',       // 17 October 2025
            'j M Y',       // 17 Oct 2025
            'n/j/Y',       // 10/17/2025
            'n/j/y',       // 10/17/25
            'm/d/Y',       // 10/17/2025
            'm/d/y',       // 10/17/25
            'Y-m-d',       // 2025-10-17
            'F Y',         // October 2025 (sets day to 1)
            'M Y',         // Oct 2025
        ];

        foreach ($formats as $format) {
            try {
                $date = Carbon::createFromFormat($format, $dateString);
                if ($date && $this->isReasonableYear($date->year)) {
                    return $date->startOfDay();
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        // Fallback to Carbon's natural language parsing
        try {
            $date = Carbon::parse($dateString);
            if ($date && $this->isReasonableYear($date->year)) {
                return $date->startOfDay();
            }
        } catch (\Exception $e) {
            return null;
        }

        return null;
    }

    /**
     * Check if year is within a reasonable range for policy documents.
     */
    protected function isReasonableYear(int $year): bool
    {
        return $year >= 2000 && $year <= 2100;
    }

    /**
     * Get the first N% of content for prioritized extraction.
     */
    protected function getContentHead(string $content, float $percentage = 0.2): string
    {
        $length = (int) (strlen($content) * $percentage);
        return substr($content, 0, max($length, 1000)); // At least 1000 chars
    }

    /**
     * Calculate confidence score based on pattern match quality and position.
     */
    protected function calculateConfidence(int $position, int $contentLength, int $patternIndex, bool $isInHeader): float
    {
        // Base confidence starts at 0.7
        $confidence = 0.7;

        // Header matches get +0.2 bonus
        if ($isInHeader) {
            $confidence += 0.2;
        } else {
            // Position-based scoring for non-header matches
            $positionRatio = $position / max($contentLength, 1);
            // Earlier in document = higher confidence
            $confidence += (1 - $positionRatio) * 0.15;
        }

        // Earlier patterns in our list are more specific, give slight bonus
        $patternBonus = max(0, 0.1 - ($patternIndex * 0.01));
        $confidence += $patternBonus;

        return min($confidence, 1.0);
    }
}
