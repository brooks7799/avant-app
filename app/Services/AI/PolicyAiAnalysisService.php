<?php

namespace App\Services\AI;

use App\Models\AnalysisResult;
use App\Models\DocumentVersion;
use App\Services\LLM\LlmClientInterface;
use App\Services\LLM\LlmResponse;
use Illuminate\Support\Facades\Log;

class PolicyAiAnalysisService
{
    protected const SYSTEM_PROMPT = <<<'PROMPT'
You are an expert legal analyst focused on consumer protection. You analyze Terms of Service, Privacy Policies, and similar legal documents.

Your role is to:
1. Explain complex legal terms in plain, simple language (6th grade reading level)
2. Identify clauses that may be risky, unfair, or concerning for users
3. Highlight provisions that protect users or are particularly fair
4. Be objective, balanced, and thorough in your analysis

Always prioritize accuracy over speculation. If something is unclear, note that it is unclear rather than guessing.
PROMPT;

    protected const CHUNK_TOKENS = 3500; // ~3.5k tokens per chunk

    protected const APPROX_CHARS_PER_TOKEN = 4;

    // Token limits - reasoning models (gpt-5-nano, o1, o3) need much higher limits
    // because they use hidden "reasoning tokens" before generating output
    protected const MAX_TOKENS_REASONING_MODEL = 16000;

    protected const MAX_TOKENS_STANDARD_MODEL = 4096;

    protected int $totalInputTokens = 0;

    protected int $totalOutputTokens = 0;

    protected array $rawAiOutputs = [];

    protected array $errors = [];

    public function __construct(
        protected LlmClientInterface $llmClient,
        protected PolicyScoringService $scoringService,
    ) {}

