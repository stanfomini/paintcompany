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
        Schema::table('nested_area_templates', function (Blueprint $table) {
            $table->boolean('use_margin_pricing')->default(true)->after('fixed_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nested_area_templates', function (Blueprint $table) {
            $table->dropColumn('use_margin_pricing');
        });
    }
};
