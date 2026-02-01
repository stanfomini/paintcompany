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
        Schema::create('estimate_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_category_id')->constrained('estimate_template_categories')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('icon')->nullable();
            $table->string('image_path')->nullable();

            // Default values
            $table->decimal('default_quantity', 10, 2)->default(1);
            $table->string('default_unit', 50)->default('sqft');
            $table->integer('default_coats')->default(2);

            // Pricing configuration
            $table->foreignId('production_rate_id')->nullable()->constrained('production_rates')->nullOnDelete();
            $table->enum('pricing_method', ['production_rate', 'fixed', 'per_sqft', 'percent'])->default('production_rate');
            $table->decimal('base_price', 12, 2)->nullable();
            $table->decimal('per_sqft_rate', 10, 4)->nullable();
            $table->decimal('percent_of_total', 5, 2)->nullable();

            // Type configuration (interior/exterior/both)
            $table->enum('scope_type', ['interior', 'exterior', 'both'])->nullable();

            // JSON fields for flexibility
            $table->json('default_surfaces')->nullable();
            $table->json('options')->nullable();
            $table->json('metadata')->nullable();

            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['template_category_id', 'is_active', 'sort_order']);
            $table->index('pricing_method');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('estimate_templates');
    }
};
