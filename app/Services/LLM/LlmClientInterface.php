<?php

namespace App\Services\LLM;

interface LlmClientInterface
{
    /**
     * Send a completion request to the LLM.
     *
     * @param  array  $messages  Array of message objects with 'role' and 'content' keys
     * @param  array  $options  Additional options (temperature, max_tokens, etc.)
     * @return LlmResponse
     */
    public function complete(array $messages, array $options = []): LlmResponse;

    /**
     * Send a completion request and parse the response as JSON.
     *
     * @param  array  $messages  Array of message objects with 'role' and 'content' keys
     * @param  array  $options  Additional options (temperature, max_tokens, etc.)
     * @return array  Parsed JSON response
     *
     * @throws \JsonException  If response is not valid JSON
     */
    public function completeJson(array $messages, array $options = []): array;

    /**
     * Send a streaming completion request to the LLM.
     *
     * @param  array  $messages  Array of message objects with 'role' and 'content' keys
     * @param  array  $options  Additional options (temperature, max_tokens, etc.)
     * @return \Generator<string>  Yields content chunks as they arrive
     */
    public function streamComplete(array $messages, array $options = []): \Generator;

    /**
     * Get the name of this provider.
     */
    public function getProviderName(): string;

    /**
     * Get the current model being used.
     */
    public function getModel(): string;
}
