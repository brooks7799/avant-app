<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Google OAuth 2.0 Credentials
    |--------------------------------------------------------------------------
    |
    | These credentials are obtained from the Google Cloud Console.
    | Create a project, enable the Gmail API, and create OAuth 2.0 credentials.
    |
    */
    'client_id' => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'redirect_uri' => env('GOOGLE_REDIRECT_URI', 'http://127.0.0.1:8000/gmail/callback'),

    /*
    |--------------------------------------------------------------------------
    | OAuth Scopes
    |--------------------------------------------------------------------------
    |
    | We only request read-only access to Gmail for discovering companies.
    | We never request send or modify permissions.
    |
    */
    'scopes' => [
        'https://www.googleapis.com/auth/gmail.readonly',
        'https://www.googleapis.com/auth/userinfo.email',
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limits
    |--------------------------------------------------------------------------
    |
    | Gmail API has quotas. These settings help prevent exceeding them.
    | Default: 250 quota units per user per second.
    |
    */
    'rate_limits' => [
        'requests_per_second' => 10,
        'daily_quota' => 1000000000, // 1 billion quota units
    ],

    /*
    |--------------------------------------------------------------------------
    | Discovery Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for email scanning and company discovery.
    |
    */
    'discovery' => [
        // Maximum number of emails to scan per discovery job
        'max_emails_per_scan' => 500,

        // How far back to search for emails (in days)
        'lookback_days' => 365,

        // Stop after finding this many unique companies
        'max_companies' => 50,

        // Minimum confidence score to include a discovered company
        'min_confidence' => 0.3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Token Settings
    |--------------------------------------------------------------------------
    |
    | Settings for OAuth token management.
    |
    */
    'tokens' => [
        // Refresh token when it expires within this many minutes
        'refresh_threshold_minutes' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Search Queries
    |--------------------------------------------------------------------------
    |
    | Gmail search queries used to find company-related emails.
    | These target welcome emails, ToS updates, and signup confirmations.
    |
    */
    'search_queries' => [
        'welcome' => 'subject:(welcome OR "thanks for signing up" OR "account created" OR "verify your email")',
        'tos_update' => 'subject:("terms of service" OR "privacy policy" OR "policy update" OR "terms and conditions")',
        'account' => 'subject:("your account" OR "registration complete" OR "account confirmation")',
        'subscription' => 'subject:("subscription confirmed" OR "you\'re subscribed")',
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Domains
    |--------------------------------------------------------------------------
    |
    | Email domains to skip when extracting companies (free email providers,
    | email service providers, etc.)
    |
    */
    'excluded_domains' => [
        // Free email providers
        'gmail.com',
        'googlemail.com',
        'yahoo.com',
        'yahoo.co.uk',
        'outlook.com',
        'hotmail.com',
        'live.com',
        'aol.com',
        'icloud.com',
        'me.com',
        'protonmail.com',
        'proton.me',
        'zoho.com',

        // Email service providers
        'mailchimp.com',
        'sendgrid.net',
        'sendgrid.com',
        'mailgun.org',
        'mailgun.com',
        'amazonses.com',
        'mandrillapp.com',
        'postmarkapp.com',
        'sparkpostmail.com',
        'sendinblue.com',
        'brevo.com',
        'constantcontact.com',
    ],
];
