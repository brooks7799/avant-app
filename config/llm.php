<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default LLM Provider
    |--------------------------------------------------------------------------
    |
    | This option controls the default LLM provider that will be used for
    | AI analysis tasks. You may set this to any of the providers defined
    | below: "openrouter", "openai", or "local".
    |
    */

    'default' => env('LLM_PROVIDER', 'openrouter'),

    /*
    |--------------------------------------------------------------------------
    | OpenRouter Configuration
    |--------------------------------------------------------------------------
    |
    | OpenRouter provides access to multiple AI models through a single API.
    | This is the recommended provider for AVANT as it offers flexibility
    | in model selection and competitive pricing.
    |
    */

    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'model' => env('OPENROUTER_MODEL', 'anthropic/claude-3.5-sonnet'),
        'site_url' => env('APP_URL', 'http://localhost'), // For OpenRouter rankings
        'site_name' => env('APP_NAME', 'AVANT'),
    ],

    /*
    |--------------------------------------------------------------------------
    | OpenAI Configuration
    |--------------------------------------------------------------------------
    |
    | Direct OpenAI API access. Use this if you have an OpenAI API key
    | and prefer to use OpenAI models directly.
    |
    */

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('OPENAI_MODEL', 'gpt-4o'),
        'organization' => env('OPENAI_ORGANIZATION'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Local LLM Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for running local LLMs via Ollama, LM Studio, or vLLM.
    | Useful for cost savings on high-volume, lower-stakes tasks.
    |
    */

    'local' => [
        'base_url' => env('LOCAL_LLM_URL', 'http://localhost:11434'),
        'model' => env('LOCAL_LLM_MODEL', 'mistral'),
        'api_format' => env('LOCAL_LLM_FORMAT', 'ollama'), // ollama, openai-compatible
    ],

    /*
    |--------------------------------------------------------------------------
    | Request Settings
    |--------------------------------------------------------------------------
    |
    | Default settings for LLM API requests. These can be overridden
    | on a per-request basis.
    |
    */

    'settings' => [
        'timeout' => env('LLM_TIMEOUT', 120),
        'connect_timeout' => env('LLM_CONNECT_TIMEOUT', 10),
        'max_tokens' => env('LLM_MAX_TOKENS', 4096),
        'temperature' => env('LLM_TEMPERATURE', 0.2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Rate limiting configuration to prevent exceeding API quotas.
    | Uses Laravel's rate limiter under the hood.
    |
    */

    'rate_limiting' => [
        'enabled' => env('LLM_RATE_LIMIT_ENABLED', true),
        'requests_per_minute' => env('LLM_RATE_LIMIT_RPM', 60),
        'tokens_per_minute' => env('LLM_RATE_LIMIT_TPM', 100000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for automatic retry on transient failures.
    |
    */

    'retry' => [
        'max_attempts' => env('LLM_RETRY_ATTEMPTS', 3),
        'initial_delay_ms' => env('LLM_RETRY_DELAY', 1000),
        'multiplier' => 2, // Exponential backoff multiplier
        'retryable_status_codes' => [429, 500, 502, 503, 504],
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging & Auditing
    |--------------------------------------------------------------------------
    |
    | Control what gets logged for LLM requests. Useful for debugging,
    | cost tracking, and audit trails.
    |
    */

    'logging' => [
        'enabled' => env('LLM_LOGGING_ENABLED', true),
        'channel' => env('LLM_LOG_CHANNEL', 'stack'),
        'log_prompts' => env('LLM_LOG_PROMPTS', false), // Be careful with sensitive data
        'log_responses' => env('LLM_LOG_RESPONSES', false),
        'log_token_usage' => env('LLM_LOG_TOKENS', true),
        'log_costs' => env('LLM_LOG_COSTS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Pricing (per 1M tokens)
    |--------------------------------------------------------------------------
    |
    | Approximate pricing for cost tracking. Update as needed.
    | Format: 'model' => ['input' => cost, 'output' => cost]
    |
    */

    'pricing' => [
        'anthropic/claude-3.5-sonnet' => ['input' => 3.00, 'output' => 15.00],
        'anthropic/claude-3-haiku' => ['input' => 0.25, 'output' => 1.25],
        'openai/gpt-4o' => ['input' => 2.50, 'output' => 10.00],
        'openai/gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
        'gpt-4o' => ['input' => 2.50, 'output' => 10.00],
        'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
    ],

];
