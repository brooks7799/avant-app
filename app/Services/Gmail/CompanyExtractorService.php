<?php

namespace App\Services\Gmail;

class CompanyExtractorService
{
    /**
     * Extract company information from an email.
     *
     * @return array|null Company data or null if extraction failed
     */
    public function extractFromEmail(array $email, string $detectionSource): ?array
    {
        $fromHeader = $email['from'] ?? '';
        $subject = $email['subject'] ?? '';
        $body = $email['body'] ?? '';

        // Parse the From header
        $fromParts = $this->parseFromHeader($fromHeader);
        if (! $fromParts) {
            return null;
        }

        $domain = $this->extractDomain($fromParts['email']);
        if (! $domain) {
            return null;
        }

        // Skip excluded domains
        if ($this->isExcludedDomain($domain)) {
            return null;
        }

        // Extract company name
        $companyName = $this->extractCompanyName(
            $fromParts['name'],
            $fromParts['email'],
            $subject
        );

        // Detect policy URLs in the email body
        $policyUrls = $this->detectPolicyUrls($body);

        // Calculate confidence score
        $confidence = $this->calculateConfidence([
            'has_company_name' => ! empty($fromParts['name']),
            'detection_source' => $detectionSource,
            'has_policy_urls' => ! empty($policyUrls),
            'domain_type' => $this->getDomainType($domain),
        ]);

        return [
            'name' => $companyName,
            'domain' => $domain,
            'email_address' => $fromParts['email'],
            'detection_source' => $detectionSource,
            'confidence_score' => $confidence,
            'email_metadata' => [
                'subject' => $subject,
                'date' => $email['date'] ?? null,
                'snippet' => $email['snippet'] ?? null,
            ],
            'detected_policy_urls' => $policyUrls,
        ];
    }

    /**
     * Parse the From header into name and email parts.
     */
    public function parseFromHeader(string $from): ?array
    {
        // Format: "Name" <email@domain.com> or just email@domain.com
        if (preg_match('/^"?([^"<]*)"?\s*<([^>]+)>/', $from, $matches)) {
            return [
                'name' => trim($matches[1]),
                'email' => strtolower(trim($matches[2])),
            ];
        }

        // Just an email address
        if (filter_var(trim($from), FILTER_VALIDATE_EMAIL)) {
            return [
                'name' => '',
                'email' => strtolower(trim($from)),
            ];
        }

        return null;
    }

    /**
     * Extract the domain from an email address.
     */
    public function extractDomain(string $email): ?string
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return null;
        }

        $domain = strtolower($parts[1]);

        // Remove common subdomains that indicate email services
        $domain = preg_replace(
            '/^(mail|email|noreply|no-reply|newsletter|notifications?|support|info|hello|team|contact|updates?)\./i',
            '',
            $domain
        );

        return $domain ?: null;
    }

    /**
     * Extract a company name from available data.
     */
    public function extractCompanyName(?string $fromName, string $email, string $subject): string
    {
        // Priority 1: Use from name if it looks like a company name
        if ($fromName && ! $this->looksLikeSystemName($fromName)) {
            return $this->cleanCompanyName($fromName);
        }

        // Priority 2: Try to extract from subject
        $subjectName = $this->extractNameFromSubject($subject);
        if ($subjectName) {
            return $subjectName;
        }

        // Priority 3: Convert domain to company name
        return $this->domainToCompanyName($this->extractDomain($email) ?? '');
    }

    /**
     * Check if a name looks like a system/noreply name.
     */
    protected function looksLikeSystemName(string $name): bool
    {
        $systemPatterns = [
            '/^no[- ]?reply$/i',
            '/^notifications?$/i',
            '/^support$/i',
            '/^team$/i',
            '/^info$/i',
            '/^hello$/i',
            '/^contact$/i',
            '/^updates?$/i',
        ];

        foreach ($systemPatterns as $pattern) {
            if (preg_match($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clean up a company name.
     */
    protected function cleanCompanyName(string $name): string
    {
        // Remove common suffixes
        $name = preg_replace('/\s*(Inc\.?|LLC|Ltd\.?|Corp\.?)$/i', '', $name);

        // Remove email service indicators
        $name = preg_replace('/\s+(via|from|by)\s+.+$/i', '', $name);

        // Clean up whitespace
        $name = trim(preg_replace('/\s+/', ' ', $name));

        return $name ?: 'Unknown Company';
    }

    /**
     * Try to extract a company name from the subject line.
     */
    protected function extractNameFromSubject(string $subject): ?string
    {
        // Patterns like "Welcome to [Company]" or "[Company] - Welcome"
        $patterns = [
            '/^welcome to ([^!,.\-]+)/i',
            '/^thanks for (?:signing up|joining|registering) (?:with|for|to) ([^!,.\-]+)/i',
            '/^([^!,.\-]+) - welcome/i',
            '/^your ([^!,.\-]+) account/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $subject, $matches)) {
                $name = trim($matches[1]);
                if (strlen($name) >= 2 && strlen($name) <= 50) {
                    return $this->cleanCompanyName($name);
                }
            }
        }

        return null;
    }

    /**
     * Convert a domain to a company name.
     */
    protected function domainToCompanyName(string $domain): string
    {
        // Remove TLD
        $name = preg_replace('/\.(com|net|org|io|co|app|dev|ai|tech)$/i', '', $domain);

        // Convert to title case and handle special characters
        $name = str_replace(['-', '_', '.'], ' ', $name);
        $name = ucwords($name);

        return $name ?: 'Unknown Company';
    }

    /**
     * Detect policy URLs in the email body.
     */
    public function detectPolicyUrls(string $body): array
    {
        $urls = [];

        // Pattern to find URLs that look like privacy/terms pages
        $patterns = [
            '/href=["\']([^"\']*(?:privacy|policy|terms|tos|legal|gdpr|ccpa)[^"\']*)["\']/',
            '/https?:\/\/[^\s"\'<>]*(?:privacy|policy|terms|tos|legal|gdpr|ccpa)[^\s"\'<>]*/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $body, $matches)) {
                foreach ($matches[1] ?? $matches[0] as $url) {
                    $url = html_entity_decode($url);
                    $url = trim($url, '"\'');
                    if (filter_var($url, FILTER_VALIDATE_URL)) {
                        $urls[] = $url;
                    }
                }
            }
        }

        return array_unique($urls);
    }

    /**
     * Calculate confidence score based on signals.
     */
    public function calculateConfidence(array $signals): float
    {
        $score = 0.5; // Base score

        // Detection source weights
        $sourceWeights = [
            'welcome_email' => 0.30,
            'signup_confirm' => 0.25,
            'tos_update' => 0.25,
            'account' => 0.20,
            'subscription' => 0.10,
        ];

        $score += $sourceWeights[$signals['detection_source']] ?? 0.10;

        // Company name from header
        if ($signals['has_company_name'] ?? false) {
            $score += 0.10;
        }

        // Policy URLs found
        if ($signals['has_policy_urls'] ?? false) {
            $score += 0.15;
        }

        // Domain type
        if (($signals['domain_type'] ?? '') === 'business') {
            $score += 0.10;
        }

        return min(1.0, max(0.0, $score));
    }

    /**
     * Check if a domain is in the excluded list.
     */
    public function isExcludedDomain(string $domain): bool
    {
        return in_array($domain, config('gmail.excluded_domains', []));
    }

    /**
     * Get the type of domain (business, free email, etc.).
     */
    protected function getDomainType(string $domain): string
    {
        $freeProviders = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'aol.com'];

        if (in_array($domain, $freeProviders)) {
            return 'free';
        }

        return 'business';
    }
}
