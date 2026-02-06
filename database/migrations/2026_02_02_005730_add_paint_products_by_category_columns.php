<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds paint_products_by_category columns to company_settings and estimates tables.
     *
     * JSON structure:
     * {
     *   "interior": {"walls": 5, "ceiling": 7, "trim": 12, "doors": 12},
     *   "exterior": {"body": 15, "trim": 18, "fascia": 18, "doors": 20}
     * }
     *
     * Surface Categories:
     * - Interior: walls, ceiling, trim, doors
     * - Exterior: body, trim, fascia, eaves, doors
     */
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->json('default_paint_products_by_category')->nullable()->after('paint_catalog');
        });

        Schema::table('estimates', function (Blueprint $table) {
            $table->json('paint_products_by_category')->nullable()->after('default_paint_product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('default_paint_products_by_category');
        });

        Schema::table('estimates', function (Blueprint $table) {
            $table->dropColumn('paint_products_by_category');
        });
    }
};
