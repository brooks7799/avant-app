<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->string('base_url')->after('url')->nullable();
            $table->index('base_url');
        });

        // Populate base_url for existing websites
        DB::table('websites')->lazyById()->each(function ($website) {
            $parsed = parse_url($website->url);
            $baseUrl = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
            DB::table('websites')->where('id', $website->id)->update(['base_url' => $baseUrl]);
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropIndex(['base_url']);
            $table->dropColumn('base_url');
        });
    }
};
