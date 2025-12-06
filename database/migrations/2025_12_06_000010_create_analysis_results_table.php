<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analysis_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_version_id')->constrained()->cascadeOnDelete();
            $table->string('analysis_type'); // full_analysis, change_analysis, summary, etc.
            $table->decimal('overall_score', 5, 2)->nullable(); // Weighted aggregate score
            $table->string('overall_rating')->nullable(); // A, B, C, D, F or good, neutral, bad
            $table->longText('summary')->nullable(); // AI-generated summary
            $table->longText('key_concerns')->nullable(); // List of main concerns
            $table->longText('positive_aspects')->nullable(); // List of positive aspects
            $table->longText('recommendations')->nullable(); // Recommendations for users
            $table->json('extracted_data')->nullable(); // Structured data extracted (data types collected, third parties, etc.)
            $table->json('flags')->nullable(); // Warning flags
            $table->string('model_used')->nullable();
            $table->integer('tokens_used')->nullable();
            $table->decimal('analysis_cost', 10, 6)->nullable(); // Cost of the API call
            $table->boolean('is_current')->default(false);
            $table->timestamps();

            $table->index('analysis_type');
            $table->index('overall_score');
            $table->index('overall_rating');
            $table->index('is_current');
            $table->index(['document_version_id', 'analysis_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_results');
    }
};