    /**
     * Check if the current model is a reasoning model that uses hidden reasoning tokens.
     */
    protected function isReasoningModel(): bool
    {
        $model = $this->llmClient->getModel();
        $reasoningModels = ['gpt-5-nano', 'gpt-5', 'o1', 'o3'];

        foreach ($reasoningModels as $prefix) {
            if (str_starts_with($model, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the appropriate max_tokens limit for the current model.
     */
    protected function getMaxTokens(): int
    {
        return $this->isReasoningModel()
            ? self::MAX_TOKENS_REASONING_MODEL
            : self::MAX_TOKENS_STANDARD_MODEL;
    }

    /**
     * Analyze a document version and create an AnalysisResult.
     */
    public function analyze(DocumentVersion $version, string $analysisType = 'full_analysis'): AnalysisResult
    {
        $this->totalInputTokens = 0;
        $this->totalOutputTokens = 0;
        $this->rawAiOutputs = [];
        $this->errors = [];

        $content = $version->content_markdown ?? $version->content_text ?? '';

        if (empty($content)) {
            throw new \RuntimeException('Document version has no content to analyze');
        }

        Log::info('Starting AI analysis', [
            'version_id' => $version->id,
            'document_id' => $version->document_id,
            'content_length' => strlen($content),
        ]);

        // Step 1: Chunk the content
        $chunks = $this->chunkContent($content);

        // Step 2: Analyze each chunk
        $chunkResults = [];
        foreach ($chunks as $index => $chunk) {
            Log::debug("Analyzing chunk {$index}", ['chunk_length' => strlen($chunk)]);
            $chunkResults[] = $this->analyzeChunk($chunk, $index, count($chunks));
        }

        // Step 3: Aggregate results
        $aggregated = $this->aggregateResults($chunkResults);

        // Step 4: Generate overall summary
        $summary = $this->generateOverallSummary($aggregated, $version);

        // Step 5: Calculate scores
        $scoring = $this->scoringService->processAnalysis($aggregated['flags']);

        // Step 6: Generate FAQ
        $faq = $this->generateFaq($version, $aggregated);

        // Step 7: Create AnalysisResult
        $result = AnalysisResult::create([
            'document_version_id' => $version->id,
            'analysis_type' => $analysisType,
            'overall_score' => $scoring['total_score'],
            'overall_rating' => $scoring['grade'],
            'summary' => $summary['summary'],
            'key_concerns' => $this->formatConcerns($aggregated['flags']['red'] ?? []),
            'positive_aspects' => $this->formatPositives($aggregated['flags']['green'] ?? []),
            'recommendations' => $summary['recommendations'] ?? null,
            'extracted_data' => [
                'faq' => $faq,
                'dimension_scores' => $scoring['dimension_scores'],
                'chunk_summaries' => array_column($chunkResults, 'plain_summary'),
            ],
            'flags' => $aggregated['flags'],
            'model_used' => $this->llmClient->getModel(),
            'tokens_used' => $this->totalInputTokens + $this->totalOutputTokens,
            'analysis_cost' => $this->estimateCost(),
            'is_current' => true,
            'processing_errors' => !empty($this->errors) ? $this->errors : null,
        ]);

        $result->markAsCurrent();

        Log::info('AI analysis completed', [
            'analysis_id' => $result->id,
            'score' => $scoring['total_score'],
            'grade' => $scoring['grade'],
            'tokens_used' => $this->totalInputTokens + $this->totalOutputTokens,
        ]);

        return $result;
    }

    /**
     * Split content into chunks by headings or token count.
     */
    public function chunkContent(string $content): array
    {
        $maxChars = self::CHUNK_TOKENS * self::APPROX_CHARS_PER_TOKEN;

        // If content is small enough, return as single chunk
        if (strlen($content) <= $maxChars) {
            return [$content];
        }

        $chunks = [];

        // Try to split by major headings first
        $sections = preg_split('/(?=^#{1,2}\s)/m', $content, -1, PREG_SPLIT_NO_EMPTY);

        $currentChunk = '';
        foreach ($sections as $section) {
            // If adding this section would exceed limit, save current chunk and start new
            if (strlen($currentChunk) + strlen($section) > $maxChars && ! empty($currentChunk)) {
                $chunks[] = trim($currentChunk);
                $currentChunk = '';
            }

            // If section itself is too large, split by paragraphs
            if (strlen($section) > $maxChars) {
                if (! empty($currentChunk)) {
                    $chunks[] = trim($currentChunk);
                    $currentChunk = '';
                }

                $paragraphs = preg_split('/\n\n+/', $section);
                foreach ($paragraphs as $para) {
                    if (strlen($currentChunk) + strlen($para) > $maxChars && ! empty($currentChunk)) {
                        $chunks[] = trim($currentChunk);
                        $currentChunk = '';
                    }
                    $currentChunk .= $para."\n\n";
                }
            } else {
                $currentChunk .= $section;
            }
        }

        if (! empty(trim($currentChunk))) {
            $chunks[] = trim($currentChunk);
        }

        return $chunks;
    }

    /**
     * Analyze a single chunk of content.
     */
    protected function analyzeChunk(string $chunk, int $index, int $totalChunks): array
    {
        $prompt = <<<PROMPT
Analyze the following section of a legal document (section {$index} of {$totalChunks}).

1. Summarize it in plain English (6th grade level).
2. Identify any clauses that may be:
   - risky or unfair to users (red flags),
   - somewhat concerning (yellow flags),
   - particularly protective or fair (green flags).
3. For each flag, explain why it matters in one or two sentences.

Return your answer in the following JSON format:

{
  "plain_summary": "A plain English summary of this section...",
  "flags": {
    "red": [
      { "type": "forced_arbitration", "description": "You must resolve disputes through binding arbitration...", "section_reference": "Section 12", "severity": 10 }
    ],
    "yellow": [
      { "type": "vague_data_sharing", "description": "They may share data with 'partners' without clear definition...", "section_reference": "Section 4", "severity": 6 }
    ],
    "green": [
      { "type": "clear_deletion_rights", "description": "You can delete your data via account settings...", "section_reference": "Section 3", "severity": 3 }
    ]
  }
}

Flag types to look for:
- Red: forced_arbitration, class_action_waiver, sell_data, no_deletion_right, automatic_consent, hidden_terms, excessive_data_collection, biometric_data
- Yellow: vague_data_sharing, third_party_sharing, location_tracking, one_sided_terms, vague_language, continued_use_consent
- Green: clear_deletion_rights, easy_opt_out, plain_language, no_data_selling, minimal_data_collection, proactive_notifications, data_portability, gdpr_compliant

Here is the section:

<<<SECTION_START>>>
{$chunk}
<<<SECTION_END>>>
PROMPT;

        try {
            $response = $this->llmClient->complete([
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user', 'content' => $prompt],
            ], [
                'temperature' => 0.2,
                'max_tokens' => $this->getMaxTokens(),
            ]);

            $this->trackTokens($response);
            $this->rawAiOutputs[] = [
                'chunk' => $index,
                'response' => $response->content,
                'truncated' => $response->wasTruncated(),
                'reasoning_tokens' => $response->rawResponse['usage']['completion_tokens_details']['reasoning_tokens'] ?? null,
            ];

            // Check if response was truncated
            if ($response->wasTruncated()) {
                Log::warning('Chunk analysis truncated', [
                    'chunk_index' => $index,
                    'output_tokens' => $response->outputTokens,
                ]);
                $this->errors[] = "Chunk {$index}: Response was truncated (hit token limit)";
            }

            return $this->parseJsonResponse($response->content, $index);
        } catch (\Exception $e) {
            // Get raw response content if available for debugging
            $rawContent = isset($response) ? $response->content : 'No response captured';
            $reasoningTokens = isset($response) ? ($response->rawResponse['usage']['completion_tokens_details']['reasoning_tokens'] ?? null) : null;

            Log::error('Chunk analysis failed', [
                'chunk_index' => $index,
                'error' => $e->getMessage(),
                'response_empty' => empty($rawContent),
                'response_length' => is_string($rawContent) ? strlen($rawContent) : 0,
                'response_preview' => is_string($rawContent) ? substr($rawContent, 0, 500) : null,
                'reasoning_tokens' => $reasoningTokens,
                'is_reasoning_model' => $this->isReasoningModel(),
            ]);

            $errorDetail = $e->getMessage();
            if (isset($response) && empty($response->content)) {
                $errorDetail = "Empty response from model (reasoning_tokens: {$reasoningTokens}). Model may need higher token limit.";
            }

            $this->errors[] = "Chunk {$index}: {$errorDetail}";

            return [
                'plain_summary' => "Analysis failed: {$errorDetail}",
                'flags' => ['red' => [], 'yellow' => [], 'green' => []],
                'error' => $errorDetail,
            ];
        }
    }

    /**
     * Parse JSON response with better error handling and recovery.
     */
    protected function parseJsonResponse(string $content, int $chunkIndex): array
    {
        // Try to extract JSON from markdown code blocks if present
        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?```/s', $content, $matches)) {
            $content = trim($matches[1]);
        }

        // Try standard JSON decode first
        $decoded = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        // Try to repair truncated JSON
        $repaired = $this->repairTruncatedJson($content);
        if ($repaired) {
            Log::info('Repaired truncated JSON for chunk', ['chunk_index' => $chunkIndex]);
            return $repaired;
        }

        // Log the raw response for debugging
        Log::error('Failed to parse JSON response', [
            'chunk_index' => $chunkIndex,
            'json_error' => json_last_error_msg(),
            'content_preview' => substr($content, 0, 500),
            'content_end' => substr($content, -200),
        ]);

        $this->errors[] = "Chunk {$chunkIndex}: Invalid JSON response - " . json_last_error_msg();

        return [
            'plain_summary' => 'Analysis failed for this section due to parsing error.',
            'flags' => ['red' => [], 'yellow' => [], 'green' => []],
            'error' => 'JSON parse error: ' . json_last_error_msg(),
        ];
    }

    /**
     * Attempt to repair truncated JSON by closing open structures.
     */
    protected function repairTruncatedJson(string $json): ?array
    {
        // Count open brackets/braces
        $openBraces = substr_count($json, '{') - substr_count($json, '}');
        $openBrackets = substr_count($json, '[') - substr_count($json, ']');

        // If roughly balanced, probably not truncated JSON
        if ($openBraces <= 0 && $openBrackets <= 0) {
            return null;
        }

        // Try to close the JSON structure
        $repaired = $json;

        // Remove any trailing incomplete string
        $repaired = preg_replace('/,?\s*"[^"]*$/', '', $repaired);

        // Close open brackets then braces
        $repaired .= str_repeat(']', max(0, $openBrackets));
        $repaired .= str_repeat('}', max(0, $openBraces));

        $decoded = json_decode($repaired, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        return null;
    }

    /**
     * Aggregate results from multiple chunks.
     */
    protected function aggregateResults(array $chunkResults): array
    {
        $aggregated = [
            'summaries' => [],
            'flags' => [
                'red' => [],
                'yellow' => [],
                'green' => [],
            ],
        ];

        foreach ($chunkResults as $result) {
            if (! empty($result['plain_summary'])) {
                $aggregated['summaries'][] = $result['plain_summary'];
            }

            foreach (['red', 'yellow', 'green'] as $color) {
                if (! empty($result['flags'][$color])) {
                    $aggregated['flags'][$color] = array_merge(
                        $aggregated['flags'][$color],
                        $result['flags'][$color]
                    );
                }
            }
        }

        // Deduplicate flags by type
        foreach (['red', 'yellow', 'green'] as $color) {
            $aggregated['flags'][$color] = $this->deduplicateFlags($aggregated['flags'][$color]);
        }

        return $aggregated;
    }

    /**
     * Remove duplicate flags, keeping the one with highest severity.
     */
    protected function deduplicateFlags(array $flags): array
    {
        $unique = [];
        foreach ($flags as $flag) {
            $type = $flag['type'] ?? 'unknown';
            if (! isset($unique[$type]) || ($flag['severity'] ?? 0) > ($unique[$type]['severity'] ?? 0)) {
                $unique[$type] = $flag;
            }
        }

        return array_values($unique);
    }

    /**
     * Generate overall summary from aggregated results.
     */
    protected function generateOverallSummary(array $aggregated, DocumentVersion $version): array
    {
        $chunkSummaries = implode("\n\n", $aggregated['summaries']);
        $redCount = count($aggregated['flags']['red']);
        $yellowCount = count($aggregated['flags']['yellow']);
        $greenCount = count($aggregated['flags']['green']);

        $documentType = $version->document?->documentType?->name ?? 'legal document';
        $companyName = $version->document?->company?->name ?? 'the company';

        $prompt = <<<PROMPT
Based on the following section summaries and analysis results, create an overall summary of this {$documentType} from {$companyName}.

Section Summaries:
{$chunkSummaries}

Analysis found:
- {$redCount} serious concerns (red flags)
- {$yellowCount} moderate concerns (yellow flags)
- {$greenCount} positive aspects (green flags)

Provide:
1. A 2-3 paragraph executive summary explaining what this document means for users
2. 2-3 key recommendations for users

Return JSON:
{
  "summary": "Your executive summary here...",
  "recommendations": "Your recommendations here..."
}
PROMPT;

        try {
            $response = $this->llmClient->complete([
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user', 'content' => $prompt],
            ], [
                'temperature' => 0.3,
                'max_tokens' => $this->getMaxTokens(),
            ]);

            $this->trackTokens($response);
            $this->rawAiOutputs[] = ['summary' => true, 'response' => $response->content];

            return $response->json();
        } catch (\Exception $e) {
            Log::error('Summary generation failed', ['error' => $e->getMessage()]);

            return [
                'summary' => implode(' ', array_slice($aggregated['summaries'], 0, 3)),
                'recommendations' => null,
            ];
        }
    }

    /**
     * Generate FAQ for the document.
     */
    public function generateFaq(DocumentVersion $version, array $aggregated): array
    {
        $documentType = $version->document?->documentType?->name ?? 'legal document';

        $prompt = <<<PROMPT
Generate 5-8 frequently asked questions that a non-lawyer would want answered about this {$documentType}.

Key points from analysis:
- Red flags: {$this->formatFlagTypesForPrompt($aggregated['flags']['red'] ?? [])}
- Yellow flags: {$this->formatFlagTypesForPrompt($aggregated['flags']['yellow'] ?? [])}
- Green flags: {$this->formatFlagTypesForPrompt($aggregated['flags']['green'] ?? [])}

Always include questions about:
- What data is collected
- How data is shared
- How to delete data/account
- How disputes are resolved

Return JSON array:
[
  {
    "question": "What personal data does this company collect about me?",
    "short_answer": "Brief answer in 1-2 sentences",
    "long_answer": "Detailed answer in 2-3 sentences",
    "risk_level": 6,
    "what_to_watch_for": "Specific thing users should be aware of"
  }
]
PROMPT;

        try {
            $response = $this->llmClient->complete([
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user', 'content' => $prompt],
            ], [
                'temperature' => 0.4,
                'max_tokens' => $this->getMaxTokens(),
            ]);

            $this->trackTokens($response);
            $this->rawAiOutputs[] = ['faq' => true, 'response' => $response->content];

            return $response->json();
        } catch (\Exception $e) {
            Log::error('FAQ generation failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Format flag types for inclusion in prompts.
     */
    protected function formatFlagTypesForPrompt(array $flags): string
    {
        if (empty($flags)) {
            return 'None found';
        }

        $types = array_map(fn ($f) => $f['type'] ?? 'unknown', $flags);

        return implode(', ', array_unique($types));
    }

    /**
     * Format red flags as key concerns text.
     */
    protected function formatConcerns(array $redFlags): ?string
    {
        if (empty($redFlags)) {
            return null;
        }

        $concerns = array_map(
            fn ($f) => "- **{$f['type']}**: {$f['description']}",
            $redFlags
        );

        return implode("\n", $concerns);
    }

    /**
     * Format green flags as positive aspects text.
     */
    protected function formatPositives(array $greenFlags): ?string
    {
        if (empty($greenFlags)) {
            return null;
        }

        $positives = array_map(
            fn ($f) => "- **{$f['type']}**: {$f['description']}",
            $greenFlags
        );

        return implode("\n", $positives);
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
     * Get the raw AI outputs for debugging.
     */
    public function getRawOutputs(): array
    {
        return $this->rawAiOutputs;
    }
}
