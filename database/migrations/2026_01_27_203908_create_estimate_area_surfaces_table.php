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
        Schema::create('estimate_area_surfaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('area_id')->constrained('estimate_areas')->cascadeOnDelete();
            $table->foreignId('production_rate_id')->nullable()->constrained('production_rates')->nullOnDelete();
            $table->string('name');
            $table->string('surface_type');
            $table->decimal('quantity', 10, 2)->default(0);
            $table->string('unit');
            $table->integer('coats')->default(2);
            $table->decimal('production_rate_value', 10, 2)->default(0);
            $table->decimal('hourly_labor_rate', 10, 2)->default(0);
            $table->decimal('material_cost_per_unit', 10, 2)->default(0);
            $table->decimal('labor_hours', 10, 2)->default(0);
            $table->decimal('labor_cost', 10, 2)->default(0);
            $table->decimal('material_cost', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);
            $table->boolean('is_included')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('area_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('estimate_area_surfaces');
    }
};
