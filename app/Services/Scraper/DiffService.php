<?php

namespace App\Services\Scraper;

use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;

class DiffService
{
    protected Differ $differ;

    public function __construct()
    {
        $builder = new UnifiedDiffOutputBuilder(
            header: "--- Old\n+++ New\n",
            addLineNumbers: true
        );

        $this->differ = new Differ($builder);
    }

    /**
     * Generate a unified diff between two texts.
     */
    public function generateDiff(string $old, string $new): string
    {
        return $this->differ->diff($old, $new);
    }

    /**
     * Generate an HTML diff for display.
     */
    public function generateHtmlDiff(string $old, string $new): string
    {
        $diff = $this->differ->diff($old, $new);

        $lines = explode("\n", $diff);
        $html = '<div class="diff">';

        foreach ($lines as $line) {
            if (str_starts_with($line, '+') && !str_starts_with($line, '+++')) {
                $html .= '<div class="diff-add">' . htmlspecialchars($line) . '</div>';
            } elseif (str_starts_with($line, '-') && !str_starts_with($line, '---')) {
                $html .= '<div class="diff-remove">' . htmlspecialchars($line) . '</div>';
            } elseif (str_starts_with($line, '@@')) {
                $html .= '<div class="diff-header">' . htmlspecialchars($line) . '</div>';
            } else {
                $html .= '<div class="diff-context">' . htmlspecialchars($line) . '</div>';
            }
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Count additions, deletions, and modifications.
     */
    public function countChanges(string $old, string $new): array
    {
        $diff = $this->differ->diff($old, $new);
        $lines = explode("\n", $diff);

        $additions = 0;
        $deletions = 0;

        foreach ($lines as $line) {
            if (str_starts_with($line, '+') && !str_starts_with($line, '+++')) {
                $additions++;
            } elseif (str_starts_with($line, '-') && !str_starts_with($line, '---')) {
                $deletions++;
            }
        }

        // Estimate modifications as minimum of additions/deletions
        $modifications = min($additions, $deletions);
        $additions -= $modifications;
        $deletions -= $modifications;

        return [
            'additions' => $additions,
            'deletions' => $deletions,
            'modifications' => $modifications,
            'total' => $additions + $deletions + $modifications,
        ];
    }

    /**
     * Calculate similarity score between two texts.
     */
    public function calculateSimilarity(string $old, string $new): float
    {
        if ($old === $new) {
            return 1.0;
        }

        if (empty($old) || empty($new)) {
            return 0.0;
        }

        // Use similar_text for basic similarity
        similar_text($old, $new, $percent);

        return round($percent / 100, 4);
    }

    /**
     * Determine change severity based on diff statistics.
     */
    public function determineSeverity(string $old, string $new): string
    {
        $changes = $this->countChanges($old, $new);
        $similarity = $this->calculateSimilarity($old, $new);

        if ($similarity >= 0.95) {
            return 'minor';
        }

        if ($similarity >= 0.80) {
            return 'moderate';
        }

        if ($similarity >= 0.50) {
            return 'major';
        }

        return 'critical';
    }

    /**
     * Extract changed sections/paragraphs.
     */
    public function extractChangedSections(string $old, string $new): array
    {
        $oldParagraphs = preg_split('/\n\n+/', $old);
        $newParagraphs = preg_split('/\n\n+/', $new);

        $changes = [];

        // Find removed paragraphs
        foreach ($oldParagraphs as $para) {
            $para = trim($para);
            if ($para && !in_array($para, array_map('trim', $newParagraphs))) {
                $changes[] = [
                    'type' => 'removed',
                    'content' => $para,
                ];
            }
        }

        // Find added paragraphs
        foreach ($newParagraphs as $para) {
            $para = trim($para);
            if ($para && !in_array($para, array_map('trim', $oldParagraphs))) {
                $changes[] = [
                    'type' => 'added',
                    'content' => $para,
                ];
            }
        }

        return $changes;
    }
}
