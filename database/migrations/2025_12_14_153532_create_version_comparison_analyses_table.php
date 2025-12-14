<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('version_comparison_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_comparison_id')->constrained()->onDelete('cascade');

            // Analysis status
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->text('error_message')->nullable();

            // AI analysis results
            $table->text('summary')->nullable();
            $table->text('impact_analysis')->nullable();
            $table->integer('impact_score_delta')->nullable();
            $table->json('change_flags')->nullable();

            // Suspicious timing
            $table->boolean('is_suspicious_timing')->default(false);
            $table->integer('suspicious_timing_score')->nullable();
            $table->json('timing_context')->nullable();

            // AI metadata
            $table->string('ai_model_used')->nullable();
            $table->integer('ai_tokens_used')->nullable();
            $table->decimal('ai_analysis_cost', 10, 6)->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['version_comparison_id', 'status']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('version_comparison_analyses');
    }
};
