<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeDocumentVersionJob;
use App\Jobs\ScrapeDocumentJob;
use App\Models\AnalysisJob;
use App\Models\AnalysisResult;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\ScrapeJob;
use App\Models\Website;
use App\Services\Scraper\VersioningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DocumentController extends Controller
{
    public function show(Document $document): Response
    {
        $document->load([
            'company',
            'website',
            'documentType',
            'versions' => fn ($q) => $q->orderByDesc('scraped_at')->limit(20),
            'currentVersion.currentAnalysis',
            'currentVersion.analysisResults' => fn ($q) => $q->orderByDesc('created_at')->limit(10),
            'scrapeJobs' => fn ($q) => $q->orderByDesc('created_at')->limit(10),
            'products',
        ]);

        return Inertia::render('documents/Show', [
            'document' => [
                'id' => $document->id,
                'source_url' => $document->source_url,
                'canonical_url' => $document->canonical_url,
                'document_type' => $document->documentType?->name,
                'document_type_slug' => $document->documentType?->slug,
                'company_id' => $document->company_id,
                'company_name' => $document->company?->name,
                'website_id' => $document->website_id,
                'website_url' => $document->website?->url,
                'is_active' => $document->is_active,
                'is_monitored' => $document->is_monitored,
                'scrape_frequency' => $document->scrape_frequency,
                'scrape_status' => $document->scrape_status,
                'discovery_method' => $document->discovery_method,
                'last_scraped_at' => $document->last_scraped_at?->toISOString(),
                'last_changed_at' => $document->last_changed_at?->toISOString(),
                'created_at' => $document->created_at->toISOString(),
                'updated_at' => $document->updated_at->toISOString(),
                'metadata' => $document->metadata,
            ],
            'currentVersion' => $document->currentVersion ? [
                'id' => $document->currentVersion->id,
                'version_number' => $document->currentVersion->version_number,
                'content_raw' => $document->currentVersion->content_raw,
                'content_text' => $document->currentVersion->content_text,
                'content_markdown' => $document->currentVersion->content_markdown,
                'word_count' => $document->currentVersion->word_count,
                'character_count' => $document->currentVersion->character_count,
                'language' => $document->currentVersion->language,
                'content_hash' => $document->currentVersion->content_hash,
                'scraped_at' => $document->currentVersion->scraped_at?->toISOString(),
                'effective_date' => $document->currentVersion->effective_date?->toISOString(),
                'metadata' => $document->currentVersion->metadata,
            ] : null,
            'versions' => $document->versions->map(fn ($v) => [
                'id' => $v->id,
                'version_number' => $v->version_number,
                'word_count' => $v->word_count,
                'content_hash' => $v->content_hash,
                'scraped_at' => $v->scraped_at?->toISOString(),
                'is_current' => $v->is_current,
            ]),
            'scrapeJobs' => $document->scrapeJobs->map(fn ($j) => [
                'id' => $j->id,
                'status' => $j->status,
                'content_changed' => $j->content_changed,
                'error_message' => $j->error_message,
                'started_at' => $j->started_at?->toISOString(),
                'completed_at' => $j->completed_at?->toISOString(),
                'duration_ms' => $j->duration_ms,
                'created_at' => $j->created_at->toISOString(),
            ]),
            'products' => $document->products->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'type' => $p->type,
                'is_primary' => $p->pivot->is_primary,
            ]),
            'analysis' => $document->currentVersion?->currentAnalysis ? [
                'id' => $document->currentVersion->currentAnalysis->id,
                'analysis_type' => $document->currentVersion->currentAnalysis->analysis_type,
                'overall_score' => (float) $document->currentVersion->currentAnalysis->overall_score,
                'overall_rating' => $document->currentVersion->currentAnalysis->overall_rating,
                'summary' => $document->currentVersion->currentAnalysis->summary,
                'key_concerns' => $document->currentVersion->currentAnalysis->key_concerns,
                'positive_aspects' => $document->currentVersion->currentAnalysis->positive_aspects,
                'recommendations' => $document->currentVersion->currentAnalysis->recommendations,
                'extracted_data' => $document->currentVersion->currentAnalysis->extracted_data,
                'flags' => $document->currentVersion->currentAnalysis->flags,
                'model_used' => $document->currentVersion->currentAnalysis->model_used,
                'tokens_used' => $document->currentVersion->currentAnalysis->tokens_used,
                'analysis_cost' => (float) $document->currentVersion->currentAnalysis->analysis_cost,
                'processing_errors' => $document->currentVersion->currentAnalysis->processing_errors,
                'has_errors' => $document->currentVersion->currentAnalysis->hasErrors(),
                'created_at' => $document->currentVersion->currentAnalysis->created_at->toISOString(),
            ] : null,
            'analysisHistory' => $document->currentVersion?->analysisResults?->map(fn ($a) => [
                'id' => $a->id,
                'analysis_type' => $a->analysis_type,
                'overall_score' => (float) $a->overall_score,
                'overall_rating' => $a->overall_rating,
                'model_used' => $a->model_used,
                'tokens_used' => $a->tokens_used,
                'analysis_cost' => (float) $a->analysis_cost,
                'is_current' => $a->is_current,
                'has_errors' => $a->hasErrors(),
                'error_count' => $a->processing_errors ? count($a->processing_errors) : 0,
                'created_at' => $a->created_at->toISOString(),
            ]) ?? [],
            'pendingAnalysisJob' => $document->currentVersion ? AnalysisJob::where('document_version_id', $document->currentVersion->id)
                ->whereIn('status', ['pending', 'running'])
                ->where('created_at', '>', now()->subMinutes(10))
                ->first()?->only(['id', 'status', 'created_at', 'started_at', 'progress_log']) : null,
        ]);
    }

    public function store(Request $request, Website $website): RedirectResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
            'document_type_id' => ['required', 'exists:document_types,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'scrape_frequency' => ['nullable', 'string', 'in:hourly,daily,weekly,monthly'],
            'is_monitored' => ['boolean'],
        ]);

        // Check for duplicate URL (normalize for comparison)
        $normalizedUrl = $this->normalizeUrl($validated['url']);
        $existingDoc = Document::where('website_id', $website->id)
            ->get()
            ->first(fn ($doc) => $this->normalizeUrl($doc->source_url) === $normalizedUrl);

        if ($existingDoc) {
            return redirect()->back()->withErrors(['url' => 'A document with this URL already exists.']);
        }

        $document = $website->documents()->create([
            'company_id' => $website->company_id,
            'document_type_id' => $validated['document_type_id'],
            'source_url' => $validated['url'],
            'title' => $validated['title'],
            'scrape_frequency' => $validated['scrape_frequency'] ?? 'daily',
            'is_monitored' => $validated['is_monitored'] ?? true,
            'is_active' => true,
            'discovery_method' => 'manual',
        ]);

        return redirect()->back()->with('success', 'Document added successfully.');
    }

    /**
     * Normalize a URL for comparison to prevent duplicates.
     */
    private function normalizeUrl(string $url): string
    {
        $parsed = parse_url($url);

        if (!$parsed || !isset($parsed['host'])) {
            return strtolower($url);
        }

        $scheme = 'https';
        $host = strtolower($parsed['host']);
        $host = preg_replace('/^www\./', '', $host);
        $path = rtrim($parsed['path'] ?? '/', '/');
        if (empty($path)) {
            $path = '/';
        }

        return "{$scheme}://{$host}{$path}";
    }

    public function update(Request $request, Document $document): RedirectResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
            'document_type_id' => ['required', 'exists:document_types,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'scrape_frequency' => ['nullable', 'string', 'in:hourly,daily,weekly,monthly'],
            'is_monitored' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        $document->update([
            'document_type_id' => $validated['document_type_id'],
            'url' => $validated['url'],
            'title' => $validated['title'],
            'scrape_frequency' => $validated['scrape_frequency'] ?? 'daily',
            'is_monitored' => $validated['is_monitored'] ?? true,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return redirect()->back()->with('success', 'Document updated successfully.');
    }

    public function destroy(Document $document): RedirectResponse
    {
        $document->delete();

        return redirect()->back()->with('success', 'Document removed successfully.');
    }

    public function scrape(Document $document): RedirectResponse
    {
        // Create scrape job record
        $scrapeJob = ScrapeJob::create([
            'document_id' => $document->id,
            'status' => 'pending',
        ]);

        // Dispatch the scrape job
        ScrapeDocumentJob::dispatch($document, $scrapeJob);

        $document->update(['scrape_status' => 'running']);

        return redirect()->back()->with('success', 'Document scrape started.');
    }

    public function scrapeStatus(Document $document)
    {
        $latestJob = $document->scrapeJobs()->latest()->first();

        return response()->json([
            'status' => $latestJob?->status ?? 'none',
            'content_changed' => $latestJob?->content_changed ?? false,
            'version_id' => $latestJob?->version_id,
            'error_message' => $latestJob?->error_message,
            'completed_at' => $latestJob?->completed_at?->toISOString(),
        ]);
    }

    public function createFromDiscovery(Request $request, Website $website): RedirectResponse
    {
        $validated = $request->validate([
            'policies' => ['required', 'array'],
            'policies.*.url' => ['required', 'url'],
            'policies.*.document_type_id' => ['required', 'exists:document_types,id'],
        ]);

        $created = 0;

        // Get all existing normalized URLs for this website
        $existingNormalizedUrls = $website->documents->map(fn ($doc) => $this->normalizeUrl($doc->source_url))->toArray();

        foreach ($validated['policies'] as $policy) {
            $normalizedUrl = $this->normalizeUrl($policy['url']);

            // Skip if document already exists (using normalized URL comparison)
            if (in_array($normalizedUrl, $existingNormalizedUrls)) {
                continue;
            }

            // Add to existing list to prevent duplicates within this batch
            $existingNormalizedUrls[] = $normalizedUrl;

            $website->documents()->create([
                'company_id' => $website->company_id,
                'document_type_id' => $policy['document_type_id'],
                'source_url' => $policy['url'],
                'scrape_frequency' => 'daily',
                'is_monitored' => true,
                'is_active' => true,
                'discovery_method' => 'crawl',
            ]);

            $created++;
        }

        return redirect()->back()->with('success', "{$created} documents added from discovery.");
    }

    /**
     * Extract or re-extract metadata from the current version of a document.
     */
    public function extractMetadata(Document $document, VersioningService $versioningService): RedirectResponse
    {
        $currentVersion = $document->currentVersion;

        if (!$currentVersion) {
            return redirect()->back()->with('error', 'No version available for metadata extraction.');
        }

        $metadata = $versioningService->reExtractMetadata($currentVersion);

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

    /**
     * Trigger AI analysis for the current version of a document.
     */
    public function analyze(Document $document): RedirectResponse
    {
        $currentVersion = $document->currentVersion;

        if (!$currentVersion) {
            return redirect()->back()->with('error', 'No version available for AI analysis. Please retrieve the document first.');
        }

        // Check if there's already an analysis running (but not stale ones > 10 minutes old)
        $pendingJob = AnalysisJob::where('document_version_id', $currentVersion->id)
            ->whereIn('status', ['pending', 'running'])
            ->where('created_at', '>', now()->subMinutes(10))
            ->first();

        if ($pendingJob) {
            return redirect()->back()->with('info', 'An analysis is already in progress for this document.');
        }

        // Mark any stale pending/running jobs as failed
        AnalysisJob::where('document_version_id', $currentVersion->id)
            ->whereIn('status', ['pending', 'running'])
            ->where('created_at', '<=', now()->subMinutes(10))
            ->update([
                'status' => 'failed',
                'error_message' => 'Job timed out or was abandoned',
                'completed_at' => now(),
            ]);

        // Create analysis job record
        $analysisJob = AnalysisJob::create([
            'document_version_id' => $currentVersion->id,
            'analysis_type' => 'full_analysis',
            'status' => 'pending',
        ]);

        AnalyzeDocumentVersionJob::dispatch($currentVersion, 'full_analysis', $analysisJob->id);

        return redirect()->back()->with('success', 'AI analysis started. This may take a few minutes.');
    }

    /**
     * Get the status of the AI analysis for a document.
     */
    public function analysisStatus(Document $document)
    {
        $currentVersion = $document->currentVersion;

        if (!$currentVersion) {
            return response()->json([
                'status' => 'no_version',
                'analysis' => null,
            ]);
        }

        $analysis = $currentVersion->currentAnalysis;

        return response()->json([
            'status' => $analysis ? 'completed' : 'pending',
            'analysis' => $analysis ? [
                'id' => $analysis->id,
                'overall_score' => (float) $analysis->overall_score,
                'overall_rating' => $analysis->overall_rating,
                'summary' => $analysis->summary,
                'key_concerns' => $analysis->key_concerns,
                'positive_aspects' => $analysis->positive_aspects,
                'recommendations' => $analysis->recommendations,
                'extracted_data' => $analysis->extracted_data,
                'flags' => $analysis->flags,
                'model_used' => $analysis->model_used,
                'tokens_used' => $analysis->tokens_used,
                'analysis_cost' => (float) $analysis->analysis_cost,
                'created_at' => $analysis->created_at->toISOString(),
            ] : null,
        ]);
    }
}
