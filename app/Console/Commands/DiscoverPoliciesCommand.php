<?php

namespace App\Console\Commands;

use App\Jobs\DiscoverPoliciesJob;
use App\Models\DiscoveryJob;
use App\Models\Website;
use Illuminate\Console\Command;

class DiscoverPoliciesCommand extends Command
{
    protected $signature = 'policies:discover
                            {website : The website ID to discover policies for}
                            {--sync : Run synchronously instead of queueing}';

    protected $description = 'Discover policy documents on a website';

    public function handle(): int
    {
        $website = Website::find($this->argument('website'));

        if (!$website) {
            $this->error('Website not found.');
            return self::FAILURE;
        }

        $this->info("Starting policy discovery for: {$website->url}");

        // Create discovery job record
        $discoveryJob = DiscoveryJob::create([
            'website_id' => $website->id,
            'status' => 'pending',
        ]);

        if ($this->option('sync')) {
            // Run synchronously
            $this->info('Running discovery synchronously...');

            $job = new DiscoverPoliciesJob($website, $discoveryJob);
            $job->handle(app(\App\Services\Scraper\PolicyDiscoveryService::class));

            $discoveryJob->refresh();

            if ($discoveryJob->status === 'completed') {
                $this->info("Discovery completed!");
                $this->info("URLs crawled: {$discoveryJob->urls_crawled}");
                $this->info("Policies found: {$discoveryJob->policies_found}");

                if ($discoveryJob->discovered_urls) {
                    $this->newLine();
                    $this->info('Discovered policies:');
                    foreach ($discoveryJob->discovered_urls as $policy) {
                        $type = $policy['detected_type'] ?? 'unknown';
                        $this->line("  - [{$type}] {$policy['url']}");
                    }
                }
            } else {
                $this->error("Discovery failed: {$discoveryJob->error_message}");
                return self::FAILURE;
            }
        } else {
            // Queue the job
            DiscoverPoliciesJob::dispatch($website, $discoveryJob);
            $this->info("Discovery job queued. Job ID: {$discoveryJob->id}");
        }

        return self::SUCCESS;
    }
}
