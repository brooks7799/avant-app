<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scrape_jobs', function (Blueprint $table) {
            $table->json('progress_log')->nullable()->after('error_message');
        });

        Schema::table('discovery_jobs', function (Blueprint $table) {
            $table->json('progress_log')->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('scrape_jobs', function (Blueprint $table) {
            $table->dropColumn('progress_log');
        });

        Schema::table('discovery_jobs', function (Blueprint $table) {
            $table->dropColumn('progress_log');
        });
    }
};
