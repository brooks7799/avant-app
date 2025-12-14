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

        // First attempt: direct parse
        $result = json_decode($content, true, 512);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $result;
        }

        // Second attempt: fix common issues with LLM-generated JSON
        // Fix unescaped newlines inside string values
        $fixed = preg_replace_callback(
            '/"([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"/s',
            function ($match) {
                // Escape literal newlines inside strings
                $str = str_replace(["\r\n", "\r", "\n"], ["\\r\\n", "\\r", "\\n"], $match[1]);
                // Escape literal tabs
                $str = str_replace("\t", "\\t", $str);
                return '"' . $str . '"';
            },
            $content
        );

        $result = json_decode($fixed, true, 512);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $result;
        }

        // Third attempt: try to find JSON object boundaries
        if (preg_match('/\{[\s\S]*\}/s', $content, $jsonMatch)) {
            $jsonContent = $jsonMatch[0];

            // Apply the same fix
            $fixed = preg_replace_callback(
                '/"([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"/s',
                function ($match) {
                    $str = str_replace(["\r\n", "\r", "\n"], ["\\r\\n", "\\r", "\\n"], $match[1]);
                    $str = str_replace("\t", "\\t", $str);
                    return '"' . $str . '"';
                },
                $jsonContent
            );

            $result = json_decode($fixed, true, 512);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $result;
            }
        }

        // If all attempts fail, throw with original error
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
