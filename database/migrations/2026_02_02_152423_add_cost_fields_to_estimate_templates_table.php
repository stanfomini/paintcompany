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
        Schema::table('estimate_templates', function (Blueprint $table) {
            // Labor tracking
            $table->decimal('estimated_labor_hours', 8, 2)->nullable()->after('base_price');
            $table->decimal('labor_cost', 12, 2)->nullable()->after('estimated_labor_hours');

            // Material cost (separate from labor)
            $table->decimal('material_cost', 12, 2)->nullable()->after('labor_cost');

            // Per-template margin (overrides scope margin when set)
            $table->decimal('margin_percent', 5, 2)->nullable()->after('material_cost');

            // Calculated final price (stored for consistency)
            $table->decimal('calculated_price', 12, 2)->nullable()->after('margin_percent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('estimate_templates', function (Blueprint $table) {
            $table->dropColumn([
                'estimated_labor_hours',
                'labor_cost',
                'material_cost',
                'margin_percent',
                'calculated_price',
            ]);
        });
    }
};
