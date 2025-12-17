<?php

namespace App\Services\LLM;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

abstract class AbstractLlmClient implements LlmClientInterface
{
    protected string $apiKey;

    protected string $baseUrl;

    protected string $model;

    protected array $config;

    public function __construct()
    {
        $this->config = config('llm');
        $this->loadProviderConfig();
    }

    /**
     * Load provider-specific configuration.
     */
    abstract protected function loadProviderConfig(): void;

    /**
     * Build the request payload for the specific provider.
     */
    abstract protected function buildPayload(array $messages, array $options): array;

    /**
     * Parse the provider-specific response format.
     */
    abstract protected function parseResponse(array $data, float $latencyMs): LlmResponse;

    /**
     * Get additional headers for the specific provider.
     */
    protected function getAdditionalHeaders(): array
    {
        return [];
    }

    public function complete(array $messages, array $options = []): LlmResponse
    {
        $this->checkRateLimit();

        $startTime = microtime(true);

        $payload = $this->buildPayload($messages, $options);

        $response = $this->makeRequest($payload);

        $latencyMs = (microtime(true) - $startTime) * 1000;

        if (! $response->successful()) {
            $this->handleError($response);
        }

        $llmResponse = $this->parseResponse($response->json(), $latencyMs);

        $this->logRequest($messages, $llmResponse);

        return $llmResponse;
    }

    public function completeJson(array $messages, array $options = []): array
    {
        // Add JSON instruction to the last message if not present
        $lastMessage = end($messages);
        if (! str_contains(strtolower($lastMessage['content'] ?? ''), 'json')) {
            $messages[array_key_last($messages)]['content'] .= "\n\nRespond with valid JSON only.";
        }

        // Some providers support JSON mode
        $options['response_format'] = ['type' => 'json_object'];

        $response = $this->complete($messages, $options);

        return $response->json();
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function streamComplete(array $messages, array $options = []): \Generator
    {
        $this->checkRateLimit();

        $payload = $this->buildPayload($messages, $options);
        $payload['stream'] = true;

        $url = rtrim($this->baseUrl, '/').'/chat/completions';

        $allHeaders = array_merge([
            'Authorization' => 'Bearer '.$this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'text/event-stream',
        ], $this->getAdditionalHeaders());

        // Build curl command for subprocess streaming
        $headerArgs = [];
        foreach ($allHeaders as $key => $value) {
            $headerArgs[] = '-H';
            $headerArgs[] = escapeshellarg("{$key}: {$value}");
        }

        $jsonPayload = json_encode($payload);
        $tempFile = tempnam(sys_get_temp_dir(), 'llm_payload_');
        file_put_contents($tempFile, $jsonPayload);

        $cmd = sprintf(
            'curl -sS -N --no-buffer -X POST %s -d @%s %s 2>&1',
            implode(' ', $headerArgs),
            escapeshellarg($tempFile),
            escapeshellarg($url)
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);

        if (! is_resource($process)) {
            @unlink($tempFile);
            throw new \RuntimeException('Failed to start curl process for streaming');
        }

        fclose($pipes[0]); // Close stdin

        stream_set_blocking($pipes[1], false);
        $buffer = '';

        try {
            while (! feof($pipes[1])) {
                $chunk = fread($pipes[1], 4096);
                if ($chunk === false || $chunk === '') {
                    usleep(1000); // 1ms wait
                    continue;
                }

                $buffer .= $chunk;

                // Process complete SSE lines
                while (($newlinePos = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $newlinePos));
                    $buffer = substr($buffer, $newlinePos + 1);

                    if (str_starts_with($line, 'data: ')) {
                        $jsonData = substr($line, 6);

                        if ($jsonData === '[DONE]') {
                            continue;
                        }

                        $json = json_decode($jsonData, true);
                        if ($json && isset($json['choices'][0]['delta']['content'])) {
                            yield $json['choices'][0]['delta']['content'];
                        }
                    }
                }
            }
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            @unlink($tempFile);
        }
    }

    /**
     * Create the HTTP client with common configuration.
     */
    protected function createHttpClient(): PendingRequest
    {
        $headers = array_merge([
            'Authorization' => 'Bearer '.$this->apiKey,
            'Content-Type' => 'application/json',
        ], $this->getAdditionalHeaders());

        return Http::withHeaders($headers)
            ->timeout($this->config['settings']['timeout'] ?? 120)
            ->connectTimeout($this->config['settings']['connect_timeout'] ?? 10);
    }

