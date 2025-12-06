<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('website_id')
                ->nullable()
                ->after('company_id')
                ->constrained()
                ->nullOnDelete();

            $table->string('discovery_method')
                ->default('manual')
                ->after('source_url'); // manual, crawl, sitemap, common_paths

            $table->index('website_id');
            $table->index('discovery_method');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['website_id']);
            $table->dropColumn(['website_id', 'discovery_method']);
        });
    }
};
