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
            $table->json('package_tiers')->nullable()->after('tier_overrides');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('estimate_area_surfaces', function (Blueprint $table) {
            $table->dropColumn('package_tiers');
        });
    }
};
