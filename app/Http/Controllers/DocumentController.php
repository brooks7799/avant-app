<?php

namespace App\Http\Controllers;

use App\Jobs\ScrapeDocumentJob;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\ScrapeJob;
use App\Models\Website;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    public function store(Request $request, Website $website): RedirectResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
            'document_type_id' => ['required', 'exists:document_types,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'scrape_frequency' => ['nullable', 'string', 'in:hourly,daily,weekly,monthly'],
            'is_monitored' => ['boolean'],
        ]);

        $document = $website->documents()->create([
            'company_id' => $website->company_id,
            'document_type_id' => $validated['document_type_id'],
            'url' => $validated['url'],
            'title' => $validated['title'],
            'scrape_frequency' => $validated['scrape_frequency'] ?? 'daily',
            'is_monitored' => $validated['is_monitored'] ?? true,
            'is_active' => true,
            'discovery_method' => 'manual',
        ]);

        return redirect()->back()->with('success', 'Document added successfully.');
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

        foreach ($validated['policies'] as $policy) {
            // Skip if document already exists for this URL
            if (Document::where('url', $policy['url'])->exists()) {
                continue;
            }

            $website->documents()->create([
                'company_id' => $website->company_id,
                'document_type_id' => $policy['document_type_id'],
                'url' => $policy['url'],
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
