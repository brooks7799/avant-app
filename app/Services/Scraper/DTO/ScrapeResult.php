<?php

namespace App\Services\Scraper\DTO;

class ScrapeResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $contentRaw = null,
        public readonly ?string $contentText = null,
        public readonly ?string $contentMarkdown = null,
        public readonly ?string $contentHash = null,
        public readonly ?int $wordCount = null,
        public readonly ?int $characterCount = null,
        public readonly ?string $language = null,
        public readonly ?int $httpStatus = null,
        public readonly ?array $responseHeaders = null,
        public readonly ?string $finalUrl = null,
        public readonly ?string $error = null,
    ) {}

    public static function success(
        string $contentRaw,
        string $contentText,
        ?string $contentMarkdown,
        string $contentHash,
        int $wordCount,
        int $characterCount,
        ?string $language = null,
        ?int $httpStatus = null,
        ?array $responseHeaders = null,
        ?string $finalUrl = null,
    ): self {
        return new self(
            success: true,
            contentRaw: $contentRaw,
            contentText: $contentText,
            contentMarkdown: $contentMarkdown,
            contentHash: $contentHash,
            wordCount: $wordCount,
            characterCount: $characterCount,
            language: $language,
            httpStatus: $httpStatus,
            responseHeaders: $responseHeaders,
            finalUrl: $finalUrl,
        );
    }

    public static function failure(string $error, ?int $httpStatus = null): self
    {
        return new self(
            success: false,
            error: $error,
            httpStatus: $httpStatus,
        );
    }
}
