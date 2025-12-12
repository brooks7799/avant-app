<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analysis_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_version_id')->constrained()->onDelete('cascade');
            $table->string('analysis_type')->default('full_analysis');
            $table->string('status')->default('pending'); // pending, running, completed, failed
            $table->string('model_used')->nullable();
            $table->integer('tokens_used')->nullable();
            $table->decimal('analysis_cost', 10, 6)->nullable();
            $table->foreignId('analysis_result_id')->nullable()->constrained()->onDelete('set null');
            $table->json('progress_log')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('document_version_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_jobs');
    }
};
