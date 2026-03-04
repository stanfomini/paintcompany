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
        Schema::table('estimate_area_paint_selections', function (Blueprint $table) {
            $table->foreignId('paint_color_id')->nullable()->after('paint_product_id')->constrained('paint_colors')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('estimate_area_paint_selections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('paint_color_id');
        });
    }
};
