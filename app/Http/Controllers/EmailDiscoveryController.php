<?php

namespace App\Http\Controllers;

use App\Jobs\ScanEmailsJob;
use App\Models\DiscoveredEmailCompany;
use App\Models\EmailDiscoveryJob;
use App\Services\Gmail\EmailCompanyImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class EmailDiscoveryController extends Controller
{
    public function __construct(
        protected EmailCompanyImportService $importService,
    ) {}

    /**
     * Show the email discovery page.
     */
    public function index(): Response
    {
        $user = Auth::user();
        $connection = $user->gmailConnection;

        // Get latest discovery job
        $latestJob = $user->emailDiscoveryJobs()->first();

        // Get discovered companies from latest completed job
        $discoveredCompanies = [];
        if ($latestJob && $latestJob->isCompleted()) {
            $discoveredCompanies = $latestJob->discoveredCompanies()
                ->orderByDesc('confidence_score')
                ->get()
                ->map(fn ($company) => [
                    'id' => $company->id,
                    'name' => $company->name,
                    'domain' => $company->domain,
                    'email_address' => $company->email_address,
                    'detection_source' => $company->detection_source,
                    'detection_source_label' => $company->getDetectionSourceLabel(),
                    'confidence_score' => $company->confidence_score,
                    'confidence_level' => $company->getConfidenceLevel(),
                    'status' => $company->status,
                    'email_metadata' => $company->email_metadata,
                    'detected_policy_urls' => $company->detected_policy_urls,
                    'company_id' => $company->company_id,
                ]);
        }

        // Get previous jobs for history
        $previousJobs = $user->emailDiscoveryJobs()
            ->take(5)
            ->get()
            ->map(fn ($job) => [
                'id' => $job->id,
                'status' => $job->status,
                'emails_scanned' => $job->emails_scanned,
                'companies_found' => $job->companies_found,
                'duration_ms' => $job->duration_ms,
                'created_at' => $job->created_at->toISOString(),
                'completed_at' => $job->completed_at?->toISOString(),
            ]);

        return Inertia::render('email-discovery/Index', [
            'connection' => $connection ? [
                'email' => $connection->email,
                'status' => $connection->status,
                'last_sync_at' => $connection->last_sync_at?->toISOString(),
                'is_active' => $connection->isActive(),
            ] : null,
            'latestJob' => $latestJob ? [
                'id' => $latestJob->id,
                'status' => $latestJob->status,
                'emails_scanned' => $latestJob->emails_scanned,
                'companies_found' => $latestJob->companies_found,
                'error_message' => $latestJob->error_message,
                'progress_log' => $latestJob->progress_log,
                'created_at' => $latestJob->created_at->toISOString(),
            ] : null,
            'discoveredCompanies' => $discoveredCompanies,
            'previousJobs' => $previousJobs,
        ]);
    }

    /**
     * Start a new email discovery scan.
     */
    public function scan(): RedirectResponse
    {
        $user = Auth::user();
        $connection = $user->gmailConnection;

        if (! $connection) {
            return redirect()
                ->route('email-discovery.index')
                ->with('error', 'Please connect your Gmail account first.');
        }

        // Try to refresh token if expired
        if ($connection->isExpired() || $connection->needsRefresh()) {
            $oauthService = app(\App\Services\Gmail\GmailOAuthService::class);
            if (! $oauthService->refreshToken($connection)) {
                return redirect()
                    ->route('email-discovery.index')
                    ->with('error', 'Your Gmail session has expired. Please reconnect your account.');
            }
            $connection->refresh();
        }

        if (! $connection->isActive()) {
            return redirect()
                ->route('email-discovery.index')
                ->with('error', 'Your Gmail connection is not active. Please reconnect.');
        }

        // Check if there's already a running job
        $runningJob = $user->emailDiscoveryJobs()
            ->whereIn('status', ['pending', 'running'])
            ->first();

        if ($runningJob) {
            return redirect()
                ->route('email-discovery.index')
                ->with('error', 'A scan is already in progress.');
        }

        // Create new discovery job
        $job = EmailDiscoveryJob::create([
            'user_id' => $user->id,
            'status' => 'pending',
            'search_queries' => array_keys(config('gmail.search_queries', [])),
        ]);

        // Dispatch the background job
        ScanEmailsJob::dispatch($connection, $job)->onQueue('email-discovery');

        return redirect()
            ->route('email-discovery.index')
            ->with('success', 'Email scan started. This may take a few minutes.');
    }

    /**
     * Get the status of the current discovery job.
     */
    public function status(): JsonResponse
    {
        $user = Auth::user();
        $job = $user->emailDiscoveryJobs()->first();

        if (! $job) {
            return response()->json(['job' => null]);
        }

        return response()->json([
            'job' => [
                'id' => $job->id,
                'status' => $job->status,
                'emails_scanned' => $job->emails_scanned,
                'companies_found' => $job->companies_found,
                'error_message' => $job->error_message,
                'progress_log' => $job->progress_log,
                'duration_ms' => $job->duration_ms,
                'created_at' => $job->created_at->toISOString(),
                'completed_at' => $job->completed_at?->toISOString(),
            ],
        ]);
    }

    /**
     * Import selected discovered companies.
     */
    public function import(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'company_ids' => ['required', 'array'],
            'company_ids.*' => ['integer', 'exists:discovered_email_companies,id'],
        ]);

        // Verify the companies belong to the current user
        $companies = DiscoveredEmailCompany::whereIn('id', $validated['company_ids'])
            ->where('user_id', Auth::id())
            ->where('status', 'pending')
            ->get();

        if ($companies->isEmpty()) {
            return redirect()
                ->route('email-discovery.index')
                ->with('error', 'No valid companies selected for import.');
        }

        $results = $this->importService->importMultiple($companies->pluck('id')->toArray());

        $importedCount = count($results['imported']);
        $failedCount = count($results['failed']);

        $message = "Imported {$importedCount} companies.";
        if ($failedCount > 0) {
            $message .= " {$failedCount} failed.";
        }

        return redirect()
            ->route('email-discovery.index')
            ->with('success', $message);
    }

    /**
     * Import a single discovered company.
     */
    public function importSingle(DiscoveredEmailCompany $discovered): RedirectResponse
    {
        // Verify the company belongs to the current user
        if ($discovered->user_id !== Auth::id()) {
            abort(403);
        }

        if (! $discovered->isPending()) {
            return redirect()
                ->route('email-discovery.index')
                ->with('error', 'This company has already been processed.');
        }

        try {
            $company = $this->importService->importCompany($discovered);

            return redirect()
                ->route('email-discovery.index')
                ->with('success', "{$company->name} imported successfully. Policy discovery started.");

        } catch (\Exception $e) {
            return redirect()
                ->route('email-discovery.index')
                ->with('error', "Failed to import {$discovered->name}: ".$e->getMessage());
        }
    }

    /**
     * Dismiss a discovered company.
     */
    public function dismiss(DiscoveredEmailCompany $discovered): RedirectResponse
    {
        // Verify the company belongs to the current user
        if ($discovered->user_id !== Auth::id()) {
            abort(403);
        }

        if (! $discovered->isPending()) {
            return redirect()
                ->route('email-discovery.index')
                ->with('error', 'This company has already been processed.');
        }

        $discovered->markDismissed();

        return redirect()
            ->route('email-discovery.index')
            ->with('success', "{$discovered->name} dismissed.");
    }
}
