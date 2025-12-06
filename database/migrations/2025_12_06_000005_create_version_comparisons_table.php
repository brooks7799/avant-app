<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('version_comparisons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('old_version_id')->constrained('document_versions')->cascadeOnDelete();
            $table->foreignId('new_version_id')->constrained('document_versions')->cascadeOnDelete();
            $table->longText('diff_html')->nullable(); // HTML diff for display
            $table->longText('diff_summary')->nullable(); // AI-generated summary of changes
            $table->json('changes')->nullable(); // Structured list of changes
            $table->integer('additions_count')->default(0);
            $table->integer('deletions_count')->default(0);
            $table->integer('modifications_count')->default(0);
            $table->decimal('similarity_score', 5, 4)->nullable(); // 0.0000 to 1.0000
            $table->string('change_severity')->nullable(); // minor, moderate, major, critical
            $table->boolean('is_analyzed')->default(false); // Has AI analysis been run?
            $table->timestamps();

            $table->unique(['old_version_id', 'new_version_id']);
            $table->index('change_severity');
            $table->index('is_analyzed');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('version_comparisons');
    }
};
