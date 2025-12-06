<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('websites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('url');
            $table->string('name')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('discovery_status')->default('pending'); // pending, running, completed, failed
            $table->timestamp('last_discovered_at')->nullable();
            $table->json('sitemap_urls')->nullable();
            $table->json('robots_txt')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'url']);
            $table->index('is_active');
            $table->index('is_primary');
            $table->index('discovery_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('websites');
    }
};
