<?php

namespace App\Services\Gmail;

use App\Jobs\DiscoverPoliciesJob;
use App\Models\Company;
use App\Models\DiscoveredEmailCompany;
use App\Models\DiscoveryJob;
use App\Models\Website;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EmailCompanyImportService
{
    /**
     * Import a discovered company into the system.
     */
    public function importCompany(DiscoveredEmailCompany $discovered): Company
    {
        return DB::transaction(function () use ($discovered) {
            // Get both the discovered domain and root domain
            $discoveredDomain = $discovered->domain;
            $rootDomain = $this->extractRootDomain($discoveredDomain);

            // Use root domain for company website URL
            $companyWebsiteUrl = 'https://' . $rootDomain;

            // Create or find company based on root domain
            $company = Company::firstOrCreate(
                ['website' => $companyWebsiteUrl],
                [
                    'name' => $discovered->name,
                    'metadata' => [
                        'discovery_source' => 'email',
                        'discovered_from_email' => $discovered->email_address,
                        'detection_source' => $discovered->detection_source,
                    ],
                ]
            );

            // Always create website for the root domain (primary)
            $rootWebsite = $this->createWebsiteFromDomain($company, $rootDomain);

            // If discovered domain is a subdomain, also add it as a secondary website
            $subdomainWebsite = null;
            if ($discoveredDomain !== $rootDomain) {
                $subdomainWebsite = $this->createWebsiteFromDomain($company, $discoveredDomain);
                Log::info('Added subdomain website in addition to root domain', [
                    'subdomain' => $discoveredDomain,
                    'root_domain' => $rootDomain,
                ]);
            }

            // Mark discovered company as imported
            $discovered->markImported($company);

            Log::info('Company imported from email discovery', [
                'company_id' => $company->id,
                'discovered_id' => $discovered->id,
                'domain' => $discoveredDomain,
                'root_domain' => $rootDomain,
            ]);

            // Trigger policy discovery for root domain first (most likely to have policies)
            $this->triggerPolicyDiscovery($rootWebsite);

            // Also trigger discovery for subdomain if different
            if ($subdomainWebsite) {
                $this->triggerPolicyDiscovery($subdomainWebsite);
            }

            return $company;
        });
    }

    /**
     * Extract the root domain from a subdomain.
     * e.g., "news.crypto.com" -> "crypto.com"
     *       "mail.google.com" -> "google.com"
     *       "walmart.com" -> "walmart.com"
     */
    protected function extractRootDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $parts = explode('.', $domain);

        // Already a root domain (e.g., "example.com")
        if (count($parts) <= 2) {
            return $domain;
        }

        // Handle common second-level TLDs (e.g., co.uk, com.au)
        $secondLevelTlds = ['co', 'com', 'net', 'org', 'gov', 'edu', 'ac', 'ne'];
        $tld = $parts[count($parts) - 1];
        $secondLevel = $parts[count($parts) - 2];

        // If second-to-last part is a common second-level TLD, keep 3 parts
        if (in_array($secondLevel, $secondLevelTlds) && strlen($tld) === 2) {
            // e.g., "www.example.co.uk" -> "example.co.uk"
            return implode('.', array_slice($parts, -3));
        }

        // Otherwise, keep just the last 2 parts
        // e.g., "news.crypto.com" -> "crypto.com"
        return implode('.', array_slice($parts, -2));
    }

    /**
     * Bulk import multiple discovered companies.
     */
    public function importMultiple(array $discoveredIds): array
    {
        $results = [
            'imported' => [],
            'failed' => [],
        ];

        $discovered = DiscoveredEmailCompany::whereIn('id', $discoveredIds)
            ->where('status', 'pending')
            ->get();

        foreach ($discovered as $item) {
            try {
                $company = $this->importCompany($item);
                $results['imported'][] = [
                    'discovered_id' => $item->id,
                    'company_id' => $company->id,
                    'name' => $company->name,
                ];
            } catch (\Exception $e) {
                Log::error('Failed to import company', [
                    'discovered_id' => $item->id,
                    'error' => $e->getMessage(),
                ]);
                $results['failed'][] = [
                    'discovered_id' => $item->id,
                    'name' => $item->name,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Create a website for a company from domain.
     */
    public function createWebsiteFromDomain(Company $company, string $domain): Website
    {
        $url = 'https://'.$domain;

        return $company->websites()->firstOrCreate(
            ['url' => $url],
            [
                'base_url' => $url,
                'name' => $domain,
                'is_primary' => ! $company->websites()->exists(),
                'is_active' => true,
                'discovery_status' => 'pending',
            ]
        );
    }

    /**
     * Trigger policy discovery for a website.
     */
    public function triggerPolicyDiscovery(Website $website): DiscoveryJob
    {
        // Check if there's already a pending/running job
        $existingJob = $website->discoveryJobs()
            ->whereIn('status', ['pending', 'running'])
            ->first();

        if ($existingJob) {
            return $existingJob;
        }

        // Create new discovery job
        $job = $website->discoveryJobs()->create([
            'status' => 'pending',
        ]);

        // Dispatch the job
        DiscoverPoliciesJob::dispatch($website, $job);

        Log::info('Policy discovery triggered', [
            'website_id' => $website->id,
            'job_id' => $job->id,
        ]);

        return $job;
    }
}
