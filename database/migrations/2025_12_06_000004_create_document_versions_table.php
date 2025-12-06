<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->string('version_number'); // e.g., "1.0", "2024-01-15", auto-generated
            $table->longText('content_raw'); // Original HTML content
            $table->longText('content_text'); // Extracted plain text
            $table->longText('content_markdown')->nullable(); // Converted to markdown
            $table->string('content_hash'); // SHA256 hash for change detection
            $table->unsignedBigInteger('word_count')->default(0);
            $table->unsignedBigInteger('character_count')->default(0);
            $table->string('language')->nullable(); // Detected language
            $table->timestamp('effective_date')->nullable(); // When the policy became effective (if stated)
            $table->timestamp('scraped_at');
            $table->json('extraction_metadata')->nullable(); // Details about how content was extracted
            $table->json('metadata')->nullable();
            $table->boolean('is_current')->default(false); // Is this the latest version?
            $table->timestamps();

            $table->index('content_hash');
            $table->index('scraped_at');
            $table->index('is_current');
            $table->index(['document_id', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_versions');
    }
};
