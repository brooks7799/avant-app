<?php

namespace App\Providers;

use App\Services\LLM\LlmClientInterface;
use App\Services\LLM\OpenAiLlmClient;
use App\Services\LLM\OpenRouterLlmClient;
use Illuminate\Support\ServiceProvider;

class LlmServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(LlmClientInterface::class, function () {
            $provider = config('llm.default', 'openrouter');

            return match ($provider) {
                'openrouter' => new OpenRouterLlmClient,
                'openai' => new OpenAiLlmClient,
                // Add local provider later if needed
                // 'local' => new LocalOllamaLlmClient(),
                default => new OpenRouterLlmClient,
            };
        });

        // Also register concrete implementations for direct injection
        $this->app->singleton(OpenRouterLlmClient::class);
        $this->app->singleton(OpenAiLlmClient::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
