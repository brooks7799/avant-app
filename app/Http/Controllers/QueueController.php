<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeDocumentVersionJob;
use App\Jobs\DiscoverPoliciesJob;
use App\Jobs\ProcessDueDocumentsJob;
use App\Jobs\ScrapeDocumentJob;
use App\Models\AnalysisJob;
use App\Models\AnalysisResult;
use App\Models\DiscoveryJob;
use App\Models\EmailDiscoveryJob;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\ScrapeJob;
use App\Models\VersionComparison;
use App\Models\VersionComparisonAnalysis;
use App\Models\Website;
use App\Services\Scraper\VersioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Inertia\Inertia;
use Inertia\Response;

class QueueController extends Controller
{
    private const WORKER_PID_KEY = 'queue_worker_pid';
    private const SCHEDULER_PID_KEY = 'scheduler_pid';

    public function index(): Response
    {
        // Get queue statistics
        $pendingJobs = DB::table('jobs')->count();
        $failedJobs = DB::table('failed_jobs')->count();
        $workerStatus = $this->getWorkerStatus();

        // Get recent scrape jobs - ordered by: running first, then pending (oldest first), then completed/failed (newest first)
        $recentScrapeJobs = ScrapeJob::with(['document.company', 'document.documentType'])
            ->orderByRaw("CASE
                WHEN status = 'running' THEN 0
                WHEN status = 'pending' THEN 1
                ELSE 2
            END")
            ->orderByRaw("CASE
                WHEN status IN ('running', 'pending') THEN created_at
                ELSE NULL
            END ASC")
            ->orderByRaw("CASE
                WHEN status NOT IN ('running', 'pending') THEN created_at
                ELSE NULL
            END DESC")
            ->limit(50)
            ->get()
            ->map(fn ($job) => [
                'id' => $job->id,
                'document_id' => $job->document_id,
                'document_type' => $job->document?->documentType?->name,
                'company_name' => $job->document?->company?->name,
                'document_url' => $job->document?->url,
                'status' => $job->status,
                'content_changed' => $job->content_changed,
                'error_message' => $job->error_message,
                'started_at' => $job->started_at?->toISOString(),
                'completed_at' => $job->completed_at?->toISOString(),
                'created_at' => $job->created_at->toISOString(),
            ]);

        // Get recent discovery jobs - ordered by: running first, then pending (oldest first), then completed/failed (newest first)
        $recentDiscoveryJobs = DiscoveryJob::with(['website.company'])
            ->orderByRaw("CASE
                WHEN status = 'running' THEN 0
                WHEN status = 'pending' THEN 1
                ELSE 2
            END")
            ->orderByRaw("CASE
                WHEN status IN ('running', 'pending') THEN created_at
                ELSE NULL
            END ASC")
            ->orderByRaw("CASE
                WHEN status NOT IN ('running', 'pending') THEN created_at
                ELSE NULL
            END DESC")
            ->limit(50)
            ->get()
            ->map(fn ($job) => [
                'id' => $job->id,
                'website_id' => $job->website_id,
                'website_url' => $job->website?->url,
                'company_name' => $job->website?->company?->name,
                'status' => $job->status,
                'urls_crawled' => $job->urls_crawled,
                'policies_found' => $job->policies_found,
                'error_message' => $job->error_message,
                'started_at' => $job->started_at?->toISOString(),
                'completed_at' => $job->completed_at?->toISOString(),
                'created_at' => $job->created_at->toISOString(),
            ]);

        // Get recent AI analysis jobs
        $recentAnalysisJobs = AnalysisJob::with(['documentVersion.document.company', 'documentVersion.document.documentType'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($job) => [
                'id' => $job->id,
                'document_version_id' => $job->document_version_id,
                'document_id' => $job->documentVersion?->document_id,
                'document_type' => $job->documentVersion?->document?->documentType?->name,
                'company_name' => $job->documentVersion?->document?->company?->name,
                'analysis_type' => $job->analysis_type,
                'status' => $job->status,
                'model_used' => $job->model_used,
                'tokens_used' => $job->tokens_used,
                'analysis_cost' => $job->analysis_cost ? (float) $job->analysis_cost : null,
                'error_message' => $job->error_message,
                'started_at' => $job->started_at?->toISOString(),
                'completed_at' => $job->completed_at?->toISOString(),
                'duration_ms' => $job->duration_ms,
                'created_at' => $job->created_at->toISOString(),
            ]);

        // Get recent version comparison analysis jobs (diff analysis)
        $recentDiffAnalysisJobs = VersionComparisonAnalysis::with(['comparison.document.company', 'comparison.document.documentType'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($job) => [
                'id' => $job->id,
                'comparison_id' => $job->version_comparison_id,
                'document_id' => $job->comparison?->document_id,
                'document_type' => $job->comparison?->document?->documentType?->name,
                'company_name' => $job->comparison?->document?->company?->name,
                'old_version_id' => $job->comparison?->old_version_id,
                'new_version_id' => $job->comparison?->new_version_id,
                'status' => $job->status,
                'ai_model_used' => $job->ai_model_used,
                'ai_tokens_used' => $job->ai_tokens_used,
                'ai_analysis_cost' => $job->ai_analysis_cost ? (float) $job->ai_analysis_cost : null,
                'impact_score_delta' => $job->impact_score_delta,
                'is_suspicious_timing' => $job->is_suspicious_timing,
                'error_message' => $job->error_message,
                'completed_at' => $job->completed_at?->toISOString(),
                'created_at' => $job->created_at->toISOString(),
            ]);

        // Get recent email discovery jobs
        $recentEmailDiscoveryJobs = EmailDiscoveryJob::with(['user'])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($job) => [
                'id' => $job->id,
                'user_email' => $job->user?->email,
                'status' => $job->status,
                'emails_scanned' => $job->emails_scanned,
                'companies_found' => $job->companies_found,
                'error_message' => $job->error_message,
                'duration_ms' => $job->duration_ms,
                'started_at' => $job->started_at?->toISOString(),
                'completed_at' => $job->completed_at?->toISOString(),
                'created_at' => $job->created_at->toISOString(),
            ]);

        // Count pending job records (not just queue table)
        $pendingScrapeJobRecords = ScrapeJob::whereIn('status', ['pending', 'running'])->count();
        $pendingDiscoveryJobRecords = DiscoveryJob::whereIn('status', ['pending', 'running'])->count();
        $pendingAnalysisJobRecords = AnalysisJob::whereIn('status', ['pending', 'running'])->count();
        $pendingDiffAnalysisRecords = VersionComparisonAnalysis::whereIn('status', ['pending', 'processing'])->count();
        $pendingEmailDiscoveryRecords = EmailDiscoveryJob::whereIn('status', ['pending', 'running'])->count();

        // Get statistics
        $stats = [
            'pending_jobs' => $pendingJobs + $pendingScrapeJobRecords + $pendingDiscoveryJobRecords + $pendingAnalysisJobRecords + $pendingDiffAnalysisRecords + $pendingEmailDiscoveryRecords,
            'pending_queue_jobs' => $pendingJobs,
            'pending_scrape_jobs' => $pendingScrapeJobRecords,
            'pending_discovery_jobs' => $pendingDiscoveryJobRecords,
            'pending_analysis_jobs' => $pendingAnalysisJobRecords,
            'pending_diff_analysis_jobs' => $pendingDiffAnalysisRecords,
            'pending_email_discovery_jobs' => $pendingEmailDiscoveryRecords,
            'failed_jobs' => $failedJobs,
            'total_documents' => Document::count(),
            'monitored_documents' => Document::where('is_monitored', true)->where('is_active', true)->count(),
            'documents_due' => Document::where('is_monitored', true)
                ->where('is_active', true)
                ->where(function ($q) {
                    $q->whereNull('last_scraped_at')
                        ->orWhere(function ($q2) {
                            $q2->where('scrape_frequency', 'hourly')->where('last_scraped_at', '<', now()->subHour());
                        })
                        ->orWhere(function ($q2) {
                            $q2->where('scrape_frequency', 'daily')->where('last_scraped_at', '<', now()->subDay());
                        })
                        ->orWhere(function ($q2) {
                            $q2->where('scrape_frequency', 'weekly')->where('last_scraped_at', '<', now()->subWeek());
                        })
                        ->orWhere(function ($q2) {
                            $q2->where('scrape_frequency', 'monthly')->where('last_scraped_at', '<', now()->subMonth());
                        });
                })
                ->count(),
            'total_websites' => Website::count(),
            'websites_pending_discovery' => Website::where('discovery_status', 'pending')->count(),
            'scrape_jobs_today' => ScrapeJob::whereDate('created_at', today())->count(),
            'scrape_jobs_success_today' => ScrapeJob::whereDate('created_at', today())->where('status', 'completed')->count(),
            'scrape_jobs_failed_today' => ScrapeJob::whereDate('created_at', today())->where('status', 'failed')->count(),
            'changes_detected_today' => ScrapeJob::whereDate('created_at', today())->where('content_changed', true)->count(),
            // AI Analysis Stats
            'ai_analyses_total' => AnalysisResult::count(),
            'ai_analyses_today' => AnalysisResult::whereDate('created_at', today())->count(),
            'ai_pending_queue_jobs' => DB::table('jobs')->where('queue', 'ai')->count(),
            'version_comparisons_analyzed' => VersionComparison::where('is_analyzed', true)->count(),
            'suspicious_timing_count' => VersionComparison::where('is_suspicious_timing', true)->count(),
        ];

        return Inertia::render('queue/Index', [
            'stats' => $stats,
            'recentScrapeJobs' => $recentScrapeJobs,
            'recentDiscoveryJobs' => $recentDiscoveryJobs,
            'recentAnalysisJobs' => $recentAnalysisJobs,
            'recentDiffAnalysisJobs' => $recentDiffAnalysisJobs,
            'recentEmailDiscoveryJobs' => $recentEmailDiscoveryJobs,
            'workerStatus' => $workerStatus,
        ]);
    }

    /**
     * Get real-time queue status
     */
    public function status(): JsonResponse
    {
        $pendingJobs = DB::table('jobs')->count();
        $failedJobs = DB::table('failed_jobs')->count();

        // Get running jobs
        $runningScrapeJobs = ScrapeJob::where('status', 'running')->count();
        $runningDiscoveryJobs = DiscoveryJob::where('status', 'running')->count();
        $runningEmailDiscoveryJobs = EmailDiscoveryJob::where('status', 'running')->count();

        // Recent activity
        $recentCompleted = ScrapeJob::where('status', 'completed')
            ->where('completed_at', '>=', now()->subMinutes(5))
            ->count();

        return response()->json([
            'pending_jobs' => $pendingJobs,
            'failed_jobs' => $failedJobs,
            'running_scrape_jobs' => $runningScrapeJobs,
            'running_discovery_jobs' => $runningDiscoveryJobs,
            'running_email_discovery_jobs' => $runningEmailDiscoveryJobs,
            'recent_completed' => $recentCompleted,
            'worker_status' => $this->getWorkerStatus(),
            'timestamp' => now()->toISOString(),
        ]);
    }

    /**
     * Start processing all due documents
     */
    public function processAll(Request $request): RedirectResponse
    {
        $force = $request->boolean('force', false);

        ProcessDueDocumentsJob::dispatch($force);

        $message = $force
            ? 'Queued all active documents for scraping.'
            : 'Queued all due documents for scraping.';

        return redirect()->back()->with('success', $message);
    }

    /**
     * Discover policies on all pending websites
     */
    public function discoverAll(): RedirectResponse
    {
        $websites = Website::where('discovery_status', 'pending')
            ->orWhereNull('discovery_status')
            ->get();

        $count = 0;
        foreach ($websites as $website) {
            $discoveryJob = DiscoveryJob::create([
                'website_id' => $website->id,
                'status' => 'pending',
            ]);

            DiscoverPoliciesJob::dispatch($website, $discoveryJob);
            $website->update(['discovery_status' => 'running']);
            $count++;
        }

        return redirect()->back()->with('success', "Queued {$count} websites for policy discovery.");
    }

    /**
     * Retry all failed jobs
     */
    public function retryFailed(): RedirectResponse
    {
        $failedCount = DB::table('failed_jobs')->count();

        if ($failedCount > 0) {
            Artisan::call('queue:retry', ['id' => 'all']);
        }

        return redirect()->back()->with('success', "Retrying {$failedCount} failed jobs.");
    }

    /**
     * Flush all failed jobs
     */
    public function flushFailed(): RedirectResponse
    {
        $failedCount = DB::table('failed_jobs')->count();
        DB::table('failed_jobs')->truncate();

        return redirect()->back()->with('success', "Cleared {$failedCount} failed jobs.");
    }

    /**
     * Clear pending jobs
     */
    public function clearPending(): RedirectResponse
    {
        $pendingCount = DB::table('jobs')->count();
        DB::table('jobs')->truncate();

        // Delete pending scrape jobs and reset document statuses
        $pendingScrapeJobs = ScrapeJob::whereIn('status', ['pending', 'running'])->get();
        foreach ($pendingScrapeJobs as $job) {
            $job->document?->update(['scrape_status' => 'pending']);
        }
        ScrapeJob::whereIn('status', ['pending', 'running'])->delete();

        // Delete pending discovery jobs and reset website statuses
        $pendingDiscoveryJobs = DiscoveryJob::whereIn('status', ['pending', 'running'])->get();
        foreach ($pendingDiscoveryJobs as $job) {
            $job->website?->update(['discovery_status' => 'pending']);
        }
        DiscoveryJob::whereIn('status', ['pending', 'running'])->delete();

        // Delete pending email discovery jobs
        EmailDiscoveryJob::whereIn('status', ['pending', 'running'])->delete();

        return redirect()->back()->with('success', "Cleared {$pendingCount} queue jobs and removed pending job records.");
    }

    /**
     * Get failed jobs list
     */
    public function failedJobs(): JsonResponse
    {
        $failedJobs = DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->limit(100)
            ->get()
            ->map(fn ($job) => [
                'id' => $job->id,
                'uuid' => $job->uuid,
                'connection' => $job->connection,
                'queue' => $job->queue,
                'exception' => \Str::limit($job->exception, 500),
                'failed_at' => $job->failed_at,
            ]);

        return response()->json($failedJobs);
    }

    /**
     * Retry a specific failed job
     */
    public function retryJob(string $uuid): RedirectResponse
    {
        Artisan::call('queue:retry', ['id' => $uuid]);

        return redirect()->back()->with('success', 'Job queued for retry.');
    }

    /**
     * Delete a specific failed job
     */
    public function deleteFailedJob(string $uuid): RedirectResponse
    {
        DB::table('failed_jobs')->where('uuid', $uuid)->delete();

        return redirect()->back()->with('success', 'Failed job deleted.');
    }

    /**
     * Scrape a specific document now
     */
    public function scrapeDocument(Document $document): RedirectResponse
    {
        $scrapeJob = ScrapeJob::create([
            'document_id' => $document->id,
            'status' => 'pending',
        ]);

        ScrapeDocumentJob::dispatch($document, $scrapeJob);

        return redirect()->back()->with('success', 'Document queued for scraping.');
    }

    /**
     * Discover policies on a specific website
     */
    public function discoverWebsite(Website $website): RedirectResponse
    {
        $discoveryJob = DiscoveryJob::create([
            'website_id' => $website->id,
            'status' => 'pending',
        ]);

        DiscoverPoliciesJob::dispatch($website, $discoveryJob);
        $website->update(['discovery_status' => 'running']);

        return redirect()->back()->with('success', 'Website queued for policy discovery.');
    }

    /**
     * Show a discovery job detail page
     */
    public function showDiscoveryJob(DiscoveryJob $discoveryJob): Response
    {
        $discoveryJob->load(['website.company']);

        return Inertia::render('queue/DiscoveryJobShow', [
            'job' => $this->formatDiscoveryJobDetail($discoveryJob),
        ]);
    }

    /**
     * Get discovery job status for polling
     */
    public function discoveryJobStatus(DiscoveryJob $discoveryJob): JsonResponse
    {
        $discoveryJob->load(['website.company']);

        return response()->json($this->formatDiscoveryJobDetail($discoveryJob));
    }

    /**
     * Show a scrape job detail page
     */
    public function showScrapeJob(ScrapeJob $scrapeJob): Response
    {
        $scrapeJob->load(['document.company', 'document.documentType', 'createdVersion']);

        return Inertia::render('queue/ScrapeJobShow', [
            'job' => $this->formatScrapeJobDetail($scrapeJob),
        ]);
    }

    /**
     * Get scrape job status for polling
     */
    public function scrapeJobStatus(ScrapeJob $scrapeJob): JsonResponse
    {
        $scrapeJob->load(['document.company', 'document.documentType', 'createdVersion']);

        return response()->json($this->formatScrapeJobDetail($scrapeJob));
    }

    /**
     * Format a discovery job for detail view
     */
    private function formatDiscoveryJobDetail(DiscoveryJob $job): array
    {
        // Compute live duration for running jobs
        $durationMs = $job->duration_ms;
        if (in_array($job->status, ['pending', 'running']) && $job->started_at) {
            $durationMs = (int) abs(now()->diffInMilliseconds($job->started_at));
        }

        return [
            'id' => $job->id,
            'website_id' => $job->website_id,
            'website_url' => $job->website?->url,
            'company_name' => $job->website?->company?->name,
            'company_id' => $job->website?->company?->id,
            'status' => $job->status,
            'urls_crawled' => $job->urls_crawled ?? 0,
            'policies_found' => $job->policies_found ?? 0,
            'discovered_urls' => $job->discovered_urls,
            'progress_log' => $job->progress_log ?? [],
            'error_message' => $job->error_message,
            'started_at' => $job->started_at?->toISOString(),
            'completed_at' => $job->completed_at?->toISOString(),
            'duration_ms' => $durationMs,
            'created_at' => $job->created_at->toISOString(),
        ];
    }

    /**
     * Show a discovered policy detail page
     */
    public function showDiscoveredPolicy(DiscoveryJob $discoveryJob, int $index): Response
    {
        $discoveryJob->load(['website.company']);

        $discoveredUrls = $discoveryJob->discovered_urls ?? [];

        if (!isset($discoveredUrls[$index])) {
            abort(404, 'Discovered policy not found');
        }

        $policy = $discoveredUrls[$index];

        // Check if a document already exists for this URL
        $existingDocument = Document::where('source_url', $policy['url'])
            ->where('website_id', $discoveryJob->website_id)
            ->with(['versions' => fn ($q) => $q->orderByDesc('scraped_at'), 'documentType'])
            ->first();

        return Inertia::render('queue/DiscoveredPolicyShow', [
            'policy' => $policy,
            'discoveryJob' => [
                'id' => $discoveryJob->id,
                'status' => $discoveryJob->status,
                'completed_at' => $discoveryJob->completed_at?->toISOString(),
            ],
            'website' => [
                'id' => $discoveryJob->website?->id,
                'url' => $discoveryJob->website?->url,
                'name' => $discoveryJob->website?->name,
            ],
            'company' => [
                'id' => $discoveryJob->website?->company?->id,
                'name' => $discoveryJob->website?->company?->name,
            ],
            'index' => $index,
            'totalPolicies' => count($discoveredUrls),
            'document' => $existingDocument ? [
                'id' => $existingDocument->id,
                'source_url' => $existingDocument->source_url,
                'document_type' => $existingDocument->documentType?->name,
                'scrape_status' => $existingDocument->scrape_status,
                'last_scraped_at' => $existingDocument->last_scraped_at?->toISOString(),
                'versions' => $existingDocument->versions->map(fn ($v) => [
                    'id' => $v->id,
                    'version_number' => $v->version_number,
                    'scraped_at' => $v->scraped_at?->toISOString(),
                    'word_count' => $v->word_count,
                    'is_current' => $v->is_current,
                    'content_hash' => substr($v->content_hash, 0, 12),
                ]),
            ] : null,
        ]);
    }

    /**
     * Retrieve (scrape) a discovered policy - creates a document and queues a scrape job
     */
    public function retrieveDiscoveredPolicy(DiscoveryJob $discoveryJob, int $index): RedirectResponse
    {
        $discoveryJob->load(['website.company']);

        $discoveredUrls = $discoveryJob->discovered_urls ?? [];

        if (!isset($discoveredUrls[$index])) {
            return redirect()->back()->with('error', 'Discovered policy not found');
        }

        $policy = $discoveredUrls[$index];
        $website = $discoveryJob->website;

        // Check if document already exists (by company_id + source_url which is the unique constraint)
        $existingDocument = Document::where('source_url', $policy['url'])
            ->where('company_id', $website->company_id)
            ->first();

        if ($existingDocument) {
            // Document exists, just create a new scrape job if not already pending/running
            if (in_array($existingDocument->scrape_status, ['pending', 'running'])) {
                return redirect()->back()->with('info', 'Document already has a pending scrape job.');
            }

            $scrapeJob = ScrapeJob::create([
                'document_id' => $existingDocument->id,
                'status' => 'pending',
            ]);

            ScrapeDocumentJob::dispatch($existingDocument, $scrapeJob);

            return redirect()->back()->with('success', 'Scrape job queued for existing document.');
        }

        // Find document type by detected_type
        $documentType = null;
        if ($policy['document_type_id']) {
            $documentType = DocumentType::find($policy['document_type_id']);
        } elseif ($policy['detected_type']) {
            $documentType = DocumentType::where('slug', $policy['detected_type'])->first();
        }

        // Create the document
        $document = Document::create([
            'company_id' => $website->company_id,
            'website_id' => $website->id,
            'document_type_id' => $documentType?->id,
            'source_url' => $policy['url'],
            'discovery_method' => $policy['discovery_method'] ?? 'crawl',
            'is_active' => true,
            'is_monitored' => true,
            'scrape_frequency' => 'daily',
            'scrape_status' => 'pending',
            'metadata' => [
                'discovery_job_id' => $discoveryJob->id,
                'confidence' => $policy['confidence'] ?? null,
                'link_text' => $policy['link_text'] ?? null,
            ],
        ]);

        // Create and dispatch scrape job
        $scrapeJob = ScrapeJob::create([
            'document_id' => $document->id,
            'status' => 'pending',
        ]);

        ScrapeDocumentJob::dispatch($document, $scrapeJob);

        return redirect()->back()->with('success', 'Document created and scrape job queued.');
    }

    /**
     * Retrieve all discovered policies from a discovery job
     */
    public function retrieveAllDiscoveredPolicies(DiscoveryJob $discoveryJob): RedirectResponse
    {
        $discoveryJob->load(['website.company']);

        $discoveredUrls = $discoveryJob->discovered_urls ?? [];

        if (empty($discoveredUrls)) {
            return redirect()->back()->with('error', 'No discovered policies to retrieve.');
        }

        $website = $discoveryJob->website;
        $created = 0;
        $queued = 0;

        foreach ($discoveredUrls as $policy) {
            // Check if document already exists (by company_id + source_url which is the unique constraint)
            $existingDocument = Document::where('source_url', $policy['url'])
                ->where('company_id', $website->company_id)
                ->first();

            if ($existingDocument) {
                // Document exists, create a scrape job if not already pending/running
                if (!in_array($existingDocument->scrape_status, ['pending', 'running'])) {
                    $scrapeJob = ScrapeJob::create([
                        'document_id' => $existingDocument->id,
                        'status' => 'pending',
                    ]);

                    ScrapeDocumentJob::dispatch($existingDocument, $scrapeJob);
                    $queued++;
                }
                continue;
            }

            // Find document type
            $documentType = null;
            if ($policy['document_type_id'] ?? null) {
                $documentType = DocumentType::find($policy['document_type_id']);
            } elseif ($policy['detected_type'] ?? null) {
                $documentType = DocumentType::where('slug', $policy['detected_type'])->first();
            }

            // Create the document
            $document = Document::create([
                'company_id' => $website->company_id,
                'website_id' => $website->id,
                'document_type_id' => $documentType?->id,
                'source_url' => $policy['url'],
                'discovery_method' => $policy['discovery_method'] ?? 'crawl',
                'is_active' => true,
                'is_monitored' => true,
                'scrape_frequency' => 'daily',
                'scrape_status' => 'pending',
                'metadata' => [
                    'discovery_job_id' => $discoveryJob->id,
                    'confidence' => $policy['confidence'] ?? null,
                    'link_text' => $policy['link_text'] ?? null,
                ],
            ]);

            // Create and dispatch scrape job
            $scrapeJob = ScrapeJob::create([
                'document_id' => $document->id,
                'status' => 'pending',
            ]);

            ScrapeDocumentJob::dispatch($document, $scrapeJob);
            $created++;
        }

        $message = "Created {$created} new documents";
        if ($queued > 0) {
            $message .= ", queued {$queued} existing documents for re-scrape";
        }

        return redirect()->back()->with('success', $message . '.');
    }

    /**
     * Show a document version's content
     */
    public function showDocumentVersion(DocumentVersion $version): Response
    {
        $version->load(['document.company', 'document.documentType', 'document.website']);

        return Inertia::render('queue/DocumentVersionShow', [
            'version' => [
                'id' => $version->id,
                'version_number' => $version->version_number,
                'content_raw' => $version->content_raw,
                'content_text' => $version->content_text,
                'content_markdown' => $version->content_markdown,
                'content_hash' => $version->content_hash,
                'word_count' => $version->word_count,
                'character_count' => $version->character_count,
                'language' => $version->language,
                'scraped_at' => $version->scraped_at?->toISOString(),
                'is_current' => $version->is_current,
                'extraction_metadata' => $version->extraction_metadata,
                'metadata' => $version->metadata,
            ],
            'document' => [
                'id' => $version->document->id,
                'source_url' => $version->document->source_url,
                'document_type' => $version->document->documentType?->name,
                'company_id' => $version->document->company_id,
                'company_name' => $version->document->company?->name,
                'website_url' => $version->document->website?->url,
            ],
        ]);
    }

    /**
     * Retry a failed scrape job
     */
    public function retryScrapeJob(ScrapeJob $scrapeJob): RedirectResponse
    {
        if ($scrapeJob->status !== 'failed') {
            return redirect()->back()->with('error', 'Only failed jobs can be retried.');
        }

        $document = $scrapeJob->document;

        if (!$document) {
            return redirect()->back()->with('error', 'Document not found for this job.');
        }

        // Create a new scrape job
        $newJob = ScrapeJob::create([
            'document_id' => $document->id,
            'status' => 'pending',
        ]);

        ScrapeDocumentJob::dispatch($document, $newJob);

        return redirect()
            ->route('queue.scrape.show', $newJob)
            ->with('success', 'New retrieval job queued.');
    }

    /**
     * Cancel a pending or running discovery job
     */
    public function cancelDiscoveryJob(DiscoveryJob $discoveryJob): RedirectResponse
    {
        if (!in_array($discoveryJob->status, ['pending', 'running'])) {
            return redirect()->back()->with('error', 'Only pending or running jobs can be cancelled.');
        }

        $discoveryJob->update([
            'status' => 'cancelled',
            'completed_at' => now(),
            'error_message' => 'Cancelled by user',
        ]);

        // Update website status
        $discoveryJob->website?->update(['discovery_status' => 'idle']);

        return redirect()->back()->with('success', 'Discovery job cancelled.');
    }

    /**
     * Cancel a pending or running scrape job
     */
    public function cancelScrapeJob(ScrapeJob $scrapeJob): RedirectResponse
    {
        if (!in_array($scrapeJob->status, ['pending', 'running'])) {
            return redirect()->back()->with('error', 'Only pending or running jobs can be cancelled.');
        }

        $scrapeJob->update([
            'status' => 'cancelled',
            'completed_at' => now(),
            'error_message' => 'Cancelled by user',
        ]);

        // Update document status
        $scrapeJob->document?->update(['scrape_status' => 'idle']);

        return redirect()->back()->with('success', 'Scrape job cancelled.');
    }

    /**
     * Format a scrape job for detail view
     */
    private function formatScrapeJobDetail(ScrapeJob $job): array
    {
        return [
            'id' => $job->id,
            'document_id' => $job->document_id,
            'document_type' => $job->document?->documentType?->name,
            'document_url' => $job->document?->source_url,
            'company_name' => $job->document?->company?->name,
            'company_id' => $job->document?->company?->id,
            'status' => $job->status,
            'http_status' => $job->http_status,
            'content_changed' => $job->content_changed,
            'created_version_id' => $job->created_version_id,
            'progress_log' => $job->progress_log ?? [],
            'error_message' => $job->error_message,
            'started_at' => $job->started_at?->toISOString(),
            'completed_at' => $job->completed_at?->toISOString(),
            'duration_ms' => $job->duration_ms,
            'created_at' => $job->created_at->toISOString(),
            'user_agent' => $job->user_agent,
            // Debug info
            'request_headers' => $job->request_headers,
            'response_headers' => $job->response_headers,
            'raw_html_size' => $job->raw_html ? strlen($job->raw_html) : null,
            'raw_html_preview' => $job->raw_html ? \Str::limit($job->raw_html, 2000) : null,
            'extracted_html_size' => $job->extracted_html ? strlen($job->extracted_html) : null,
            'extracted_html_preview' => $job->extracted_html ? \Str::limit($job->extracted_html, 2000) : null,
            // Include version preview if available
            'version_preview' => $job->createdVersion ? [
                'id' => $job->createdVersion->id,
                'content_preview' => \Str::limit($job->createdVersion->content_text, 500),
                'word_count' => $job->createdVersion->word_count,
                'content_hash' => $job->createdVersion->content_hash,
            ] : null,
        ];
    }

    /**
     * Start the queue worker
     */
    public function startWorker(): RedirectResponse
    {
        $workerStatus = $this->getWorkerStatus();

        if ($workerStatus['running']) {
            return redirect()->back()->with('info', 'Queue worker is already running.');
        }

        $pidFile = $this->getPidFilePath();
        $logFile = storage_path('logs/queue-worker.log');
        $artisan = base_path('artisan');

        // Start multiple queue workers in the background for parallel processing
        // Using escapeshellarg for paths with spaces
        // Process all queues: default, scraping, discovery, ai, and email-discovery
        // Memory limit set to 1GB to handle large sitemap parsing
        // 8 workers to take advantage of multi-core CPUs (e.g., Intel Ultra 9)
        $workerCount = (int) env('QUEUE_WORKER_COUNT', 8);
        $pids = [];

        for ($i = 1; $i <= $workerCount; $i++) {
            $workerLogFile = storage_path("logs/queue-worker-{$i}.log");
            $command = sprintf(
                'nohup php -d memory_limit=1G %s queue:work --queue=default,scraping,discovery,ai,email-discovery --sleep=3 --tries=3 --timeout=1800 --max-time=3600 --memory=1024 > %s 2>&1 & echo $!',
                escapeshellarg($artisan),
                escapeshellarg($workerLogFile)
            );

            $result = Process::run($command);
            $workerPid = trim($result->output());

            if (is_numeric($workerPid)) {
                $pids[] = (int) $workerPid;
            }
        }

        if (!empty($pids)) {
            // Store all PIDs
            file_put_contents($pidFile, implode(',', $pids));
            Cache::put(self::WORKER_PID_KEY, $pids, now()->addDay());

            // Also start the scheduler in the background (handles stuck job cleanup)
            $this->startScheduler();

            $count = count($pids);
            return redirect()->back()->with('success', "Started {$count} queue workers and scheduler successfully.");
        }

        return redirect()->back()->with('error', 'Failed to start queue workers.');
    }

    /**
     * Start the scheduler for background tasks (stuck job cleanup, etc.)
     */
    private function startScheduler(): void
    {
        $schedulerPidFile = $this->getSchedulerPidFilePath();
        $schedulerLogFile = storage_path('logs/scheduler.log');
        $artisan = base_path('artisan');

        // Check if scheduler is already running
        $existingPid = $this->getSchedulerPid();
        if ($existingPid) {
            $checkResult = Process::run("ps -p {$existingPid} --no-headers");
            if ($checkResult->successful() && !empty(trim($checkResult->output()))) {
                return; // Already running
            }
        }

        $schedulerCommand = sprintf(
            'nohup php %s schedule:work > %s 2>&1 & echo $!',
            escapeshellarg($artisan),
            escapeshellarg($schedulerLogFile)
        );

        $result = Process::run($schedulerCommand);
        $schedulerPid = trim($result->output());

        if (is_numeric($schedulerPid)) {
            file_put_contents($schedulerPidFile, $schedulerPid);
            Cache::put(self::SCHEDULER_PID_KEY, (int) $schedulerPid, now()->addDay());
        }
    }

    /**
     * Stop the scheduler
     */
    private function stopScheduler(): void
    {
        $pid = $this->getSchedulerPid();

        if ($pid) {
            Process::run("kill {$pid} 2>/dev/null");
        }

        $this->cleanupSchedulerPidFile();
    }

    /**
     * Get the scheduler PID
     */
    private function getSchedulerPid(): ?int
    {
        $pid = Cache::get(self::SCHEDULER_PID_KEY);

        if (!$pid) {
            $pidFile = $this->getSchedulerPidFilePath();
            if (file_exists($pidFile)) {
                $pid = (int) trim(file_get_contents($pidFile));
            }
        }

        return $pid ?: null;
    }

    /**
     * Get the scheduler PID file path
     */
    private function getSchedulerPidFilePath(): string
    {
        return storage_path('scheduler.pid');
    }

    /**
     * Clean up the scheduler PID file and cache
     */
    private function cleanupSchedulerPidFile(): void
    {
        $pidFile = $this->getSchedulerPidFilePath();
        if (file_exists($pidFile)) {
            unlink($pidFile);
        }
        Cache::forget(self::SCHEDULER_PID_KEY);
    }

    /**
     * Stop all queue workers
     */
    public function stopWorker(): RedirectResponse
    {
        $workerStatus = $this->getWorkerStatus();

        if (!$workerStatus['running']) {
            // Clean up stale PID file
            $this->cleanupPidFile();

            return redirect()->back()->with('info', 'Queue workers are not running.');
        }

        $pids = $workerStatus['pids'] ?? [];
        $stoppedCount = 0;

        foreach ($pids as $pid) {
            // Send SIGTERM to gracefully stop the worker
            Process::run("kill -15 {$pid} 2>/dev/null");
            $stoppedCount++;
        }

        // Give them a moment to stop gracefully
        sleep(1);

        // Force kill any that are still running
        foreach ($pids as $pid) {
            $checkResult = Process::run("ps -p {$pid} --no-headers 2>/dev/null");
            if ($checkResult->successful() && !empty(trim($checkResult->output()))) {
                Process::run("kill -9 {$pid} 2>/dev/null");
            }
        }

        $this->cleanupPidFile();

        // Also stop the scheduler
        $this->stopScheduler();

        return redirect()->back()->with('success', "Stopped {$stoppedCount} queue workers and scheduler.");
    }

    /**
     * Restart the queue worker
     */
    public function restartWorker(): RedirectResponse
    {
        $this->stopWorker();
        sleep(1);

        return $this->startWorker();
    }

    /**
     * Get the current worker status
     */
    private function getWorkerStatus(): array
    {
        $pids = $this->getWorkerPids();

        if (empty($pids)) {
            return [
                'running' => false,
                'pids' => [],
                'worker_count' => 0,
                'uptime' => null,
            ];
        }

        // Check which processes are actually running
        $runningPids = [];
        $uptime = null;

        foreach ($pids as $pid) {
            $result = Process::run("ps -p {$pid} -o pid,etime,comm --no-headers 2>/dev/null");

            if ($result->successful() && !empty(trim($result->output()))) {
                $runningPids[] = $pid;

                // Get uptime from first running worker
                if (!$uptime) {
                    $output = trim($result->output());
                    $parts = preg_split('/\s+/', $output);
                    $uptime = $parts[1] ?? null;
                }
            }
        }

        if (empty($runningPids)) {
            $this->cleanupPidFile();

            return [
                'running' => false,
                'pids' => [],
                'worker_count' => 0,
                'uptime' => null,
            ];
        }

        return [
            'running' => true,
            'pids' => $runningPids,
            'worker_count' => count($runningPids),
            'uptime' => $uptime,
        ];
    }

    /**
     * Get the worker PIDs from file or cache
     */
    private function getWorkerPids(): array
    {
        // Try cache first
        $pids = Cache::get(self::WORKER_PID_KEY);

        if (!$pids) {
            // Try file
            $pidFile = $this->getPidFilePath();
            if (file_exists($pidFile)) {
                $content = trim(file_get_contents($pidFile));
                if (!empty($content)) {
                    $pids = array_map('intval', explode(',', $content));
                    $pids = array_filter($pids, fn($p) => $p > 0);
                    if (!empty($pids)) {
                        Cache::put(self::WORKER_PID_KEY, $pids, now()->addDay());
                    }
                }
            }
        }

        return $pids ?: [];
    }

    /**
     * Get the PID file path
     */
    private function getPidFilePath(): string
    {
        return storage_path('queue-worker.pid');
    }

    /**
     * Clean up the PID file and cache
     */
    private function cleanupPidFile(): void
    {
        $pidFile = $this->getPidFilePath();
        if (file_exists($pidFile)) {
            unlink($pidFile);
        }
        Cache::forget(self::WORKER_PID_KEY);
    }

    /**
     * Extract or re-extract metadata from a document version.
     */
    public function extractVersionMetadata(DocumentVersion $version, VersioningService $versioningService): RedirectResponse
    {
        $metadata = $versioningService->reExtractMetadata($version);

        $foundItems = [];
        if (!empty($metadata['update_date'])) {
            $foundItems[] = 'update date';
        }
        if (!empty($metadata['effective_date'])) {
            $foundItems[] = 'effective date';
        }
        if (!empty($metadata['version'])) {
            $foundItems[] = 'version';
        }

        if (empty($foundItems)) {
            return redirect()->back()->with('info', 'No metadata could be extracted from the document content.');
        }

        return redirect()->back()->with('success', 'Extracted: ' . implode(', ', $foundItems));
    }
}
