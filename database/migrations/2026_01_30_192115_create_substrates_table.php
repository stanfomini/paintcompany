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
        Schema::create('substrates', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // e.g., "Previously Painted Drywall - Good Condition"
            $table->string('code')->unique(); // e.g., "painted_drywall_good"
            $table->text('description')->nullable();
            $table->string('scope_type')->default('both'); // interior, exterior, both
            $table->string('category')->default('standard'); // standard, wood, masonry, metal

            // Prep requirements
            $table->boolean('requires_prime')->default(false);
            $table->boolean('requires_sand')->default(false);
            $table->boolean('requires_scrape')->default(false);
            $table->boolean('requires_wash')->default(false);

            // Multipliers
            $table->decimal('prep_time_multiplier', 4, 2)->default(1.00); // Affects prep labor hours
            $table->decimal('paint_time_multiplier', 4, 2)->default(1.00); // Affects paint labor hours
            $table->decimal('material_multiplier', 4, 2)->default(1.00); // Affects material usage

            // Defaults for common surfaces
            $table->integer('default_production_rate')->nullable(); // sqft/hr or lnft/hr

            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('scope_type');
            $table->index('category');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('substrates');
    }
};
