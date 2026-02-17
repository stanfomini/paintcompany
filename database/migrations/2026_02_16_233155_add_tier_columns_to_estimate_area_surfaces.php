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
        Schema::table('estimate_area_surfaces', function (Blueprint $table) {
            $table->decimal('material_cost_good', 10, 2)->nullable()->after('material_cost');
            $table->decimal('material_cost_better', 10, 2)->nullable()->after('material_cost_good');
            $table->decimal('material_cost_best', 10, 2)->nullable()->after('material_cost_better');
            $table->unsignedBigInteger('paint_product_id_good')->nullable()->after('paint_product_id');
            $table->unsignedBigInteger('paint_product_id_better')->nullable()->after('paint_product_id_good');
            $table->unsignedBigInteger('paint_product_id_best')->nullable()->after('paint_product_id_better');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('estimate_area_surfaces', function (Blueprint $table) {
            $table->dropColumn([
                'material_cost_good',
                'material_cost_better',
                'material_cost_best',
                'paint_product_id_good',
                'paint_product_id_better',
                'paint_product_id_best',
            ]);
        });
    }
};
