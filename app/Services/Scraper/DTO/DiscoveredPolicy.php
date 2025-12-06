<?php

namespace App\Services\Scraper\DTO;

class DiscoveredPolicy
{
    public function __construct(
        public readonly string $url,
        public readonly ?string $detectedType = null,
        public readonly ?int $documentTypeId = null,
        public readonly float $confidence = 0.0,
        public readonly string $discoveryMethod = 'crawl',
        public readonly ?string $linkText = null,
    ) {}

    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'detected_type' => $this->detectedType,
            'document_type_id' => $this->documentTypeId,
            'confidence' => $this->confidence,
            'discovery_method' => $this->discoveryMethod,
            'link_text' => $this->linkText,
        ];
    }
}
