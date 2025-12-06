<?php

namespace App\Services\Scraper;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\VersionComparison;
use App\Services\Scraper\DTO\ScrapeResult;

class VersioningService
{
    public function __construct(
        protected DiffService $diffService,
    ) {}

    /**
     * Create a new document version if content has changed.
     *
     * @return DocumentVersion|null Returns null if content hasn't changed
     */
    public function createVersion(Document $document, ScrapeResult $result): ?DocumentVersion
    {
        // Check if content actually changed
        $currentVersion = $document->currentVersion;

        if ($currentVersion && $currentVersion->content_hash === $result->contentHash) {
            // Content hasn't changed - just update the document's last_scraped_at
            $document->update([
                'last_scraped_at' => now(),
                'scrape_status' => 'success',
            ]);

            return null;
        }

        // Generate version number
        $versionNumber = $this->generateVersionNumber($document);

        // Create new version
        $version = DocumentVersion::create([
            'document_id' => $document->id,
            'version_number' => $versionNumber,
            'content_raw' => $result->contentRaw,
            'content_text' => $result->contentText,
            'content_markdown' => $result->contentMarkdown,
            'content_hash' => $result->contentHash,
            'word_count' => $result->wordCount,
            'character_count' => $result->characterCount,
            'language' => $result->language,
            'scraped_at' => now(),
            'extraction_metadata' => [
                'http_status' => $result->httpStatus,
                'final_url' => $result->finalUrl,
            ],
            'is_current' => true,
        ]);

        // Mark as current (unmarks previous)
        $version->markAsCurrent();

        // Update document
        $document->update([
            'last_scraped_at' => now(),
            'last_changed_at' => now(),
            'scrape_status' => 'success',
            'canonical_url' => $result->finalUrl !== $document->source_url
                ? $result->finalUrl
                : $document->canonical_url,
        ]);

        return $version;
    }

    /**
     * Create a comparison between two versions.
     */
    public function createComparison(
        DocumentVersion $oldVersion,
        DocumentVersion $newVersion
    ): VersionComparison {
        // Use text content for comparison
        $oldText = $oldVersion->content_text;
        $newText = $newVersion->content_text;

        // Generate diff
        $diffHtml = $this->diffService->generateHtmlDiff($oldText, $newText);
        $changes = $this->diffService->countChanges($oldText, $newText);
        $similarity = $this->diffService->calculateSimilarity($oldText, $newText);
        $severity = $this->diffService->determineSeverity($oldText, $newText);
        $changedSections = $this->diffService->extractChangedSections($oldText, $newText);

        return VersionComparison::create([
            'document_id' => $oldVersion->document_id,
            'old_version_id' => $oldVersion->id,
            'new_version_id' => $newVersion->id,
            'diff_html' => $diffHtml,
            'changes' => $changedSections,
            'additions_count' => $changes['additions'],
            'deletions_count' => $changes['deletions'],
            'modifications_count' => $changes['modifications'],
            'similarity_score' => $similarity,
            'change_severity' => $severity,
            'is_analyzed' => false, // Will be set to true after AI analysis
        ]);
    }

    /**
     * Generate a version number for a new version.
     */
    protected function generateVersionNumber(Document $document): string
    {
        $latestVersion = $document->versions()->orderByDesc('created_at')->first();

        if (!$latestVersion) {
            return '1.0';
        }

        // Parse existing version number
        $parts = explode('.', $latestVersion->version_number);
        $major = (int) ($parts[0] ?? 1);
        $minor = (int) ($parts[1] ?? 0);

        // Increment minor version
        return $major . '.' . ($minor + 1);
    }

    /**
     * Check if a document needs a new version based on content hash.
     */
    public function needsNewVersion(Document $document, string $contentHash): bool
    {
        $currentVersion = $document->currentVersion;

        if (!$currentVersion) {
            return true;
        }

        return $currentVersion->content_hash !== $contentHash;
    }

    /**
     * Get or create comparison between consecutive versions.
     */
    public function getOrCreateComparison(
        DocumentVersion $oldVersion,
        DocumentVersion $newVersion
    ): VersionComparison {
        // Check if comparison already exists
        $existing = VersionComparison::where('old_version_id', $oldVersion->id)
            ->where('new_version_id', $newVersion->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        return $this->createComparison($oldVersion, $newVersion);
    }
}
