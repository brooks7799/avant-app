<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained()->cascadeOnDelete();
            $table->string('source_url'); // The URL we scrape from
            $table->string('canonical_url')->nullable(); // If different from source
            $table->boolean('is_active')->default(true);
            $table->boolean('is_monitored')->default(true); // Whether to check for updates
            $table->string('scrape_frequency')->default('daily'); // daily, weekly, monthly
            $table->timestamp('last_scraped_at')->nullable();
            $table->timestamp('last_changed_at')->nullable();
            $table->string('scrape_status')->default('pending'); // pending, success, failed, blocked
            $table->text('scrape_notes')->nullable(); // Notes about scraping issues
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'document_type_id', 'source_url']);
            $table->index('is_active');
            $table->index('is_monitored');
            $table->index('scrape_status');
            $table->index('last_scraped_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
