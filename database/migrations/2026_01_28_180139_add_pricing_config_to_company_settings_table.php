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
        Schema::table('company_settings', function (Blueprint $table) {
            // Labor Configuration
            $table->decimal('base_hourly_wage', 10, 2)->default(25.00)->after('logo_path');
            $table->decimal('payroll_burden_multiplier', 5, 2)->default(1.35)->after('base_hourly_wage');

            // Margins & Markup
            $table->decimal('net_profit_margin_percent', 5, 2)->default(30.00)->after('payroll_burden_multiplier');
            $table->decimal('overhead_percent', 5, 2)->default(12.00)->after('net_profit_margin_percent');
            $table->decimal('marketing_percent', 5, 2)->nullable()->after('overhead_percent');
            $table->decimal('sales_percent', 5, 2)->nullable()->after('marketing_percent');
            $table->decimal('production_management_percent', 5, 2)->nullable()->after('sales_percent');
            $table->decimal('markup_percent', 5, 2)->default(45.00)->after('production_management_percent');

            // Defaults
            $table->decimal('default_deposit_percent', 5, 2)->default(30.00)->after('markup_percent');
            $table->decimal('default_sales_discount_percent', 5, 2)->default(10.00)->after('default_deposit_percent');
            $table->decimal('material_tax_rate', 5, 2)->default(7.50)->after('default_sales_discount_percent');
            $table->decimal('default_interior_sqft_rate', 10, 4)->default(4.50)->after('material_tax_rate');
            $table->decimal('default_exterior_sqft_rate', 10, 4)->default(3.50)->after('default_interior_sqft_rate');

            // Paint Catalog (JSON)
            $table->json('paint_catalog')->nullable()->after('default_exterior_sqft_rate');

            // Trim Widths (JSON)
            $table->json('trim_widths')->nullable()->after('paint_catalog');

            // Production Settings
            $table->decimal('cleanup_hours_ratio', 5, 2)->default(16.00)->after('trim_widths');
            $table->decimal('sundries_cost_per_prep_hour', 10, 2)->default(3.30)->after('cleanup_hours_ratio');
            $table->decimal('spray_paint_coverage_multiplier', 5, 2)->default(2.0)->after('sundries_cost_per_prep_hour');
            $table->decimal('brush_roll_coverage_multiplier', 5, 2)->default(1.3)->after('spray_paint_coverage_multiplier');

            // Warranty Options (JSON)
            $table->json('warranty_options')->nullable()->after('brush_roll_coverage_multiplier');

            // Recycle/Misc Fees
            $table->decimal('recycle_fee_per_gallon', 10, 2)->default(0.54)->after('warranty_options');
            $table->decimal('wood_replacement_minimum', 12, 2)->default(300.00)->after('recycle_fee_per_gallon');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn([
                'base_hourly_wage',
                'payroll_burden_multiplier',
                'net_profit_margin_percent',
                'overhead_percent',
                'marketing_percent',
                'sales_percent',
                'production_management_percent',
                'markup_percent',
                'default_deposit_percent',
                'default_sales_discount_percent',
                'material_tax_rate',
                'default_interior_sqft_rate',
                'default_exterior_sqft_rate',
                'paint_catalog',
                'trim_widths',
                'cleanup_hours_ratio',
                'sundries_cost_per_prep_hour',
                'spray_paint_coverage_multiplier',
                'brush_roll_coverage_multiplier',
                'warranty_options',
                'recycle_fee_per_gallon',
                'wood_replacement_minimum',
            ]);
        });
    }
};
