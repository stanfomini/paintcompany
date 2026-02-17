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
        Schema::table('paint_products', function (Blueprint $table) {
            $table->string('quality_tier')->nullable()->after('surface_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('paint_products', function (Blueprint $table) {
            $table->dropColumn('quality_tier');
        });
    }
};
