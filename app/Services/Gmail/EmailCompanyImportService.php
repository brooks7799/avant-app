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
            // Create or find company
            $company = Company::firstOrCreate(
                ['website' => $discovered->getWebsiteUrl()],
                [
                    'name' => $discovered->name,
                    'metadata' => [
                        'discovery_source' => 'email',
                        'discovered_from_email' => $discovered->email_address,
                        'detection_source' => $discovered->detection_source,
                    ],
                ]
            );

            // Create website if it doesn't exist
            $website = $this->createWebsiteFromDomain($company, $discovered->domain);

            // Mark discovered company as imported
            $discovered->markImported($company);

            Log::info('Company imported from email discovery', [
                'company_id' => $company->id,
                'discovered_id' => $discovered->id,
                'domain' => $discovered->domain,
            ]);

            // Trigger policy discovery
            $this->triggerPolicyDiscovery($website);

            return $company;
        });
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
