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
        Schema::table('company_settings', function (Blueprint $table) {
            $table->string('excluded_surfaces_display', 20)->default('hidden')->after('estimate_builder_instructions');
        });

        Schema::table('estimates', function (Blueprint $table) {
            $table->string('excluded_surfaces_display', 20)->nullable()->after('paint_tiers');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('excluded_surfaces_display');
        });

        Schema::table('estimates', function (Blueprint $table) {
            $table->dropColumn('excluded_surfaces_display');
        });
    }
};
