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
        Schema::create('nested_area_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Small Closet, Medium Closet, Large Closet, Half Bath
            $table->string('code')->unique(); // small_closet, medium_closet, etc.
            $table->text('description')->nullable();

            // Categorization
            $table->string('category')->default('closet'); // closet, bathroom, utility, other
            $table->string('scope_type')->default('interior'); // interior, exterior

            // Pricing
            $table->decimal('fixed_price', 10, 2); // The fixed price for this nested area
            $table->boolean('include_in_package_pricing')->default(true);

            // Optional: If we want to also calculate based on dimensions
            $table->decimal('default_length', 10, 2)->nullable();
            $table->decimal('default_width', 10, 2)->nullable();
            $table->decimal('default_height', 10, 2)->nullable();

            // What's included
            $table->boolean('includes_walls')->default(true);
            $table->boolean('includes_ceiling')->default(true);
            $table->boolean('includes_trim')->default(true);
            $table->boolean('includes_door')->default(false);

            // Production rate override (optional)
            $table->foreignId('production_rate_id')->nullable()->constrained()->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('category');
            $table->index('scope_type');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nested_area_templates');
    }
};
