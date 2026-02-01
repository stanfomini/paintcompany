<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds estimated_hours and material_cost fields for backend reporting.
     * These values are NOT included in estimate totals - they're for:
     * - Painter pay breakdown calculations
     * - Company reporting and analytics
     * - Job costing and profitability analysis
     */
    public function up(): void
    {
        Schema::table('nested_area_templates', function (Blueprint $table) {
            // Estimated labor hours for this nested item (for painter breakdown)
            $table->decimal('estimated_hours', 6, 2)->nullable()->after('fixed_price');
            // Material cost for this nested item (for company reporting)
            $table->decimal('material_cost', 8, 2)->nullable()->after('estimated_hours');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nested_area_templates', function (Blueprint $table) {
            $table->dropColumn(['estimated_hours', 'material_cost']);
        });
    }
};
