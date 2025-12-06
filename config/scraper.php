<?php

return [

    /*
    |--------------------------------------------------------------------------
    | User Agent Configuration
    |--------------------------------------------------------------------------
    |
    | The user agent string sent with HTTP requests when scraping websites.
    |
    */

    'user_agent' => env('SCRAPER_USER_AGENT', 'AvantBot/1.0 (Legal Document Tracker; +https://avant.app)'),

    /*
    |--------------------------------------------------------------------------
    | HTTP Timeouts
    |--------------------------------------------------------------------------
    |
    | Connection and request timeout settings in seconds.
    |
    */

    'timeouts' => [
        'connect' => env('SCRAPER_TIMEOUT_CONNECT', 10),
        'request' => env('SCRAPER_TIMEOUT_REQUEST', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Settings to avoid overwhelming target servers.
    |
    */

    'rate_limiting' => [
        'requests_per_second' => env('SCRAPER_RATE_LIMIT', 2),
        'delay_between_requests_ms' => env('SCRAPER_DELAY_MS', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Policy Discovery Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for automatic policy URL discovery.
    |
    */

    'discovery' => [
        // Maximum depth to crawl from homepage
        'max_depth' => 3,

        // Maximum number of pages to crawl per website
        'max_pages' => 100,

        // Common paths to check for legal documents
        'common_paths' => [
            // Privacy policies
            '/privacy',
            '/privacy-policy',
            '/privacypolicy',
            '/legal/privacy',
            '/legal/privacy-policy',
            '/about/privacy',

            // Terms of service
            '/terms',
            '/terms-of-service',
            '/termsofservice',
            '/tos',
            '/legal/terms',
            '/legal/terms-of-service',
            '/about/terms',

            // Cookie policies
            '/cookies',
            '/cookie-policy',
            '/cookiepolicy',
            '/legal/cookies',

            // Other legal documents
            '/legal',
            '/policies',
            '/eula',
            '/acceptable-use',
            '/acceptable-use-policy',
            '/aup',
            '/gdpr',
            '/ccpa',
            '/data-protection',
            '/dmca',
        ],

        // Keywords to identify policy pages from link text and URLs
        'policy_keywords' => [
            'privacy',
            'terms',
            'cookie',
            'legal',
            'policy',
            'policies',
            'tos',
            'eula',
            'gdpr',
            'ccpa',
            'data-protection',
            'data protection',
            'acceptable use',
            'dmca',
        ],

        // Document type patterns for URL matching
        'type_patterns' => [
            'privacy-policy' => ['privacy', 'datenschutz', 'confidential'],
            'terms-of-service' => ['terms', 'tos', 'conditions', 'user-agreement', 'eula'],
            'cookie-policy' => ['cookie', 'cookies', 'tracking'],
            'acceptable-use-policy' => ['acceptable-use', 'aup', 'fair-use'],
            'data-processing-agreement' => ['dpa', 'data-processing', 'processor'],
            'ccpa-notice' => ['ccpa', 'california', 'do-not-sell'],
            'community-guidelines' => ['community', 'guidelines', 'rules', 'code-of-conduct'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Content Extraction Settings
    |--------------------------------------------------------------------------
    |
    | CSS selectors for extracting main content from pages.
    |
    */

    'extraction' => [
        // Selectors to find main content (tried in order)
        'content_selectors' => [
            'main',
            'article',
            '[role="main"]',
            '.main-content',
            '.content',
            '#content',
            '#main-content',
            '.post-content',
            '.article-content',
            '.entry-content',
            '.page-content',
            '.legal-content',
            '.privacy-policy',
            '.terms-of-service',
        ],

        // Elements to remove before extraction
        'remove_selectors' => [
            'nav',
            'header',
            'footer',
            'aside',
            '.nav',
            '.navbar',
            '.navigation',
            '.header',
            '.footer',
            '.sidebar',
            '.menu',
            '.breadcrumb',
            '.breadcrumbs',
            '.social-share',
            '.comments',
            '.related-posts',
            'script',
            'style',
            'noscript',
            'iframe',
            'form',
            '.cookie-banner',
            '.cookie-notice',
            '#cookie-banner',
            '[role="navigation"]',
            '[role="banner"]',
            '[role="contentinfo"]',
        ],

        // Minimum content length to consider valid (characters)
        'min_content_length' => 500,

        // Maximum content length to store (characters)
        'max_content_length' => 5000000, // 5MB
    ],

    /*
    |--------------------------------------------------------------------------
    | Markdown Conversion Settings
    |--------------------------------------------------------------------------
    |
    | Options for converting HTML to Markdown.
    |
    */

    'markdown' => [
        'strip_tags' => true,
        'preserve_comments' => false,
        'hard_break' => true,
        'suppress_errors' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for retrying failed requests.
    |
    */

    'retry' => [
        'max_attempts' => 3,
        'delay_ms' => 1000,
        'multiplier' => 2, // Exponential backoff multiplier
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for queue job processing.
    |
    */

    'queue' => [
        'connection' => env('SCRAPER_QUEUE_CONNECTION', 'default'),
        'scrape_queue' => env('SCRAPER_QUEUE_NAME', 'scraping'),
        'discovery_queue' => env('SCRAPER_DISCOVERY_QUEUE', 'discovery'),
    ],

];
