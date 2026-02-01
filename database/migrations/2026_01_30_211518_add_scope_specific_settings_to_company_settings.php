<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds scope-specific (interior/exterior) settings based on PBP spreadsheet configuration.
     * Interior uses $21/hr wage, exterior uses $27/hr wage.
     * Different cleanup ratios, sundries costs, and markup percentages per scope.
     */
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            // Scope-specific wage rates (base_hourly_wage becomes interior default)
            $table->decimal('interior_hourly_wage', 10, 2)->default(21.00)->after('base_hourly_wage');
            $table->decimal('exterior_hourly_wage', 10, 2)->default(27.00)->after('interior_hourly_wage');

            // Scope-specific markup percentages
            $table->decimal('interior_markup_percent', 5, 2)->default(50.00)->after('markup_percent');
            $table->decimal('exterior_markup_percent', 5, 2)->default(41.00)->after('interior_markup_percent');

            // Scope-specific cleanup hours ratios
            $table->decimal('interior_cleanup_hours_ratio', 5, 2)->default(16.00)->after('cleanup_hours_ratio');
            $table->decimal('exterior_cleanup_hours_ratio', 5, 2)->default(20.00)->after('interior_cleanup_hours_ratio');

            // Scope-specific sundries cost
            $table->decimal('interior_sundries_cost_per_hour', 10, 2)->default(3.30)->after('sundries_cost_per_prep_hour');
            $table->decimal('exterior_sundries_cost_per_hour', 10, 2)->default(1.00)->after('interior_sundries_cost_per_hour');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn([
                'interior_hourly_wage',
                'exterior_hourly_wage',
                'interior_markup_percent',
                'exterior_markup_percent',
                'interior_cleanup_hours_ratio',
                'exterior_cleanup_hours_ratio',
                'interior_sundries_cost_per_hour',
                'exterior_sundries_cost_per_hour',
            ]);
        });
    }
};
