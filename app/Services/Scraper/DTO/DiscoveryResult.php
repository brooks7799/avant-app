<?php

namespace App\Services\Scraper\DTO;

class DiscoveryResult
{
    public function __construct(
        public readonly bool $success,
        public readonly array $discoveredPolicies = [],
        public readonly int $urlsCrawled = 0,
        public readonly ?array $sitemapUrls = null,
        public readonly ?array $robotsTxt = null,
        public readonly ?string $error = null,
    ) {}

    public static function success(
        array $discoveredPolicies,
        int $urlsCrawled,
        ?array $sitemapUrls = null,
        ?array $robotsTxt = null,
    ): self {
        return new self(
            success: true,
            discoveredPolicies: $discoveredPolicies,
            urlsCrawled: $urlsCrawled,
            sitemapUrls: $sitemapUrls,
            robotsTxt: $robotsTxt,
        );
    }

    public static function failure(string $error): self
    {
        return new self(
            success: false,
            error: $error,
        );
    }
}
