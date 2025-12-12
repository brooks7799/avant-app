<?php

namespace App\Services\LLM;

class LlmResponse
{
    public function __construct(
        public readonly string $content,
        public readonly string $model,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly ?string $finishReason = null,
        public readonly ?string $requestId = null,
        public readonly ?float $latencyMs = null,
        public readonly array $rawResponse = [],
    ) {}

    /**
     * Get the total tokens used in this request.
     */
    public function totalTokens(): int
    {
        return ($this->inputTokens ?? 0) + ($this->outputTokens ?? 0);
    }

    /**
     * Calculate the estimated cost of this request.
     */
    public function estimateCost(?array $pricing = null): float
    {
        $pricing ??= config('llm.pricing');

        // Try exact model match first, then without provider prefix
        $modelPricing = $pricing[$this->model]
            ?? $pricing[str_replace('/', '', strrchr($this->model, '/') ?: $this->model)]
            ?? null;

        if (! $modelPricing) {
            return 0.0;
        }

        $inputCost = (($this->inputTokens ?? 0) / 1_000_000) * ($modelPricing['input'] ?? 0);
        $outputCost = (($this->outputTokens ?? 0) / 1_000_000) * ($modelPricing['output'] ?? 0);

        return $inputCost + $outputCost;
    }

    /**
     * Parse the content as JSON.
     *
     * @throws \JsonException
     */
    public function json(): array
    {
        // Try to extract JSON from markdown code blocks if present
        $content = $this->content;

        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?```/s', $content, $matches)) {
            $content = trim($matches[1]);
        }

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Check if the response was truncated.
     */
    public function wasTruncated(): bool
    {
        return $this->finishReason === 'length';
    }

    /**
     * Convert to array for storage/logging.
     */
    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'model' => $this->model,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'total_tokens' => $this->totalTokens(),
            'finish_reason' => $this->finishReason,
            'request_id' => $this->requestId,
            'latency_ms' => $this->latencyMs,
            'estimated_cost' => $this->estimateCost(),
        ];
    }
}
