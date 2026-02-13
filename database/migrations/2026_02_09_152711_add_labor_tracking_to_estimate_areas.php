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
        Schema::table('estimate_areas', function (Blueprint $table) {
            $table->decimal('estimated_labor_hours', 10, 2)->nullable()->after('fixed_price_amount');
            $table->decimal('estimated_material_cost', 10, 2)->nullable()->after('estimated_labor_hours');
            $table->decimal('estimated_labor_cost', 10, 2)->nullable()->after('estimated_material_cost');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('estimate_areas', function (Blueprint $table) {
            $table->dropColumn(['estimated_labor_hours', 'estimated_material_cost', 'estimated_labor_cost']);
        });
    }
};
