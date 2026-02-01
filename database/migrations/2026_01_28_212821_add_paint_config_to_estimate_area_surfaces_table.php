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
            $table->string('application_method')->default('brush_roll')->after('coats');
            $table->string('paint_brand')->nullable()->after('application_method');
            $table->string('paint_type')->nullable()->after('paint_brand');
            $table->decimal('paint_gallons', 8, 2)->nullable()->after('paint_type');
            $table->decimal('paint_cost', 10, 2)->nullable()->after('paint_gallons');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('estimate_area_surfaces', function (Blueprint $table) {
            $table->dropColumn([
                'application_method',
                'paint_brand',
                'paint_type',
                'paint_gallons',
                'paint_cost',
            ]);
        });
    }
};
