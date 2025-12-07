<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\DocumentType;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    public function index(): Response
    {
        $companies = Company::withCount(['documents', 'activeDocuments'])
            ->with(['documents' => function ($query) {
                $query->with(['currentVersion.currentAnalysis', 'documentType'])
                    ->where('is_active', true);
            }])
            ->orderBy('name')
            ->get()
            ->map(function ($company) {
                $latestAnalysis = $company->documents
                    ->pluck('currentVersion')
                    ->filter()
                    ->pluck('currentAnalysis')
                    ->filter()
                    ->sortByDesc('created_at')
                    ->first();

                return [
                    'id' => $company->id,
                    'name' => $company->name,
                    'slug' => $company->slug,
                    'website' => $company->website,
                    'industry' => $company->industry,
                    'is_active' => $company->is_active,
                    'documents_count' => $company->documents_count,
                    'active_documents_count' => $company->active_documents_count,
                    'has_analysis' => $latestAnalysis !== null,
                    'overall_score' => $latestAnalysis?->overall_score,
                    'overall_rating' => $latestAnalysis?->overall_rating,
                    'created_at' => $company->created_at,
                    'updated_at' => $company->updated_at,
                ];
            });

        return Inertia::render('companies/Index', [
            'companies' => $companies,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('companies/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'website' => 'nullable|url|max:255',
            'description' => 'nullable|string|max:1000',
            'industry' => 'nullable|string|max:100',
        ]);

        $company = Company::create($validated);

        return redirect()
            ->route('companies.show', $company)
            ->with('success', 'Company created successfully.');
    }

    public function show(Company $company): Response
    {
        $company->load([
            'websites' => function ($query) {
                $query->with([
                    'documents' => function ($q) {
                        $q->with([
                            'documentType',
                            'currentVersion.currentAnalysis',
                            'products',
                        ])->orderBy('document_type_id');
                    },
                    'discoveryJobs' => function ($q) {
                        $q->latest()->limit(1);
                    },
                ])->orderBy('is_primary', 'desc');
            },
            'products' => function ($query) {
                $query->with(['documents.documentType'])->orderBy('name');
            },
            'tags',
        ]);

        $websites = $company->websites->map(function ($website) {
            $latestDiscovery = $website->discoveryJobs->first();

            return [
                'id' => $website->id,
                'url' => $website->url,
                'base_url' => $website->base_url,
                'name' => $website->name,
                'is_primary' => $website->is_primary,
                'is_active' => $website->is_active,
                'discovery_status' => $website->discovery_status,
                'last_discovered_at' => $website->last_discovered_at?->toISOString(),
                'latest_discovery' => $latestDiscovery ? [
                    'id' => $latestDiscovery->id,
                    'status' => $latestDiscovery->status,
                    'policies_found' => $latestDiscovery->policies_found,
                    'urls_crawled' => $latestDiscovery->urls_crawled,
                    'discovered_urls' => $latestDiscovery->discovered_urls,
                    'completed_at' => $latestDiscovery->completed_at?->toISOString(),
                ] : null,
                'documents' => $website->documents->map(function ($document) {
                    $currentVersion = $document->currentVersion;
                    $analysis = $currentVersion?->currentAnalysis;

                    return [
                        'id' => $document->id,
                        'url' => $document->url,
                        'title' => $document->title,
                        'type' => $document->documentType->name,
                        'type_slug' => $document->documentType->slug,
                        'is_active' => $document->is_active,
                        'is_monitored' => $document->is_monitored,
                        'scrape_status' => $document->scrape_status,
                        'scrape_frequency' => $document->scrape_frequency,
                        'discovery_method' => $document->discovery_method,
                        'last_scraped_at' => $document->last_scraped_at?->toISOString(),
                        'last_changed_at' => $document->last_changed_at?->toISOString(),
                        'has_version' => $currentVersion !== null,
                        'version_count' => $document->versions_count ?? $document->versions()->count(),
                        'overall_score' => $analysis?->overall_score,
                        'overall_rating' => $analysis?->overall_rating,
                        'products' => $document->products->map(fn ($p) => [
                            'id' => $p->id,
                            'name' => $p->name,
                            'is_primary' => $p->pivot->is_primary,
                        ]),
                    ];
                }),
            ];
        });

        $products = $company->products->map(function ($product) {
            return [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'type' => $product->type,
                'type_label' => $product->getTypeLabel(),
                'url' => $product->url,
                'app_store_url' => $product->app_store_url,
                'play_store_url' => $product->play_store_url,
                'description' => $product->description,
                'icon_url' => $product->icon_url,
                'is_active' => $product->is_active,
                'documents' => $product->documents->map(fn ($doc) => [
                    'id' => $doc->id,
                    'type' => $doc->documentType->name,
                    'type_slug' => $doc->documentType->slug,
                    'is_primary' => $doc->pivot->is_primary,
                ]),
            ];
        });

        $documentTypes = DocumentType::orderBy('name')->get()->map(fn ($type) => [
            'id' => $type->id,
            'name' => $type->name,
            'slug' => $type->slug,
        ]);

        return Inertia::render('companies/Show', [
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'slug' => $company->slug,
                'website' => $company->website,
                'logo_url' => $company->logo_url,
                'description' => $company->description,
                'industry' => $company->industry,
                'headquarters_country' => $company->headquarters_country,
                'headquarters_state' => $company->headquarters_state,
                'is_active' => $company->is_active,
                'created_at' => $company->created_at->toISOString(),
                'updated_at' => $company->updated_at->toISOString(),
            ],
            'websites' => $websites,
            'products' => $products,
            'productTypes' => Product::TYPES,
            'documentTypes' => $documentTypes,
            'tags' => $company->tags->map(fn ($tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
                'slug' => $tag->slug,
                'color' => $tag->color,
            ]),
        ]);
    }

    public function edit(Company $company): Response
    {
        return Inertia::render('companies/Edit', [
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'slug' => $company->slug,
                'website' => $company->website,
                'description' => $company->description,
                'industry' => $company->industry,
                'headquarters_country' => $company->headquarters_country,
                'headquarters_state' => $company->headquarters_state,
                'is_active' => $company->is_active,
            ],
        ]);
    }

    public function update(Request $request, Company $company): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'website' => 'nullable|url|max:255',
            'description' => 'nullable|string|max:1000',
            'industry' => 'nullable|string|max:100',
            'headquarters_country' => 'nullable|string|max:100',
            'headquarters_state' => 'nullable|string|max:100',
            'is_active' => 'boolean',
        ]);

        $company->update($validated);

        return redirect()
            ->route('companies.show', $company)
            ->with('success', 'Company updated successfully.');
    }

    public function destroy(Company $company): RedirectResponse
    {
        $company->delete();

        return redirect()
            ->route('companies.index')
            ->with('success', 'Company deleted successfully.');
    }
}
