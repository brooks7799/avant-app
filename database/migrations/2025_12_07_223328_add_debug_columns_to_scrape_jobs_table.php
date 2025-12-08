<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('scrape_jobs', function (Blueprint $table) {
            $table->longText('raw_html')->nullable()->after('response_headers');
            $table->longText('extracted_html')->nullable()->after('raw_html');
            $table->json('request_headers')->nullable()->after('extracted_html');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scrape_jobs', function (Blueprint $table) {
            $table->dropColumn(['raw_html', 'extracted_html', 'request_headers']);
        });
    }
};
