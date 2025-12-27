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
        'max_depth' => 2,

        // Maximum number of pages to crawl per website
        'max_pages' => 50,

        // Common paths to check for legal documents (core policies only)
        'common_paths' => [
            // Privacy policies
            '/privacy',
            '/privacy-policy',
            '/privacypolicy',
            '/legal/privacy',
            '/legal/privacy-policy',

            // Terms of service
            '/terms',
            '/terms-of-service',
            '/termsofservice',
            '/tos',
            '/legal/terms',
            '/legal/terms-of-service',

            // Cookie policies
            '/cookie-policy',
            '/legal/cookies',

            // Core legal pages
            '/legal',
            '/ccpa',
            '/your-privacy-choices',
        ],

        // URL patterns that indicate a policy page (must match these patterns)
        // More restrictive than before - requires specific policy-related paths
        'policy_url_patterns' => [
            '/privacy',
            '/privacy-policy',
            '/privacypolicy',
            '/terms',
            '/terms-of-service',
            '/termsofservice',
            '/tos',
            '/legal',
            '/cookie-policy',
            '/cookies-policy',
            '/ccpa',
            '/your-privacy-choices',
            '/do-not-sell',
            '/gdpr',
            '/data-protection',
            '/acceptable-use',
            '/aup',
            '/eula',
            '/dmca',
        ],

        // URL path segments that indicate this is NOT a policy page
        // These take priority - if any match, the URL is excluded
        'excluded_url_patterns' => [
            // E-commerce / Product pages
            '/cp/',              // Walmart category pages
            '/browse/',          // Browse/category pages
            '/c/',               // Category shorthand
            '/ip/',              // Item/product pages
            '/product/',
            '/products/',
            '/shop/',
            '/buy/',
            '/cart/',
            '/checkout/',
            '/order/',
            '/catalog/',
            '/category/',
            '/collection/',
            '/collections/',

            // Sitemaps and technical files
            '.xml',
            '/sitemap',
            '/feed/',
            '/rss/',

            // Account/user pages
            '/account/',
            '/my-account/',
            '/login',
            '/signin',
            '/signup',
            '/register',

            // Blog/content pages
            '/blog/',
            '/news/',
            '/article/',
            '/articles/',
            '/post/',
            '/posts/',

            // Help/support (not policies)
            '/help/',
            '/faq/',
            '/support/',
            '/contact/',

            // Promotional/marketing
            '/promo/',
            '/promotion/',
            '/offer/',
            '/deals/',
            '/sale/',
            '/coupon/',
            '/gift/',

            // Media
            '/video/',
            '/image/',
            '/images/',
            '/media/',
            '/photo/',
            '/photos/',

            // Search
            '/search',

            // Store locator
            '/store/',
            '/stores/',
            '/location/',
            '/locations/',
        ],

        // Non-English URL patterns to exclude (Spanish, Portuguese, French, German, etc.)
        'excluded_language_patterns' => [
            // Spanish
            '/es/',
            '/es-',
            '-es/',
            '_es/',
            '/espanol/',
            '/spanish/',
            'politica-de-',
            'terminos-',
            'condiciones-',
            'privacidad',
            'papel-para-',
            'para-autos',
            'para-el-',
            'para-la-',

            // Portuguese
            '/pt/',
            '/pt-',
            '-pt/',
            '_pt/',
            '/portugues/',
            '/portuguese/',
            '_br.',
            '_br/',
            '/br/',
            '-br/',
            'politica-de-privacidade',

            // French
            '/fr/',
            '/fr-',
            '-fr/',
            '_fr/',
            '/francais/',
            '/french/',
            'politique-de-',
            'conditions-',

            // German
            '/de/',
            '/de-',
            '-de/',
            '_de/',
            '/deutsch/',
            '/german/',
            'datenschutz',
            'nutzungsbedingungen',

            // Italian
            '/it/',
            '/it-',
            '-it/',
            '_it/',
            '/italiano/',
            '/italian/',

            // Japanese/Chinese/Korean
            '/ja/',
            '/jp/',
            '/zh/',
            '/cn/',
            '/ko/',
            '/kr/',

            // Other regional
            '/mx/',  // Mexico
            '/ca-fr/', // Canada French
            '/uk/',  // Sometimes indicates localized UK content
            '/au/',  // Australia (might have different policies)
        ],

        // Keywords for link text matching (more restrictive)
        'policy_link_keywords' => [
            'privacy policy',
            'privacy notice',
            'terms of service',
            'terms of use',
            'terms and conditions',
            'terms & conditions',
            'cookie policy',
            'cookie notice',
            'legal',
            'ccpa',
            'california privacy',
            'do not sell my personal information',
            'your privacy choices',
        ],

        // Document type patterns for URL matching
        'type_patterns' => [
            'privacy-policy' => ['privacy-policy', 'privacy-notice', '/privacy'],
            'terms-of-service' => ['terms-of-service', 'terms-of-use', 'termsofservice', '/terms', '/tos'],
            'cookie-policy' => ['cookie-policy', 'cookies-policy', 'cookie-notice'],
            'acceptable-use-policy' => ['acceptable-use', 'aup'],
            'ccpa-notice' => ['ccpa', 'california-privacy', 'do-not-sell', 'your-privacy-choices'],
        ],

        // Content keywords to validate scraped content matches expected document type
        // If content has < 3 of these keywords AND < 1000 words, it's likely wrong content
        'content_validation_keywords' => [
            'privacy-policy' => [
                'personal information', 'personal data', 'collect', 'data collection',
                'cookies', 'tracking', 'third party', 'third-party', 'share your',
                'opt out', 'opt-out', 'your rights', 'data subject', 'gdpr', 'ccpa',
                'retention', 'security', 'disclose', 'information we collect',
                'how we use', 'privacy', 'protect', 'safeguard',
            ],
            'terms-of-service' => [
                'agreement', 'terms', 'conditions', 'license', 'user conduct',
                'liability', 'indemnify', 'indemnification', 'warranty', 'warranties',
                'terminate', 'termination', 'prohibited', 'restrictions', 'intellectual property',
                'dispute', 'arbitration', 'governing law', 'jurisdiction', 'consent',
                'binding', 'acceptance', 'violation', 'breach',
            ],
            'cookie-policy' => [
                'cookie', 'cookies', 'tracking', 'analytics', 'session', 'persistent',
                'first-party', 'third-party', 'advertising', 'preferences', 'functional',
                'performance', 'strictly necessary', 'consent', 'browser', 'opt out',
            ],
            'acceptable-use-policy' => [
                'acceptable use', 'prohibited', 'restrictions', 'conduct', 'abuse',
                'violation', 'terminate', 'suspend', 'content', 'upload', 'post',
                'spam', 'malware', 'harassment', 'illegal',
            ],
            'ccpa-notice' => [
                'california', 'ccpa', 'consumer', 'sell', 'do not sell', 'categories',
                'personal information', 'right to know', 'right to delete', 'opt-out',
                'financial incentive', 'disclosure', 'request',
            ],
        ],

        // Minimum word count thresholds for valid policy documents
        'min_word_counts' => [
            'privacy-policy' => 500,
            'terms-of-service' => 800,
            'cookie-policy' => 200,
            'acceptable-use-policy' => 300,
            'ccpa-notice' => 200,
            'default' => 300,
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

    /*
    |--------------------------------------------------------------------------
    | Browser Rendering Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for headless browser rendering (Playwright/Lightpanda).
    | Used for JavaScript-heavy pages that can't be scraped with HTTP alone.
    |
    */

    'browser' => [
        // Enable browser rendering fallback when HTTP scraping fails
        'enabled' => env('SCRAPER_BROWSER_ENABLED', true),

        // Default browser: 'chromium' or 'lightpanda'
        'default' => env('SCRAPER_BROWSER_DEFAULT', 'chromium'),

        // Auto-select best available browser (prefers Lightpanda if running)
        'auto_select' => env('SCRAPER_BROWSER_AUTO_SELECT', true),

        // Timeout for browser rendering in milliseconds
        'timeout' => env('SCRAPER_BROWSER_TIMEOUT', 30000),

        // User agent for browser rendering (null uses Chrome default)
        'user_agent' => env('SCRAPER_BROWSER_USER_AGENT', null),

        // Lightpanda CDP endpoint
        'lightpanda_endpoint' => env('LIGHTPANDA_ENDPOINT', 'http://127.0.0.1:9222'),

        // Wait strategy: 'load', 'domcontentloaded', 'networkidle0', 'networkidle2'
        // Note: Use 'networkidle2' for compatibility with both Puppeteer and Playwright
        'wait_until' => env('SCRAPER_BROWSER_WAIT_UNTIL', 'networkidle2'),
    ],

];
