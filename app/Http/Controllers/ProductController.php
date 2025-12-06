<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Document;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function store(Request $request, Company $company): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:' . implode(',', array_keys(Product::TYPES))],
            'url' => ['nullable', 'url', 'max:2048'],
            'app_store_url' => ['nullable', 'url', 'max:2048'],
            'play_store_url' => ['nullable', 'url', 'max:2048'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $company->products()->create($validated);

        return redirect()->back()->with('success', 'Product added successfully.');
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:' . implode(',', array_keys(Product::TYPES))],
            'url' => ['nullable', 'url', 'max:2048'],
            'app_store_url' => ['nullable', 'url', 'max:2048'],
            'play_store_url' => ['nullable', 'url', 'max:2048'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ]);

        $product->update($validated);

        return redirect()->back()->with('success', 'Product updated successfully.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $product->delete();

        return redirect()->back()->with('success', 'Product removed successfully.');
    }

    /**
     * Attach documents to a product.
     */
    public function attachDocuments(Request $request, Product $product): RedirectResponse
    {
        $validated = $request->validate([
            'document_ids' => ['required', 'array'],
            'document_ids.*' => ['exists:documents,id'],
        ]);

        // Get current document IDs
        $currentIds = $product->documents()->pluck('documents.id')->toArray();

        // Attach new documents (only ones not already attached)
        $newIds = array_diff($validated['document_ids'], $currentIds);
        foreach ($newIds as $documentId) {
            $product->documents()->attach($documentId);
        }

        return redirect()->back()->with('success', count($newIds) . ' document(s) linked to product.');
    }

    /**
     * Detach a document from a product.
     */
    public function detachDocument(Product $product, Document $document): RedirectResponse
    {
        $product->documents()->detach($document->id);

        return redirect()->back()->with('success', 'Document unlinked from product.');
    }

    /**
     * Set a document as primary for this product.
     */
    public function setPrimaryDocument(Request $request, Product $product, Document $document): RedirectResponse
    {
        // Unset all other primary documents for this product
        $product->documents()->updateExistingPivot(
            $product->documents()->pluck('documents.id')->toArray(),
            ['is_primary' => false]
        );

        // Set this document as primary
        $product->documents()->updateExistingPivot($document->id, ['is_primary' => true]);

        return redirect()->back()->with('success', 'Primary document updated.');
    }
}
