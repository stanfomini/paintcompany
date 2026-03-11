<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('paint_colors', function (Blueprint $table) {
            $table->string('intended_use', 20)->default('both')->after('color_family');
            $table->index(['is_active', 'is_popular', 'intended_use', 'paint_brand_id'], 'paint_colors_popular_use_brand_index');
        });

        // Drop the old index that's superseded by the new composite
        Schema::table('paint_colors', function (Blueprint $table) {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropIndex(['is_active', 'is_popular']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('paint_colors', function (Blueprint $table) {
            $table->dropIndex('paint_colors_popular_use_brand_index');
            $table->dropColumn('intended_use');
            $table->index(['is_active', 'is_popular']);
        });
    }
};
