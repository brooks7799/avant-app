<?php

namespace App\Http\Controllers;

use App\Jobs\ScrapeDocumentJob;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\ScrapeJob;
use App\Models\Website;
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
            'currentVersion',
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
                'content_text' => $document->currentVersion->content_text,
                'content_markdown' => $document->currentVersion->content_markdown,
                'word_count' => $document->currentVersion->word_count,
                'character_count' => $document->currentVersion->character_count,
                'language' => $document->currentVersion->language,
                'content_hash' => $document->currentVersion->content_hash,
                'scraped_at' => $document->currentVersion->scraped_at?->toISOString(),
                'effective_date' => $document->currentVersion->effective_date?->toISOString(),
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
}
