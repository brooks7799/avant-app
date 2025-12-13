<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Services\AI\VersionDiffService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VersionComparisonController extends Controller
{
    public function __construct(
        protected VersionDiffService $diffService,
    ) {}

    /**
     * Show comparison between two versions of a document.
     */
    public function show(Document $document, DocumentVersion $oldVersion, DocumentVersion $newVersion): Response
    {
        // Ensure versions belong to this document
        if ($oldVersion->document_id !== $document->id || $newVersion->document_id !== $document->id) {
            abort(404, 'Version not found for this document');
        }

        $document->load(['documentType', 'company']);

        // Generate the diff
        $diff = $this->diffService->generateDiff(
            $oldVersion->content_markdown ?? $oldVersion->content_text,
            $newVersion->content_markdown ?? $newVersion->content_text
        );

        // Get behavioral signals for the new version
        $behavioralSignals = null;
        if ($newVersion->currentAnalysis?->behavioral_signals) {
            $behavioralSignals = $newVersion->currentAnalysis->behavioral_signals;
        }

        return Inertia::render('documents/VersionComparison', [
            'document' => [
                'id' => $document->id,
                'source_url' => $document->source_url,
                'document_type' => $document->documentType?->name ?? 'Unknown',
                'company' => [
                    'id' => $document->company?->id,
                    'name' => $document->company?->name,
                ],
            ],
            'oldVersion' => [
                'id' => $oldVersion->id,
                'version_number' => $oldVersion->version_number,
                'scraped_at' => $oldVersion->scraped_at?->toISOString(),
                'word_count' => $oldVersion->word_count,
            ],
            'newVersion' => [
                'id' => $newVersion->id,
                'version_number' => $newVersion->version_number,
                'scraped_at' => $newVersion->scraped_at?->toISOString(),
                'word_count' => $newVersion->word_count,
            ],
            'diff' => $diff,
            'behavioralSignals' => $behavioralSignals,
        ]);
    }

    /**
     * Generate and return a change summary (AI-powered).
     */
    public function summary(Document $document, DocumentVersion $oldVersion, DocumentVersion $newVersion): \Illuminate\Http\JsonResponse
    {
        // Ensure versions belong to this document
        if ($oldVersion->document_id !== $document->id || $newVersion->document_id !== $document->id) {
            abort(404, 'Version not found for this document');
        }

        $summary = $this->diffService->generateChangeSummary(
            $oldVersion,
            $newVersion
        );

        return response()->json($summary);
    }
}
