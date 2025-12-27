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
        Schema::create('discovered_email_companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_discovery_job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('domain');
            $table->string('email_address')->nullable();
            $table->string('detection_source'); // welcome_email, tos_update, signup_confirm, subscription
            $table->float('confidence_score'); // 0.0 - 1.0
            $table->string('status')->default('pending'); // pending, imported, dismissed
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->json('email_metadata')->nullable(); // subject, date, snippet, body_html
            $table->json('detected_policy_urls')->nullable();
            $table->string('gmail_message_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['user_id', 'status']);
            $table->index('domain');
            $table->unique(['email_discovery_job_id', 'domain']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discovered_email_companies');
    }
};
