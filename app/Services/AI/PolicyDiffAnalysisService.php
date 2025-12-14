<?php

namespace App\Services\AI;

use App\Models\DocumentVersion;
use App\Models\VersionComparison;
use App\Models\VersionComparisonAnalysis;
use App\Models\VersionComparisonChunkSummary;
use App\Services\LLM\LlmClientInterface;
use App\Services\LLM\LlmResponse;
use Illuminate\Support\Facades\Log;

class PolicyDiffAnalysisService
{
    protected const SYSTEM_PROMPT = <<<'PROMPT'
You are an expert legal analyst specializing in comparing legal document versions. Your role is to:

1. Identify meaningful changes between document versions
2. Assess whether changes benefit or harm users
3. Explain changes in plain, simple language
4. Flag concerning changes that may reduce user rights or increase company power

Be objective and thorough. Focus on substantive changes, not formatting or minor wording adjustments.
PROMPT;

    protected int $totalInputTokens = 0;

    protected int $totalOutputTokens = 0;

    public function __construct(
        protected LlmClientInterface $llmClient,
        protected SuspiciousTimingService $timingService,
        protected VersionDiffService $diffService,
    ) {}

    /**
     * Analyze the diff and store results in a VersionComparisonAnalysis record.
     * This supports multiple analyses per comparison.
     */
    public function analyzeForHistory(VersionComparison $comparison, VersionComparisonAnalysis $analysisRecord): array
    {
        $this->totalInputTokens = 0;
        $this->totalOutputTokens = 0;

        $oldVersion = $comparison->oldVersion;
        $newVersion = $comparison->newVersion;

        if (! $oldVersion || ! $newVersion) {
            throw new \RuntimeException('Comparison is missing version references');
        }

        Log::info('Starting diff analysis for history', [
            'analysis_id' => $analysisRecord->id,
            'comparison_id' => $comparison->id,
        ]);

        // Generate the diff to get blocks for chunk analysis
        $diff = $this->diffService->generateDiff(
            $oldVersion->content_markdown ?? $oldVersion->content_text,
            $newVersion->content_markdown ?? $newVersion->content_text
        );

        // Identify change chunks (blocks that are added or removed)
        $changeChunks = $this->identifyChangeChunks($diff['blocks'] ?? []);
        $totalChunks = count($changeChunks);

        // Update analysis with total chunk count
        $analysisRecord->update([
            'total_chunks' => $totalChunks,
            'processed_chunks' => 0,
            'current_chunk_label' => 'Analyzing overall changes...',
        ]);

        // Get the existing structured changes
        $structuredChanges = $comparison->changes ?? [];

        // Analyze changes with AI (overall summary)
        $analysis = $this->analyzeChanges($oldVersion, $newVersion, $structuredChanges);

        // Calculate impact score delta
        $impactDelta = $this->calculateImpactDelta($analysis['change_flags'] ?? []);

        // Analyze timing
        $timingAnalysis = $this->timingService->evaluate($newVersion, $impactDelta);

        // Now process each chunk sequentially
        Log::info('Starting chunk-by-chunk analysis', [
            'analysis_id' => $analysisRecord->id,
            'total_chunks' => $totalChunks,
        ]);

        foreach ($changeChunks as $index => $chunk) {
            $chunkNumber = $index + 1;
            $chunkLabel = "Analyzing change {$chunkNumber} of {$totalChunks}...";

            $analysisRecord->updateProgress($index, $chunkLabel);

            try {
                $this->analyzeAndStoreChunk($comparison, $chunk, $index);
            } catch (\Exception $e) {
                Log::warning('Chunk analysis failed, continuing with next', [
                    'chunk_index' => $index,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Check if the analysis actually succeeded (not the fallback error message)
        $analysisFailed = ($analysis['summary'] ?? '') === 'Analysis could not be completed.'
            || empty($analysis['summary']);

        if ($analysisFailed) {
            $analysisRecord->markAsFailed('AI analysis failed to generate a valid summary. Please try again.');

            Log::warning('Diff analysis marked as failed due to invalid summary', [
                'analysis_id' => $analysisRecord->id,
                'comparison_id' => $comparison->id,
                'summary' => $analysis['summary'] ?? 'null',
            ]);

            return [
                'impact_score_delta' => 0,
                'is_suspicious_timing' => false,
            ];
        }

        // Update the analysis record as completed
        $analysisRecord->markAsCompleted([
            'summary' => $analysis['summary'] ?? null,
            'impact_analysis' => $analysis['impact_analysis'] ?? null,
            'impact_score_delta' => $impactDelta,
            'change_flags' => $analysis['change_flags'] ?? [],
            'is_suspicious_timing' => $timingAnalysis['is_suspicious'],
            'suspicious_timing_score' => $timingAnalysis['score'],
            'timing_context' => $timingAnalysis['context'],
            'ai_model_used' => $this->llmClient->getModel(),
            'ai_tokens_used' => $this->totalInputTokens + $this->totalOutputTokens,
            'ai_analysis_cost' => $this->estimateCost(),
            'total_chunks' => $totalChunks,
            'processed_chunks' => $totalChunks,
            'current_chunk_label' => null,
        ]);

        // Also mark the comparison as analyzed (for backward compatibility)
        if (! $comparison->is_analyzed) {
            $comparison->update(['is_analyzed' => true]);
        }

        Log::info('Diff analysis for history completed', [
            'analysis_id' => $analysisRecord->id,
            'comparison_id' => $comparison->id,
            'impact_delta' => $impactDelta,
            'chunks_processed' => $totalChunks,
        ]);

        return [
            'impact_score_delta' => $impactDelta,
            'is_suspicious_timing' => $timingAnalysis['is_suspicious'],
        ];
    }

    /**
     * Identify change chunks from diff blocks.
     * Returns array of chunks with their block indices and content.
     */
    protected function identifyChangeChunks(array $blocks): array
    {
        $chunks = [];
        $currentChunk = null;

        foreach ($blocks as $blockIndex => $block) {
            $type = $block['type'] ?? 'unchanged';

            if ($type === 'added' || $type === 'removed') {
                if ($currentChunk === null) {
                    $currentChunk = [
                        'blockIndices' => [$blockIndex],
                        'blocks' => [$block],
                        'startLine' => $block['startNewLine'] ?? $block['startOldLine'] ?? null,
                    ];
                } else {
                    // Add to current chunk if it's adjacent
                    $currentChunk['blockIndices'][] = $blockIndex;
                    $currentChunk['blocks'][] = $block;
                }
            } else {
                // End of change chunk
                if ($currentChunk !== null) {
                    $chunks[] = $currentChunk;
                    $currentChunk = null;
                }
            }
        }

        // Don't forget the last chunk
        if ($currentChunk !== null) {
            $chunks[] = $currentChunk;
        }

        return $chunks;
    }

    /**
     * Analyze a single chunk and store the result.
     */
    protected function analyzeAndStoreChunk(VersionComparison $comparison, array $chunk, int $chunkIndex): void
    {
        // Extract text from the chunk
        $removedText = '';
        $addedText = '';

        foreach ($chunk['blocks'] as $block) {
            $lines = array_map(fn($line) => $line['content'] ?? '', $block['lines'] ?? []);
            $text = implode("\n", $lines);

            if ($block['type'] === 'removed') {
                $removedText .= $text . "\n";
            } elseif ($block['type'] === 'added') {
                $addedText .= $text . "\n";
            }
        }

        $removedText = trim($removedText);
        $addedText = trim($addedText);

        // Skip if both are empty
        if (empty($removedText) && empty($addedText)) {
            return;
        }

        // Generate content hash for deduplication
        $contentHash = VersionComparisonChunkSummary::generateContentHash($removedText, $addedText);

        // Check if we already have this chunk analyzed for this comparison
        $existing = VersionComparisonChunkSummary::where('version_comparison_id', $comparison->id)
            ->where('chunk_index', $chunkIndex)
            ->first();

        if ($existing) {
            Log::info('Chunk already analyzed, skipping', ['chunk_index' => $chunkIndex]);
            return;
        }

        // Call AI to analyze this chunk
        $result = $this->diffService->generateChunkSummary($removedText, $addedText);

        if (!$result['success'] || !$result['data']) {
            Log::warning('Chunk analysis returned no data', ['chunk_index' => $chunkIndex]);
            return;
        }

        $data = $result['data'];

        // Store the chunk summary
        VersionComparisonChunkSummary::create([
            'version_comparison_id' => $comparison->id,
            'chunk_index' => $chunkIndex,
            'content_hash' => $contentHash,
            'title' => $data['title'] ?? null,
            'summary' => $data['summary'] ?? null,
            'impact' => $data['impact'] ?? 'neutral',
            'grade' => $data['grade'] ?? null,
            'reason' => $data['reason'] ?? null,
            'ai_model_used' => $this->llmClient->getModel(),
        ]);

        Log::info('Chunk summary stored', [
            'chunk_index' => $chunkIndex,
            'title' => $data['title'] ?? 'unknown',
        ]);
    }

    /**
     * Analyze the diff between two document versions.
     * @deprecated Use analyzeForHistory instead
     */
    public function analyzeDiff(VersionComparison $comparison): VersionComparison
    {
        $this->totalInputTokens = 0;
        $this->totalOutputTokens = 0;

        $oldVersion = $comparison->oldVersion;
        $newVersion = $comparison->newVersion;

        if (! $oldVersion || ! $newVersion) {
            throw new \RuntimeException('Comparison is missing version references');
        }

        Log::info('Starting diff analysis', [
            'comparison_id' => $comparison->id,
            'old_version_id' => $oldVersion->id,
            'new_version_id' => $newVersion->id,
        ]);

        // Get the existing structured changes
        $structuredChanges = $comparison->changes ?? [];

        // Analyze changes with AI
        $analysis = $this->analyzeChanges($oldVersion, $newVersion, $structuredChanges);

        // Calculate impact score delta
        $impactDelta = $this->calculateImpactDelta($analysis['change_flags'] ?? []);

        // Analyze timing
        $timingAnalysis = $this->timingService->evaluate($newVersion, $impactDelta);

        // Update comparison record
        $comparison->update([
            'ai_change_summary' => $analysis['summary'] ?? null,
            'ai_impact_analysis' => $analysis['impact_analysis'] ?? null,
            'impact_score_delta' => $impactDelta,
            'change_flags' => $analysis['change_flags'] ?? [],
            'is_suspicious_timing' => $timingAnalysis['is_suspicious'],
            'suspicious_timing_score' => $timingAnalysis['score'],
            'timing_context' => $timingAnalysis['context'],
            'is_analyzed' => true,
            'ai_model_used' => $this->llmClient->getModel(),
            'ai_tokens_used' => $this->totalInputTokens + $this->totalOutputTokens,
            'ai_analysis_cost' => $this->estimateCost(),
            'ai_analyzed_at' => now(),
        ]);

        Log::info('Diff analysis completed', [
            'comparison_id' => $comparison->id,
            'impact_delta' => $impactDelta,
            'is_suspicious' => $timingAnalysis['is_suspicious'],
        ]);

        return $comparison->fresh();
    }

    /**
     * Analyze changes between versions using AI.
     */
    protected function analyzeChanges(
        DocumentVersion $oldVersion,
        DocumentVersion $newVersion,
        array $structuredChanges
    ): array {
        $oldContent = $oldVersion->content_text ?? '';
        $newContent = $newVersion->content_text ?? '';

        // Prepare a summary of structural changes if available
        $changeContext = '';
        if (! empty($structuredChanges)) {
            $changeContext = "Structural changes detected:\n";
            foreach ($structuredChanges as $change) {
                $type = $change['type'] ?? 'unknown';
                $text = substr($change['text'] ?? '', 0, 200);
                $changeContext .= "- [{$type}]: {$text}...\n";
            }
        }

        // For large documents, we'll send key sections rather than full content
        $oldSample = $this->getSampleContent($oldContent, 3000);
        $newSample = $this->getSampleContent($newContent, 3000);

        $documentType = $oldVersion->document?->documentType?->name ?? 'legal document';
        $companyName = $oldVersion->document?->company?->name ?? 'the company';

        $prompt = <<<PROMPT
Compare these two versions of a {$documentType} from {$companyName} and analyze the changes.

{$changeContext}

OLD VERSION (sample):
<<<OLD_START>>>
{$oldSample}
<<<OLD_END>>>

NEW VERSION (sample):
<<<NEW_START>>>
{$newSample}
<<<NEW_END>>>

Analyze the changes and provide:
1. A summary of what changed
2. Impact analysis - how these changes affect users
3. Specific change flags for notable additions, removals, or modifications

CRITICAL FORMATTING REQUIREMENT:
The "summary" and "impact_analysis" fields MUST use rich markdown formatting. DO NOT write plain paragraphs.

Required format for summary and impact_analysis:
- Start with a 1-sentence overview
- Use ### Section Headers to organize by topic
- Use bullet points (- ) for listing multiple changes
- Use **bold text** for key terms, company names, and important phrases
- Use emojis at the start of bullets: ⚠️ (warning/concern), ❌ (negative/removed), ✅ (positive), 📝 (neutral/info), 🔒 (privacy), ⚖️ (legal), 💰 (financial), 🕐 (timing)
- Keep paragraphs SHORT (1-2 sentences max)
- Never write walls of text

Example of CORRECT formatting:
"### 📋 Overview\n\nThis update makes **significant changes** to user rights and dispute resolution.\n\n### ⚠️ Key Concerns\n\n- ⚠️ **Mandatory arbitration** now required for all disputes\n- ❌ **Class action rights** have been removed\n- 🔒 Data retention extended from **30 days to 1 year**\n\n### ✅ Improvements\n\n- ✅ Added **clearer language** about data collection\n- ✅ New **opt-out mechanism** for marketing emails"

Return JSON:
{
  "summary": "### 📋 Overview\n\n[formatted content with headers, bullets, bold, emojis]...",
  "impact_analysis": "### ⚖️ User Impact\n\n[formatted content]...",
  "change_flags": {
    "new_clauses": [
      { "type": "forced_arbitration", "description": "Added mandatory arbitration clause", "severity": 10 }
    ],
    "removed_clauses": [
      { "type": "data_deletion_right", "description": "Removed explicit deletion rights", "severity": 8 }
    ],
    "modified_clauses": [
      { "type": "data_retention", "description": "Extended data retention from 1 year to indefinite", "severity": 7 }
    ],
    "neutral_changes": [
      { "type": "clarification", "description": "Clarified existing cookie policy" }
    ]
  },
  "overall_direction": "negative" | "positive" | "neutral" | "mixed"
}

Change types to look for:
- Negative: forced_arbitration, class_action_waiver, sell_data, removed_deletion_right, extended_retention, expanded_sharing, reduced_notice
- Positive: added_deletion_right, limited_sharing, shorter_retention, added_opt_out, clearer_language
- Neutral: clarification, formatting, typo_fix, reordering
PROMPT;

        $response = null;
        try {
            $response = $this->llmClient->complete([
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user', 'content' => $prompt],
            ], [
                'temperature' => 0.2,
                'max_tokens' => 16000, // Increased to avoid truncation
                'response_format' => ['type' => 'json_object'],
            ]);

            // Check for truncation which can cause JSON parsing failures
            if ($response->wasTruncated()) {
                Log::warning('LLM response was truncated', [
                    'output_tokens' => $response->outputTokens,
                    'finish_reason' => $response->finishReason,
                ]);
            }

            $this->trackTokens($response);

            $result = $response->json();

            // Post-process: ensure summary and impact_analysis have rich formatting
            // If the model returned plain text, format it
            if (!empty($result['summary']) && !str_contains($result['summary'], '###') && !str_contains($result['summary'], '**')) {
                $result['summary'] = $this->formatAsRichMarkdown($result['summary'], 'summary');
            }
            if (!empty($result['impact_analysis']) && !str_contains($result['impact_analysis'], '###') && !str_contains($result['impact_analysis'], '**')) {
                $result['impact_analysis'] = $this->formatAsRichMarkdown($result['impact_analysis'], 'impact');
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('Diff analysis failed', [
                'error' => $e->getMessage(),
                'content_length' => $response ? strlen($response->content) : 0,
                'content_preview' => $response ? substr($response->content, 0, 500) : 'No response',
                'finish_reason' => $response?->finishReason ?? 'unknown',
                'output_tokens' => $response?->outputTokens ?? 0,
                'was_truncated' => $response?->wasTruncated() ?? false,
            ]);

            return [
                'summary' => 'Analysis could not be completed.',
                'impact_analysis' => null,
                'change_flags' => [],
                'overall_direction' => 'unknown',
            ];
        }
    }

    /**
     * Get a representative sample of content for analysis.
     */
    protected function getSampleContent(string $content, int $maxChars): string
    {
        if (strlen($content) <= $maxChars) {
            return $content;
        }

        // Get beginning, middle, and end sections
        $sectionSize = (int) ($maxChars / 3);

        $beginning = substr($content, 0, $sectionSize);
        $middle = substr($content, (int) (strlen($content) / 2) - ($sectionSize / 2), $sectionSize);
        $end = substr($content, -$sectionSize);

        return $beginning."\n\n[...middle section...]\n\n".$middle."\n\n[...end section...]\n\n".$end;
    }

    /**
     * Calculate the impact score delta based on change flags.
     */
    public function calculateImpactDelta(array $changeFlags): int
    {
        $delta = 0;

        // Penalties for new negative clauses
        $negativeTypes = [
            'forced_arbitration' => -20,
            'class_action_waiver' => -15,
            'sell_data' => -25,
            'removed_deletion_right' => -15,
            'extended_retention' => -10,
            'expanded_sharing' => -12,
            'reduced_notice' => -8,
            'automatic_consent' => -10,
            'liability_expansion' => -10,
        ];

        // Bonuses for positive changes
        $positiveTypes = [
            'added_deletion_right' => 15,
            'limited_sharing' => 12,
            'shorter_retention' => 8,
            'added_opt_out' => 10,
            'clearer_language' => 5,
            'added_notice' => 8,
            'enhanced_security' => 5,
        ];

        // Process new clauses (negative)
        foreach ($changeFlags['new_clauses'] ?? [] as $clause) {
            $type = $clause['type'] ?? '';
            $severity = $clause['severity'] ?? 5;

            if (isset($negativeTypes[$type])) {
                $delta += $negativeTypes[$type] * ($severity / 10);
            } else {
                // Unknown negative clause - apply generic penalty
                $delta -= $severity;
            }
        }

        // Process removed clauses (could be negative if good clauses removed)
        foreach ($changeFlags['removed_clauses'] ?? [] as $clause) {
            $type = $clause['type'] ?? '';
            $severity = $clause['severity'] ?? 5;

            // Removing positive features is negative
            if (isset($positiveTypes[$type])) {
                $delta -= $positiveTypes[$type] * ($severity / 10);
            }
            // Removing negative features would be positive, but that's rare
            if (isset($negativeTypes[$type])) {
                $delta -= $negativeTypes[$type] * ($severity / 10); // Double negative = positive
            }
        }

        // Process modified clauses
        foreach ($changeFlags['modified_clauses'] ?? [] as $clause) {
            $type = $clause['type'] ?? '';
            $severity = $clause['severity'] ?? 5;

            if (isset($negativeTypes[$type])) {
                $delta += $negativeTypes[$type] * ($severity / 10) * 0.5; // 50% weight for modifications
            } elseif (isset($positiveTypes[$type])) {
                $delta += $positiveTypes[$type] * ($severity / 10) * 0.5;
            }
        }

        return (int) round($delta);
    }

    /**
     * Track token usage from response.
     */
    protected function trackTokens(LlmResponse $response): void
    {
        $this->totalInputTokens += $response->inputTokens ?? 0;
        $this->totalOutputTokens += $response->outputTokens ?? 0;
    }

    /**
     * Estimate cost based on tracked tokens.
     */
    protected function estimateCost(): float
    {
        $pricing = config('llm.pricing');
        $model = $this->llmClient->getModel();

        $modelPricing = $pricing[$model] ?? null;
        if (! $modelPricing) {
            return 0.0;
        }

        $inputCost = ($this->totalInputTokens / 1_000_000) * ($modelPricing['input'] ?? 0);
        $outputCost = ($this->totalOutputTokens / 1_000_000) * ($modelPricing['output'] ?? 0);

        return $inputCost + $outputCost;
    }

    /**
     * Format plain text analysis as rich markdown using AI.
     */
    protected function formatAsRichMarkdown(string $plainText, string $type): string
    {
        $typeLabel = $type === 'summary' ? 'Executive Summary' : 'Impact Analysis';
        $headerEmoji = $type === 'summary' ? '📋' : '⚖️';

        $prompt = <<<PROMPT
Transform this plain text into a DETAILED, richly formatted markdown document. EXPAND the content to be more comprehensive and detailed.

Original text:
{$plainText}

YOUR TASK: Create a well-structured, visually rich markdown document that is AT LEAST 3x longer than the input.

REQUIRED FORMAT:

### {$headerEmoji} {$typeLabel}

[One sentence hook that grabs attention]

### ⚠️ Key Concerns

- ⚠️ **[Concern 1 Title]** — [Detailed explanation of this concern and why it matters to users]
- ❌ **[Concern 2 Title]** — [Detailed explanation]
- 🔒 **[Privacy Concern]** — [Detailed explanation]

### 📝 What Changed

- 🕐 **[Change 1]** — [Details about timing, deadlines, or process changes]
- ⚖️ **[Legal Change]** — [Details about legal implications]
- 💰 **[Financial Impact]** — [Details if applicable]

### ✅ Neutral or Positive Changes (if any)

- ✅ **[Positive item]** — [Explanation]
- 📝 **[Neutral item]** — [Explanation]

### 🎯 Bottom Line

[2-3 sentences summarizing the overall impact on users]

FORMATTING RULES:
1. EVERY bullet point MUST start with an emoji
2. EVERY bullet point MUST have a **bold title** followed by an em dash (—) and explanation
3. Each bullet explanation should be 1-2 full sentences, not fragments
4. Use ### headers to create clear sections
5. Be VERBOSE - explain WHY each change matters
6. Aim for 300-500 words total
7. Make it look like a professional ChatGPT response

Return ONLY the formatted markdown. No code blocks, no JSON, no meta-commentary.
PROMPT;

        try {
            $response = $this->llmClient->complete([
                ['role' => 'user', 'content' => $prompt],
            ], [
                'temperature' => 0.3,
                'max_tokens' => 2000,
            ]);

            $this->trackTokens($response);

            $formatted = trim($response->content);

            // Remove any markdown code block wrapper if present
            if (preg_match('/^```(?:markdown)?\s*\n?(.*?)\n?```$/s', $formatted, $matches)) {
                $formatted = trim($matches[1]);
            }

            return $formatted;
        } catch (\Exception $e) {
            Log::warning('Failed to format as rich markdown', ['error' => $e->getMessage()]);
            return $plainText;
        }
    }
}
