<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds cascading paint selection support:
     * - Estimate level: default paint for all areas
     * - Area level: override paint for this area
     * - Surface level: paint_product_id on estimate_area_surfaces
     */
    public function up(): void
    {
        // Add default paint product to estimates
        Schema::table('estimates', function (Blueprint $table) {
            $table->foreignId('default_paint_product_id')
                ->nullable()
                ->after('project_type')
                ->constrained('paint_products')
                ->nullOnDelete();
        });

        // Add default paint product to estimate_areas (overrides estimate default)
        Schema::table('estimate_areas', function (Blueprint $table) {
            $table->foreignId('default_paint_product_id')
                ->nullable()
                ->after('application_method')
                ->constrained('paint_products')
                ->nullOnDelete();
        });

        // Add paint product to estimate_area_surfaces (overrides area default)
        Schema::table('estimate_area_surfaces', function (Blueprint $table) {
            $table->foreignId('paint_product_id')
                ->nullable()
                ->after('paint_cost')
                ->constrained('paint_products')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('estimate_area_surfaces', function (Blueprint $table) {
            $table->dropConstrainedForeignId('paint_product_id');
        });

        Schema::table('estimate_areas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_paint_product_id');
        });

        Schema::table('estimates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_paint_product_id');
        });
    }
};
