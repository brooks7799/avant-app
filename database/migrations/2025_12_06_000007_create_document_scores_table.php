<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scoring_criteria_id')->constrained('scoring_criteria')->cascadeOnDelete();
            $table->decimal('score', 5, 2); // 0.00 to 100.00
            $table->string('rating')->nullable(); // good, neutral, bad, ugly
            $table->text('explanation')->nullable(); // AI explanation of the score
            $table->json('evidence')->nullable(); // Relevant excerpts from the document
            $table->decimal('confidence', 5, 4)->nullable(); // AI confidence in the score
            $table->string('model_used')->nullable(); // Which AI model was used
            $table->timestamps();

            $table->unique(['document_version_id', 'scoring_criteria_id']);
            $table->index('score');
            $table->index('rating');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_scores');
    }
};
