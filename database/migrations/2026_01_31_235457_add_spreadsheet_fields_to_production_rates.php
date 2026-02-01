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
        Schema::table('production_rates', function (Blueprint $table) {
            // Scope: interior or exterior (from spreadsheet organization)
            $table->string('scope')->nullable()->after('category_id');

            // Surface category: walls, ceiling, baseboard, doors, prep, body, eaves, fascia, windows, etc.
            $table->string('surface_category')->nullable()->after('scope');

            // Original name from spreadsheet for reference/debugging
            $table->string('spreadsheet_name')->nullable()->after('surface_category');

            // Rate type: helps clarify what the production_rate value represents
            // sqft_hr = square feet per hour
            // lnft_hr = linear feet per hour
            // units_hr = units per hour
            // hours_per_unit = hours per unit (inverse rate for doors)
            // cost_per_lnft = cost per linear foot (for wood replacement)
            $table->string('rate_type')->default('sqft_hr')->after('production_rate');

            // Add indexes for efficient lookups
            $table->index(['scope', 'surface_category']);
            $table->index('spreadsheet_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('production_rates', function (Blueprint $table) {
            $table->dropIndex(['scope', 'surface_category']);
            $table->dropIndex(['spreadsheet_name']);
            $table->dropColumn(['scope', 'surface_category', 'spreadsheet_name', 'rate_type']);
        });
    }
};
