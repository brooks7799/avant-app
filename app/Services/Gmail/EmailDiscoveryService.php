<?php

namespace App\Services\Gmail;

use App\Models\DiscoveredEmailCompany;
use App\Models\EmailDiscoveryJob;
use App\Models\UserGmailConnection;
use Illuminate\Support\Facades\Log;

class EmailDiscoveryService
{
    public function __construct(
        protected GmailOAuthService $oauthService,
        protected CompanyExtractorService $extractor,
    ) {}

    /**
     * Run the email discovery process.
     */
    public function discover(UserGmailConnection $connection, EmailDiscoveryJob $job): void
    {
        $job->markRunning();
        $job->addProgress('Starting email discovery...');

        try {
            // Get configured Gmail client
            $client = $this->oauthService->getClientForConnection($connection);

            // Track discovered companies by domain to avoid duplicates
            $discoveredDomains = [];
            $totalEmails = 0;
            $maxCompanies = config('gmail.discovery.max_companies', 50);
            $maxEmails = config('gmail.discovery.max_emails_per_scan', 500);

            // Run each search query
            $queries = config('gmail.search_queries', []);
            $lookbackDays = config('gmail.discovery.lookback_days', 365);
            $dateFilter = 'after:'.now()->subDays($lookbackDays)->format('Y/m/d');
            $excludeFilter = '-from:me -in:spam -in:trash';

            foreach ($queries as $source => $query) {
                if (count($discoveredDomains) >= $maxCompanies) {
                    $job->addProgress("Reached max companies limit ({$maxCompanies})");
                    break;
                }

                $fullQuery = "{$query} {$dateFilter} {$excludeFilter}";
                $job->addProgress("Searching: {$source}");

                try {
                    $messages = $client->searchMessages($fullQuery, min(100, $maxEmails - $totalEmails));
                    $job->addProgress("Found ".count($messages)." emails for {$source}");

                    foreach ($messages as $messageRef) {
                        if ($totalEmails >= $maxEmails) {
                            break 2;
                        }

                        if (count($discoveredDomains) >= $maxCompanies) {
                            break 2;
                        }

                        try {
                            $email = $client->getMessage($messageRef['id']);
                            $totalEmails++;

                            // Extract company from email
                            $companyData = $this->extractor->extractFromEmail($email, $source);

                            if ($companyData && ! isset($discoveredDomains[$companyData['domain']])) {
                                // Check minimum confidence
                                $minConfidence = config('gmail.discovery.min_confidence', 0.3);
                                if ($companyData['confidence_score'] >= $minConfidence) {
                                    // Save discovered company
                                    $discovered = DiscoveredEmailCompany::create([
                                        'email_discovery_job_id' => $job->id,
                                        'user_id' => $connection->user_id,
                                        'name' => $companyData['name'],
                                        'domain' => $companyData['domain'],
                                        'email_address' => $companyData['email_address'],
                                        'detection_source' => $companyData['detection_source'],
                                        'confidence_score' => $companyData['confidence_score'],
                                        'email_metadata' => $companyData['email_metadata'],
                                        'detected_policy_urls' => $companyData['detected_policy_urls'],
                                        'gmail_message_id' => $companyData['gmail_message_id'],
                                    ]);

                                    $discoveredDomains[$companyData['domain']] = true;
                                    $job->addProgress("Discovered: {$companyData['name']} ({$companyData['domain']})");
                                }
                            }

                            // Update progress periodically
                            if ($totalEmails % 10 === 0) {
                                $job->updateProgress($totalEmails, count($discoveredDomains));
                            }

                        } catch (\Exception $e) {
                            Log::warning('Error processing email', [
                                'messageId' => $messageRef['id'],
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }

                } catch (\Exception $e) {
                    $job->addProgress("Error searching {$source}: ".$e->getMessage());
                    Log::error('Email search error', [
                        'source' => $source,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Mark job as completed
            $job->addProgress("Discovery complete. Found ".count($discoveredDomains)." companies from {$totalEmails} emails.");
            $job->markCompleted($totalEmails, count($discoveredDomains));

            // Update connection sync time
            $connection->recordSync();

        } catch (\Exception $e) {
            Log::error('Email discovery failed', [
                'job_id' => $job->id,
                'user_id' => $connection->user_id,
                'error' => $e->getMessage(),
            ]);

            $job->addProgress('Error: '.$e->getMessage());
            $job->markFailed($e->getMessage());
        }
    }
}
