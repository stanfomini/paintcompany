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
        Schema::create('estimate_area_paint_selections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_area_id')->constrained('estimate_areas')->cascadeOnDelete();
            $table->foreignId('paint_product_id')->constrained('paint_products')->cascadeOnDelete();

            // Which surface this paint applies to
            $table->string('surface_type'); // walls, ceiling, trim, doors, windows, etc.

            // Override coats if different from product default
            $table->integer('coats')->default(2);

            // Calculated values (stored for reporting)
            $table->decimal('sqft_covered', 10, 2)->default(0);
            $table->decimal('gallons_needed', 8, 2)->default(0);
            $table->decimal('paint_cost', 10, 2)->default(0);
            $table->decimal('recycle_fee', 8, 2)->default(0);
            $table->decimal('total_material_cost', 10, 2)->default(0);

            // Color information (optional)
            $table->string('color_name')->nullable();
            $table->string('color_code')->nullable();

            $table->timestamps();

            $table->index('estimate_area_id');
            $table->index('paint_product_id');
            $table->index('surface_type');

            // Each area can only have one paint selection per surface type
            $table->unique(['estimate_area_id', 'surface_type'], 'est_area_paint_area_surface_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('estimate_area_paint_selections');
    }
};
