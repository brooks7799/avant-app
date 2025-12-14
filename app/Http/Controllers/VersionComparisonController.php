<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeVersionDiffJob;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\VersionComparison;
use App\Models\VersionComparisonAnalysis;
use App\Services\AI\VersionDiffService;
use App\Services\Scraper\VersioningService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VersionComparisonController extends Controller
{
    public function __construct(
        protected VersionDiffService $diffService,
        protected VersioningService $versioningService,
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

        // Get or create comparison record and load existing analyses and chunk summaries
        $comparison = $this->versioningService->getOrCreateComparison($oldVersion, $newVersion);
        $comparison->load(['analyses', 'chunkSummaries']);

        // Format chunk summaries as a map (index => data) for frontend
        $chunkSummariesMap = $comparison->chunkSummaries->keyBy('chunk_index')->map(fn ($chunk) => $chunk->toFrontendArray());

        // Format analyses for frontend (sorted by newest first)
        $analyses = $comparison->analyses->sortByDesc('created_at')->values()->map(fn ($analysis) => [
            'id' => $analysis->id,
            'status' => $analysis->status,
            'summary' => $analysis->summary,
            'impact_analysis' => $analysis->impact_analysis,
            'impact_score_delta' => $analysis->impact_score_delta,
            'change_flags' => $analysis->change_flags,
            'is_suspicious_timing' => $analysis->is_suspicious_timing,
            'suspicious_timing_score' => $analysis->suspicious_timing_score,
            'timing_context' => $analysis->timing_context,
            'ai_model_used' => $analysis->ai_model_used,
            'completed_at' => $analysis->completed_at?->toISOString(),
            'created_at' => $analysis->created_at?->toISOString(),
            'error_message' => $analysis->error_message,
            // Progress fields for pending/processing analyses
            'total_chunks' => $analysis->total_chunks,
            'processed_chunks' => $analysis->processed_chunks,
            'current_chunk_label' => $analysis->current_chunk_label,
        ]);

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
            'comparisonId' => $comparison->id,
            'analyses' => $analyses,
            'chunkSummaries' => $chunkSummariesMap,
        ]);
    }

    /**
     * Generate a new analysis (AI-powered).
     * Always creates a new analysis record for comparison history.
     */
    public function summary(Document $document, DocumentVersion $oldVersion, DocumentVersion $newVersion): \Illuminate\Http\JsonResponse
    {
        // Ensure versions belong to this document
        if ($oldVersion->document_id !== $document->id || $newVersion->document_id !== $document->id) {
            abort(404, 'Version not found for this document');
        }

        // Get or create the comparison record
        $comparison = $this->versioningService->getOrCreateComparison($oldVersion, $newVersion);

        // Always create a new analysis record
        $analysis = VersionComparisonAnalysis::create([
            'version_comparison_id' => $comparison->id,
            'status' => 'pending',
        ]);

        // Dispatch the analysis job
        AnalyzeVersionDiffJob::dispatch($analysis);

        return response()->json([
            'success' => true,
            'status' => 'pending',
            'analysis_id' => $analysis->id,
            'comparison_id' => $comparison->id,
            'message' => 'Analysis job queued. Poll the status endpoint for results.',
        ]);
    }

    /**
     * Check the status of an analysis job.
     */
    public function analysisStatus(Document $document, VersionComparisonAnalysis $analysis): \Illuminate\Http\JsonResponse
    {
        // Ensure analysis belongs to this document
        $comparison = $analysis->comparison;
        if (!$comparison || $comparison->document_id !== $document->id) {
            abort(404, 'Analysis not found for this document');
        }

        if ($analysis->isCompleted()) {
            return response()->json([
                'success' => true,
                'status' => 'completed',
                'analysis_id' => $analysis->id,
                'data' => [
                    'id' => $analysis->id,
                    'summary' => $analysis->summary,
                    'impact_analysis' => $analysis->impact_analysis,
                    'change_flags' => $analysis->change_flags,
                    'impact_score_delta' => $analysis->impact_score_delta,
                    'is_suspicious_timing' => $analysis->is_suspicious_timing,
                    'suspicious_timing_score' => $analysis->suspicious_timing_score,
                    'timing_context' => $analysis->timing_context,
                    'ai_model_used' => $analysis->ai_model_used,
                    'completed_at' => $analysis->completed_at?->toISOString(),
                    'created_at' => $analysis->created_at?->toISOString(),
                ],
            ]);
        }

        if ($analysis->isFailed()) {
            return response()->json([
                'success' => false,
                'status' => 'failed',
                'analysis_id' => $analysis->id,
                'error' => $analysis->error_message ?? 'Analysis failed. Please try again.',
            ]);
        }

        return response()->json([
            'success' => true,
            'status' => $analysis->status,
            'analysis_id' => $analysis->id,
            'message' => $analysis->isProcessing() ? 'Analysis in progress...' : 'Analysis queued...',
            'progress' => [
                'total_chunks' => $analysis->total_chunks,
                'processed_chunks' => $analysis->processed_chunks,
                'current_label' => $analysis->current_chunk_label,
            ],
        ]);
    }

    /**
     * Generate a summary for a specific chunk of changes (for tooltips).
     */
    public function chunkSummary(Document $document, Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'removed_text' => 'nullable|string',
            'added_text' => 'nullable|string',
        ]);

        $removedText = $request->input('removed_text', '');
        $addedText = $request->input('added_text', '');

        if (empty($removedText) && empty($addedText)) {
            return response()->json([
                'success' => false,
                'error' => 'No text provided',
            ]);
        }

        $summary = $this->diffService->generateChunkSummary($removedText, $addedText);

        return response()->json($summary);
    }
}
