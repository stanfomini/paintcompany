<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove redundant columns from production_rates table.
 *
 * These columns are being consolidated:
 * - hourly_labor_rate → Use CompanySetting::getEffectiveHourlyRateForScope() instead
 * - material_cost_per_unit → Use PaintProduct pricing instead
 *
 * Note: estimate_area_surfaces keeps these columns as snapshots of values
 * at the time of estimate creation.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('production_rates', function (Blueprint $table) {
            $table->dropColumn(['hourly_labor_rate', 'material_cost_per_unit']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('production_rates', function (Blueprint $table) {
            $table->decimal('hourly_labor_rate', 10, 2)->default(0)->after('production_rate');
            $table->decimal('material_cost_per_unit', 10, 2)->default(0)->after('hourly_labor_rate');
        });
    }
};
