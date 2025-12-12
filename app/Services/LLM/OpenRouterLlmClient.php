<?php

namespace App\Services\LLM;

class OpenRouterLlmClient extends AbstractLlmClient
{
    protected string $siteUrl;

    protected string $siteName;

    protected function loadProviderConfig(): void
    {
        $providerConfig = $this->config['openrouter'] ?? [];

        $this->apiKey = $providerConfig['api_key'] ?? '';
        $this->baseUrl = $providerConfig['base_url'] ?? 'https://openrouter.ai/api/v1';
        $this->model = $providerConfig['model'] ?? 'anthropic/claude-3.5-sonnet';
        $this->siteUrl = $providerConfig['site_url'] ?? config('app.url');
        $this->siteName = $providerConfig['site_name'] ?? config('app.name');
    }

    public function getProviderName(): string
    {
        return 'openrouter';
    }

    protected function getAdditionalHeaders(): array
    {
        return [
            'HTTP-Referer' => $this->siteUrl,
            'X-Title' => $this->siteName,
        ];
    }

    protected function buildPayload(array $messages, array $options): array
    {
        $payload = [
            'model' => $options['model'] ?? $this->model,
            'messages' => $messages,
        ];

        // Add optional parameters
        if (isset($options['temperature'])) {
            $payload['temperature'] = $options['temperature'];
        } else {
            $payload['temperature'] = $this->config['settings']['temperature'] ?? 0.2;
        }

        if (isset($options['max_tokens'])) {
            $payload['max_tokens'] = $options['max_tokens'];
        } else {
            $payload['max_tokens'] = $this->config['settings']['max_tokens'] ?? 4096;
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

        // OpenRouter-specific: response format for JSON mode
        if (isset($options['response_format'])) {
            $payload['response_format'] = $options['response_format'];
        }

        // OpenRouter-specific: route through specific provider
        if (isset($options['provider'])) {
            $payload['provider'] = $options['provider'];
        }

        return $payload;
    }

    protected function parseResponse(array $data, float $latencyMs): LlmResponse
    {
        $choice = $data['choices'][0] ?? [];
        $usage = $data['usage'] ?? [];

        return new LlmResponse(
            content: $choice['message']['content'] ?? '',
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
