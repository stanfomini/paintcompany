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
            $table->decimal('sale_price', 10, 2)->nullable()->after('total');
            $table->decimal('crew_price', 10, 2)->nullable()->after('sale_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('estimate_area_surfaces', function (Blueprint $table) {
            $table->dropColumn(['sale_price', 'crew_price']);
        });
    }
};
