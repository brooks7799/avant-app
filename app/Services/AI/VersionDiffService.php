<?php

namespace App\Services\AI;

use App\Models\DocumentVersion;
use App\Services\LLM\LlmClientInterface;
use Illuminate\Support\Facades\Log;

/**
 * Service for generating diffs between document versions
 * and AI-powered summaries of changes.
 */
class VersionDiffService
{
    public function __construct(
        protected LlmClientInterface $llmClient,
    ) {}

    /**
     * Generate a structured diff between two text versions.
     *
     * Returns an array of diff blocks with type (unchanged, added, removed, modified)
     * and content.
     */
    public function generateDiff(string $oldText, string $newText): array
    {
        $oldLines = explode("\n", $oldText);
        $newLines = explode("\n", $newText);

        // Use a simple line-by-line diff algorithm
        $diff = $this->computeLineDiff($oldLines, $newLines);

        // Calculate statistics
        $stats = $this->calculateDiffStats($diff);

        return [
            'blocks' => $diff,
            'stats' => $stats,
        ];
    }

    /**
     * Compute line-by-line diff using a modified LCS algorithm.
     */
    protected function computeLineDiff(array $oldLines, array $newLines): array
    {
        $m = count($oldLines);
        $n = count($newLines);

        // Build LCS length table
        $lcs = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));

        for ($i = 1; $i <= $m; $i++) {
            for ($j = 1; $j <= $n; $j++) {
                if ($this->linesMatch($oldLines[$i - 1], $newLines[$j - 1])) {
                    $lcs[$i][$j] = $lcs[$i - 1][$j - 1] + 1;
                } else {
                    $lcs[$i][$j] = max($lcs[$i - 1][$j], $lcs[$i][$j - 1]);
                }
            }
        }

        // Backtrack to build the diff
        $diff = [];
        $i = $m;
        $j = $n;

        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0 && $this->linesMatch($oldLines[$i - 1], $newLines[$j - 1])) {
                // Lines match - unchanged
                array_unshift($diff, [
                    'type' => 'unchanged',
                    'oldLine' => $i,
                    'newLine' => $j,
                    'content' => $newLines[$j - 1],
                ]);
                $i--;
                $j--;
            } elseif ($j > 0 && ($i === 0 || $lcs[$i][$j - 1] >= $lcs[$i - 1][$j])) {
                // Line was added
                array_unshift($diff, [
                    'type' => 'added',
                    'oldLine' => null,
                    'newLine' => $j,
                    'content' => $newLines[$j - 1],
                ]);
                $j--;
            } elseif ($i > 0 && ($j === 0 || $lcs[$i][$j - 1] < $lcs[$i - 1][$j])) {
                // Line was removed
                array_unshift($diff, [
                    'type' => 'removed',
                    'oldLine' => $i,
                    'newLine' => null,
                    'content' => $oldLines[$i - 1],
                ]);
                $i--;
            }
        }

        // Group consecutive changes into blocks
        return $this->groupIntoBlocks($diff);
    }

    /**
     * Check if two lines match (with some tolerance for whitespace).
     */
    protected function linesMatch(string $a, string $b): bool
    {
        return trim($a) === trim($b);
    }

    /**
     * Group consecutive diff lines into blocks for better display.
     */
    protected function groupIntoBlocks(array $diff): array
    {
        if (empty($diff)) {
            return [];
        }

        $blocks = [];
        $currentBlock = null;
        $contextLines = 3; // Show 3 lines of context around changes

        foreach ($diff as $index => $line) {
            if ($currentBlock === null) {
                $currentBlock = [
                    'type' => $line['type'],
                    'lines' => [$line],
                    'startOldLine' => $line['oldLine'],
                    'startNewLine' => $line['newLine'],
                ];
            } elseif ($line['type'] === $currentBlock['type']) {
                // Same type, add to current block
                $currentBlock['lines'][] = $line;
            } else {
                // Different type, save current block and start new
                $blocks[] = $currentBlock;
                $currentBlock = [
                    'type' => $line['type'],
                    'lines' => [$line],
                    'startOldLine' => $line['oldLine'],
                    'startNewLine' => $line['newLine'],
                ];
            }
        }

        if ($currentBlock !== null) {
            $blocks[] = $currentBlock;
        }

        // Collapse long unchanged sections but keep context
        return $this->collapseUnchangedBlocks($blocks, $contextLines);
    }

    /**
     * Collapse long unchanged sections, keeping context around changes.
     */
    protected function collapseUnchangedBlocks(array $blocks, int $contextLines): array
    {
        $result = [];

        foreach ($blocks as $index => $block) {
            if ($block['type'] !== 'unchanged' || count($block['lines']) <= ($contextLines * 2 + 3)) {
                // Keep short unchanged blocks or change blocks as-is
                $result[] = $block;
                continue;
            }

            // Long unchanged block - split into context sections
            $lines = $block['lines'];
            $totalLines = count($lines);

            // Check if this is between changes
            $prevIsChange = $index > 0 && $blocks[$index - 1]['type'] !== 'unchanged';
            $nextIsChange = $index < count($blocks) - 1 && $blocks[$index + 1]['type'] !== 'unchanged';

            if ($prevIsChange || $nextIsChange) {
                // Keep first few lines as context
                if ($prevIsChange) {
                    $result[] = [
                        'type' => 'unchanged',
                        'lines' => array_slice($lines, 0, $contextLines),
                        'startOldLine' => $block['startOldLine'],
                        'startNewLine' => $block['startNewLine'],
                    ];
                }

                // Add collapsed indicator
                $skippedLines = $totalLines - ($contextLines * 2);
                if ($skippedLines > 0) {
                    $result[] = [
                        'type' => 'collapsed',
                        'skippedLines' => $skippedLines,
                        'lines' => [],
                    ];
                }

                // Keep last few lines as context for next change
                if ($nextIsChange) {
                    $result[] = [
                        'type' => 'unchanged',
                        'lines' => array_slice($lines, -$contextLines),
                        'startOldLine' => $lines[$totalLines - $contextLines]['oldLine'] ?? null,
                        'startNewLine' => $lines[$totalLines - $contextLines]['newLine'] ?? null,
                    ];
                }
            } else {
                // No adjacent changes, just show collapsed indicator
                $result[] = [
                    'type' => 'collapsed',
                    'skippedLines' => $totalLines,
                    'lines' => [],
                ];
            }
        }

        return $result;
    }

    /**
     * Calculate diff statistics.
     */
    protected function calculateDiffStats(array $blocks): array
    {
        $added = 0;
        $removed = 0;
        $unchanged = 0;

        foreach ($blocks as $block) {
            $lineCount = count($block['lines']);
            match ($block['type']) {
                'added' => $added += $lineCount,
                'removed' => $removed += $lineCount,
                'unchanged' => $unchanged += $lineCount,
                default => null,
            };
        }

        $total = $added + $removed + $unchanged;
        $changePercentage = $total > 0 ? round((($added + $removed) / $total) * 100, 1) : 0;

        return [
            'linesAdded' => $added,
            'linesRemoved' => $removed,
            'linesUnchanged' => $unchanged,
            'totalLines' => $total,
            'changePercentage' => $changePercentage,
        ];
    }

    /**
     * Generate an AI-powered summary of changes between versions.
     */
    public function generateChangeSummary(DocumentVersion $oldVersion, DocumentVersion $newVersion): array
    {
        $oldContent = $oldVersion->content_markdown ?? $oldVersion->content_text ?? '';
        $newContent = $newVersion->content_markdown ?? $newVersion->content_text ?? '';

        $oldDate = $oldVersion->scraped_at?->format('F j, Y') ?? 'Unknown date';
        $newDate = $newVersion->scraped_at?->format('F j, Y') ?? 'Unknown date';

        // Truncate content if too long
        $maxChars = 8000;
        if (strlen($oldContent) > $maxChars) {
            $oldContent = substr($oldContent, 0, $maxChars) . "\n...[truncated]";
        }
        if (strlen($newContent) > $maxChars) {
            $newContent = substr($newContent, 0, $maxChars) . "\n...[truncated]";
        }

        $prompt = <<<PROMPT
Compare these two versions of a legal document and provide a detailed analysis of the changes.

## VERSION 1 (From {$oldDate})
{$oldContent}

## VERSION 2 (From {$newDate})
{$newContent}

Analyze the changes and return a JSON response with:

1. "summary": A 2-3 sentence executive summary of the most important changes
2. "changes": An array of specific changes, each with:
   - "category": One of: "data_collection", "data_sharing", "user_rights", "legal_terms", "dispute_resolution", "liability", "notifications", "other"
   - "title": Short title for the change (e.g., "Data Deletion Timeline Extended")
   - "old_text": Brief quote or summary of what the old version said
   - "new_text": Brief quote or summary of what the new version says
   - "impact": "positive", "negative", or "neutral" from user perspective
   - "severity": 1-10 scale of how significant this change is
   - "explanation": 1-2 sentences explaining what this means for users in plain language
3. "risk_assessment": Overall assessment of whether these changes benefit or harm users
4. "recommendations": What users should do or be aware of

Example format:
{
  "summary": "This update significantly reduces user data rights...",
  "changes": [
    {
      "category": "user_rights",
      "title": "Data Deletion Timeline Extended",
      "old_text": "delete within 30 days",
      "new_text": "delete within 90 days",
      "impact": "negative",
      "severity": 6,
      "explanation": "You'll now have to wait 3x longer to have your data deleted."
    }
  ],
  "risk_assessment": "These changes represent a significant reduction in user protections...",
  "recommendations": "Users should review their data sharing settings..."
}
PROMPT;

        try {
            $response = $this->llmClient->complete([
                ['role' => 'system', 'content' => 'You are an expert legal analyst. Analyze legal document changes and explain them in plain language.'],
                ['role' => 'user', 'content' => $prompt],
            ], [
                'temperature' => 0.3,
                'max_tokens' => 4096,
            ]);

            $result = $response->json();

            if (!is_array($result)) {
                throw new \RuntimeException('Invalid response format');
            }

            return [
                'success' => true,
                'data' => $result,
                'oldDate' => $oldDate,
                'newDate' => $newDate,
            ];
        } catch (\Exception $e) {
            Log::error('Change summary generation failed', [
                'error' => $e->getMessage(),
                'old_version' => $oldVersion->id,
                'new_version' => $newVersion->id,
            ]);

            return [
                'success' => false,
                'error' => 'Failed to generate change summary: ' . $e->getMessage(),
                'oldDate' => $oldDate,
                'newDate' => $newDate,
            ];
        }
    }
}