    /**
     * Make the API request with retry logic.
     */
    protected function makeRequest(array $payload): Response
    {
        $client = $this->createHttpClient();
        $url = rtrim($this->baseUrl, '/').'/chat/completions';

        $retryConfig = $this->config['retry'] ?? [];
        $maxAttempts = $retryConfig['max_attempts'] ?? 3;
        $delay = $retryConfig['initial_delay_ms'] ?? 1000;
        $multiplier = $retryConfig['multiplier'] ?? 2;
        $retryableCodes = $retryConfig['retryable_status_codes'] ?? [429, 500, 502, 503, 504];

        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxAttempts) {
            $attempt++;

            try {
                $response = $client->post($url, $payload);

                if ($response->successful() || ! in_array($response->status(), $retryableCodes)) {
                    return $response;
                }

                // Rate limited - check for Retry-After header
                if ($response->status() === 429) {
                    $retryAfter = $response->header('Retry-After');
                    if ($retryAfter) {
                        $delay = max($delay, (int) $retryAfter * 1000);
                    }
                }

                Log::warning("LLM request failed (attempt {$attempt}/{$maxAttempts})", [
                    'provider' => $this->getProviderName(),
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

            } catch (\Exception $e) {
                $lastException = $e;
                Log::warning("LLM request exception (attempt {$attempt}/{$maxAttempts})", [
                    'provider' => $this->getProviderName(),
                    'error' => $e->getMessage(),
                ]);
            }

            if ($attempt < $maxAttempts) {
                usleep($delay * 1000);
                $delay *= $multiplier;
            }
        }

        // Return last response or throw exception
        if (isset($response)) {
            return $response;
        }

        throw $lastException ?? new \RuntimeException('LLM request failed after all retries');
    }

    /**
     * Check and enforce rate limiting.
     */
    protected function checkRateLimit(): void
    {
        if (! ($this->config['rate_limiting']['enabled'] ?? false)) {
            return;
        }

        $key = 'llm-rate-limit:'.$this->getProviderName();
        $maxAttempts = $this->config['rate_limiting']['requests_per_minute'] ?? 60;

        $executed = RateLimiter::attempt(
            $key,
            $maxAttempts,
            fn () => true,
            60 // Decay time in seconds
        );

        if (! $executed) {
            $seconds = RateLimiter::availableIn($key);
            throw new \RuntimeException("Rate limit exceeded. Try again in {$seconds} seconds.");
        }
    }

    /**
     * Handle API errors.
     */
    protected function handleError(Response $response): never
    {
        $body = $response->json() ?? [];
        $errorMessage = $body['error']['message']
            ?? $body['error']
            ?? $body['message']
            ?? 'Unknown error';

        Log::error('LLM API error', [
            'provider' => $this->getProviderName(),
            'status' => $response->status(),
            'error' => $errorMessage,
            'body' => $response->body(),
        ]);

        throw new \RuntimeException(
            "LLM API error ({$this->getProviderName()}): {$errorMessage}",
            $response->status()
        );
    }

    /**
     * Log the request and response.
     */
    protected function logRequest(array $messages, LlmResponse $response): void
    {
        $loggingConfig = $this->config['logging'] ?? [];

        if (! ($loggingConfig['enabled'] ?? false)) {
            return;
        }

        $channel = $loggingConfig['channel'] ?? 'stack';
        $logData = [
            'provider' => $this->getProviderName(),
            'model' => $response->model,
            'latency_ms' => $response->latencyMs,
        ];

        if ($loggingConfig['log_prompts'] ?? false) {
            $logData['messages'] = $messages;
        }

        if ($loggingConfig['log_responses'] ?? false) {
            $logData['response'] = $response->content;
        }

        if ($loggingConfig['log_token_usage'] ?? false) {
            $logData['input_tokens'] = $response->inputTokens;
            $logData['output_tokens'] = $response->outputTokens;
            $logData['total_tokens'] = $response->totalTokens();
        }

        if ($loggingConfig['log_costs'] ?? false) {
            $logData['estimated_cost'] = $response->estimateCost();
        }

        Log::channel($channel)->info('LLM request completed', $logData);
    }
}
