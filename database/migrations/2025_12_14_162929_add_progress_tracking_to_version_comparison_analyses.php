<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('version_comparison_analyses', function (Blueprint $table) {
            $table->unsignedInteger('total_chunks')->nullable()->after('status');
            $table->unsignedInteger('processed_chunks')->default(0)->after('total_chunks');
            $table->string('current_chunk_label')->nullable()->after('processed_chunks');
        });
    }

    public function down(): void
    {
        Schema::table('version_comparison_analyses', function (Blueprint $table) {
            $table->dropColumn(['total_chunks', 'processed_chunks', 'current_chunk_label']);
        });
    }
};
