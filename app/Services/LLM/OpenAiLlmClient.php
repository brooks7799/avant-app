<?php

namespace App\Services\LLM;

class OpenAiLlmClient extends AbstractLlmClient
{
    protected ?string $organization;

    protected function loadProviderConfig(): void
    {
        $providerConfig = $this->config['openai'] ?? [];

        $this->apiKey = $providerConfig['api_key'] ?? '';
        $this->baseUrl = $providerConfig['base_url'] ?? 'https://api.openai.com/v1';
        $this->model = $providerConfig['model'] ?? 'gpt-4o';
        $this->organization = $providerConfig['organization'] ?? null;
    }

    public function getProviderName(): string
    {
        return 'openai';
    }

    protected function getAdditionalHeaders(): array
    {
        $headers = [];

        if ($this->organization) {
            $headers['OpenAI-Organization'] = $this->organization;
        }

        return $headers;
    }

    protected function buildPayload(array $messages, array $options): array
    {
        $payload = [
            'model' => $options['model'] ?? $this->model,
            'messages' => $messages,
        ];

        // Add optional parameters
        // Some models (gpt-5-nano, o1, o3) only support default temperature
        if (!$this->isFixedTemperatureModel()) {
            if (isset($options['temperature'])) {
                $payload['temperature'] = (float) $options['temperature'];
            } else {
                $payload['temperature'] = (float) ($this->config['settings']['temperature'] ?? 0.2);
            }
        }

        // Newer OpenAI models (gpt-4o, gpt-5, etc.) use max_completion_tokens
        // Older models use max_tokens
        $maxTokensKey = $this->useMaxCompletionTokens() ? 'max_completion_tokens' : 'max_tokens';
        if (isset($options['max_tokens'])) {
            $payload[$maxTokensKey] = (int) $options['max_tokens'];
        } else {
            $payload[$maxTokensKey] = (int) ($this->config['settings']['max_tokens'] ?? 4096);
        }

        if (isset($options['top_p'])) {
            $payload['top_p'] = $options['top_p'];
        }

        if (isset($options['frequency_penalty'])) {
            $payload['frequency_penalty'] = $options['frequency_penalty'];
        }

        if (isset($options['presence_penalty'])) {
            $payload['presence_penalty'] = $options['presence_penalty'];
        }

        if (isset($options['stop'])) {
            $payload['stop'] = $options['stop'];
        }

        // OpenAI-specific: response format for JSON mode (not supported by all models)
        if (isset($options['response_format']) && $this->supportsJsonResponseFormat()) {
            $payload['response_format'] = $options['response_format'];
        }

        // OpenAI-specific: seed for reproducibility
        if (isset($options['seed'])) {
            $payload['seed'] = $options['seed'];
        }

        return $payload;
    }

    /**
     * Check if the model uses max_completion_tokens instead of max_tokens.
     * Newer models (gpt-4o, gpt-5, o1, etc.) require this parameter.
     */
    protected function useMaxCompletionTokens(): bool
    {
        $model = $this->model;

        // Models that use max_completion_tokens
        $newApiModels = ['gpt-4o', 'gpt-5', 'o1', 'o3'];

        foreach ($newApiModels as $prefix) {
            if (str_starts_with($model, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the model only supports fixed temperature (default=1).
     * Some newer models like gpt-5-nano, o1, o3 don't allow custom temperature.
     */
    protected function isFixedTemperatureModel(): bool
    {
        $model = $this->model;

        // Models that only support default temperature
        $fixedTempModels = ['gpt-5-nano', 'o1', 'o3'];

        foreach ($fixedTempModels as $prefix) {
            if (str_starts_with($model, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the model supports JSON response format.
     * Some models may not support this feature.
     */
    protected function supportsJsonResponseFormat(): bool
    {
        $model = $this->model;

        // Models that may not support response_format
        $unsupportedModels = ['gpt-5-nano', 'o1', 'o3'];

        foreach ($unsupportedModels as $prefix) {
            if (str_starts_with($model, $prefix)) {
                return false;
            }
        }

        return true;
    }

    protected function parseResponse(array $data, float $latencyMs): LlmResponse
    {
        $choice = $data['choices'][0] ?? [];
        $usage = $data['usage'] ?? [];
        $message = $choice['message'] ?? [];

        // Try different content locations (varies by model)
        $content = $message['content'] ?? '';

        // Some reasoning models (o1, o3, gpt-5-nano) may put content in different places
        if (empty($content)) {
            // Check for reasoning_content (o1/o3 style)
            $content = $message['reasoning_content'] ?? '';
        }
        if (empty($content)) {
            // Check for text field
            $content = $message['text'] ?? '';
        }

        // Log warning if content is empty but we expected a response
        if (empty($content) && !empty($data['choices'])) {
            \Illuminate\Support\Facades\Log::warning('OpenAI returned empty content', [
                'finish_reason' => $choice['finish_reason'] ?? 'unknown',
                'model' => $data['model'] ?? $this->model,
                'choice_keys' => array_keys($choice),
                'message_keys' => array_keys($message),
                'message_preview' => json_encode(array_map(
                    fn($v) => is_string($v) ? substr($v, 0, 100) : $v,
                    $message
                )),
                'refusal' => $message['refusal'] ?? null,
                'output_tokens' => $usage['completion_tokens'] ?? 0,
            ]);
        }

        return new LlmResponse(
            content: $content,
            model: $data['model'] ?? $this->model,
            inputTokens: $usage['prompt_tokens'] ?? null,
            outputTokens: $usage['completion_tokens'] ?? null,
            finishReason: $choice['finish_reason'] ?? null,
            requestId: $data['id'] ?? null,
            latencyMs: $latencyMs,
            rawResponse: $data,
        );
    }
}
