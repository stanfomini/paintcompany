<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds substrate and default paint product to templates so they can
     * automatically inherit the correct multipliers and paint calculations
     * when applied to estimate areas.
     */
    public function up(): void
    {
        Schema::table('nested_area_templates', function (Blueprint $table) {
            $table->foreignId('substrate_id')
                ->nullable()
                ->after('production_rate_id')
                ->constrained('substrates')
                ->nullOnDelete();

            $table->foreignId('default_paint_product_id')
                ->nullable()
                ->after('substrate_id')
                ->constrained('paint_products')
                ->nullOnDelete();
        });

        Schema::table('estimate_templates', function (Blueprint $table) {
            $table->foreignId('substrate_id')
                ->nullable()
                ->after('production_rate_id')
                ->constrained('substrates')
                ->nullOnDelete();

            $table->foreignId('default_paint_product_id')
                ->nullable()
                ->after('substrate_id')
                ->constrained('paint_products')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nested_area_templates', function (Blueprint $table) {
            $table->dropForeign(['substrate_id']);
            $table->dropForeign(['default_paint_product_id']);
            $table->dropColumn(['substrate_id', 'default_paint_product_id']);
        });

        Schema::table('estimate_templates', function (Blueprint $table) {
            $table->dropForeign(['substrate_id']);
            $table->dropForeign(['default_paint_product_id']);
            $table->dropColumn(['substrate_id', 'default_paint_product_id']);
        });
    }
};
