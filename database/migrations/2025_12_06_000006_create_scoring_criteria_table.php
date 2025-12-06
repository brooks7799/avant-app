<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scoring_criteria', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('category'); // data_collection, data_sharing, user_rights, transparency, etc.
            $table->text('description');
            $table->text('evaluation_prompt')->nullable(); // Prompt for AI evaluation
            $table->decimal('weight', 5, 2)->default(1.00); // Importance weight for overall score
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('category');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scoring_criteria');
    }
};
