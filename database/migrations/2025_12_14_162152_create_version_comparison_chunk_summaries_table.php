<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('version_comparison_chunk_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_comparison_id')->constrained()->onDelete('cascade');
            $table->unsignedInteger('chunk_index'); // Index of the change in the diff
            $table->string('content_hash', 64); // SHA-256 hash of removed+added text for deduplication
            $table->string('title')->nullable(); // Short 2-4 word title
            $table->text('summary')->nullable(); // 1-2 sentence summary
            $table->enum('impact', ['positive', 'negative', 'neutral'])->nullable();
            $table->char('grade', 1)->nullable(); // A, B, C, D, F
            $table->text('reason')->nullable(); // Explanation for the grade
            $table->string('ai_model_used')->nullable();
            $table->unsignedInteger('ai_tokens_used')->nullable();
            $table->timestamps();

            // Unique constraint: one summary per chunk per comparison
            $table->unique(['version_comparison_id', 'chunk_index'], 'unique_comparison_chunk');
            // Index for fast lookup by hash (for potential cross-comparison deduplication)
            $table->index('content_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('version_comparison_chunk_summaries');
    }
};
