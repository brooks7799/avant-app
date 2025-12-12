<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('version_comparisons', function (Blueprint $table) {
            // AI-generated analysis fields
            $table->text('ai_change_summary')->nullable()->after('diff_summary');
            $table->text('ai_impact_analysis')->nullable()->after('ai_change_summary');
            $table->integer('impact_score_delta')->nullable()->after('ai_impact_analysis');
            $table->json('change_flags')->nullable()->after('impact_score_delta');

            // Suspicious timing detection
            $table->boolean('is_suspicious_timing')->default(false)->after('change_flags');
            $table->integer('suspicious_timing_score')->default(0)->after('is_suspicious_timing');
            $table->json('timing_context')->nullable()->after('suspicious_timing_score');

            // AI analysis metadata
            $table->string('ai_model_used')->nullable()->after('timing_context');
            $table->integer('ai_tokens_used')->nullable()->after('ai_model_used');
            $table->decimal('ai_analysis_cost', 8, 6)->nullable()->after('ai_tokens_used');
            $table->timestamp('ai_analyzed_at')->nullable()->after('ai_analysis_cost');

            // Index for suspicious timing queries
            $table->index('is_suspicious_timing');
        });
    }

    public function down(): void
    {
        Schema::table('version_comparisons', function (Blueprint $table) {
            $table->dropIndex(['is_suspicious_timing']);

            $table->dropColumn([
                'ai_change_summary',
                'ai_impact_analysis',
                'impact_score_delta',
                'change_flags',
                'is_suspicious_timing',
                'suspicious_timing_score',
                'timing_context',
                'ai_model_used',
                'ai_tokens_used',
                'ai_analysis_cost',
                'ai_analyzed_at',
            ]);
        });
    }
};
