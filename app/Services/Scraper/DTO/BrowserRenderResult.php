<?php

namespace App\Services\Scraper\DTO;

readonly class BrowserRenderResult
{
    public function __construct(
        public bool $success,
        public ?string $html = null,
        public ?string $text = null,
        public ?int $httpStatus = null,
        public ?string $finalUrl = null,
        public ?string $error = null,
        public ?string $browser = null,
        public ?array $metadata = null,
        public bool $fallback = false,
        public ?string $fallbackReason = null,
    ) {}

    public static function fromJson(array $data): self
    {
        return new self(
            success: $data['success'] ?? false,
            html: $data['html'] ?? null,
            text: $data['text'] ?? null,
            httpStatus: $data['httpStatus'] ?? null,
            finalUrl: $data['finalUrl'] ?? null,
            error: $data['error'] ?? null,
            browser: $data['browser'] ?? null,
            metadata: $data['metadata'] ?? null,
            fallback: $data['fallback'] ?? false,
            fallbackReason: $data['fallbackReason'] ?? null,
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
