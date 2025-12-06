<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('type')->default('product'); // product, service, app, game, platform
            $table->string('url')->nullable(); // Product-specific URL if different from company
            $table->string('app_store_url')->nullable();
            $table->string('play_store_url')->nullable();
            $table->text('description')->nullable();
            $table->string('icon_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'slug']);
            $table->index(['company_id', 'is_active']);
        });

        // Pivot table for many-to-many relationship between documents and products
        Schema::create('document_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_primary')->default(false); // Is this the primary policy for this product?
            $table->timestamps();

            $table->unique(['document_id', 'product_id']);
            $table->index(['product_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_product');
        Schema::dropIfExists('products');
    }
};
