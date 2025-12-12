<?php

namespace App\Services\AI;

use App\Models\DocumentVersion;
use App\Models\VersionComparison;
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
    ) {}

    /**
     * Analyze the diff between two document versions.
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
1. A summary of what changed (2-3 paragraphs)
2. Impact analysis - how these changes affect users (positive, negative, or neutral)
3. Specific change flags for notable additions, removals, or modifications

Return JSON:
{
  "summary": "Plain English summary of what changed...",
  "impact_analysis": "Analysis of how changes impact users...",
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

        try {
            $response = $this->llmClient->complete([
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user', 'content' => $prompt],
            ], [
                'temperature' => 0.2,
                'max_tokens' => 2048,
            ]);

            $this->trackTokens($response);

            return $response->json();
        } catch (\Exception $e) {
            Log::error('Diff analysis failed', ['error' => $e->getMessage()]);

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
}
