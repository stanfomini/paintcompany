<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds labor and material tracking for fixed-price addons.
     * This allows painter pay calculations for closets, half-baths, etc.
     *
     * - labor_hours: From template.estimated_hours × quantity
     * - material_cost: From template.material_cost × quantity
     * - labor_cost: labor_hours × effective_hourly_rate (at time of creation)
     */
    public function up(): void
    {
        Schema::table('estimate_area_addons', function (Blueprint $table) {
            $table->decimal('labor_hours', 6, 2)->nullable()->after('total_price')
                ->comment('Estimated labor hours (from template × quantity)');
            $table->decimal('material_cost', 10, 2)->nullable()->after('labor_hours')
                ->comment('Material cost (from template × quantity)');
            $table->decimal('labor_cost', 10, 2)->nullable()->after('material_cost')
                ->comment('Labor cost (labor_hours × effective rate at creation)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('estimate_area_addons', function (Blueprint $table) {
            $table->dropColumn(['labor_hours', 'material_cost', 'labor_cost']);
        });
    }
};
