<?php

namespace App\Http\Controllers;

use App\Jobs\DiscoverPoliciesJob;
use App\Models\Company;
use App\Models\DiscoveryJob;
use App\Models\Website;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WebsiteController extends Controller
{
    public function store(Request $request, Company $company): RedirectResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
            'name' => ['nullable', 'string', 'max:255'],
            'is_primary' => ['boolean'],
        ]);

        // Parse base URL from the provided URL
        $parsedUrl = parse_url($validated['url']);
        $baseUrl = ($parsedUrl['scheme'] ?? 'https') . '://' . ($parsedUrl['host'] ?? '');

        // If this is marked as primary, unset any existing primary
        if ($validated['is_primary'] ?? false) {
            $company->websites()->update(['is_primary' => false]);
        }

        $website = $company->websites()->create([
            'url' => $validated['url'],
            'base_url' => $baseUrl,
            'name' => $validated['name'] ?? $parsedUrl['host'] ?? null,
            'is_primary' => $validated['is_primary'] ?? false,
            'is_active' => true,
        ]);

        return redirect()->back()->with('success', 'Website added successfully.');
    }

    public function update(Request $request, Website $website): RedirectResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
            'name' => ['nullable', 'string', 'max:255'],
            'is_primary' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        // Parse base URL from the provided URL
        $parsedUrl = parse_url($validated['url']);
        $baseUrl = ($parsedUrl['scheme'] ?? 'https') . '://' . ($parsedUrl['host'] ?? '');

        // If this is marked as primary, unset any existing primary
        if ($validated['is_primary'] ?? false) {
            $website->company->websites()
                ->where('id', '!=', $website->id)
                ->update(['is_primary' => false]);
        }

        $website->update([
            'url' => $validated['url'],
            'base_url' => $baseUrl,
            'name' => $validated['name'] ?? $parsedUrl['host'] ?? null,
            'is_primary' => $validated['is_primary'] ?? false,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return redirect()->back()->with('success', 'Website updated successfully.');
    }

    public function destroy(Website $website): RedirectResponse
    {
        $website->delete();

        return redirect()->back()->with('success', 'Website removed successfully.');
    }

    public function discover(Website $website): RedirectResponse
    {
        // Create discovery job record
        $discoveryJob = DiscoveryJob::create([
            'website_id' => $website->id,
            'status' => 'pending',
        ]);

        // Dispatch the discovery job
        DiscoverPoliciesJob::dispatch($website, $discoveryJob);

        $website->update(['discovery_status' => 'running']);

        return redirect()->back()->with('success', 'Policy discovery started.');
    }

    public function discoveryStatus(Website $website)
    {
        $latestJob = $website->discoveryJobs()->latest()->first();

        return response()->json([
            'status' => $latestJob?->status ?? 'none',
            'policies_found' => $latestJob?->policies_found ?? 0,
            'urls_crawled' => $latestJob?->urls_crawled ?? 0,
            'discovered_urls' => $latestJob?->discovered_urls ?? [],
            'error_message' => $latestJob?->error_message,
            'completed_at' => $latestJob?->completed_at?->toISOString(),
        ]);
    }
}
