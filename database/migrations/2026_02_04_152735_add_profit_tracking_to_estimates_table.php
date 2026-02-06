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
        Schema::table('estimates', function (Blueprint $table) {
            $table->json('actual_paint_costs')->nullable()->after('settings_snapshot');
            $table->json('actual_painters')->nullable()->after('actual_paint_costs');
            $table->json('profit_snapshot')->nullable()->after('actual_painters');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            $table->dropColumn(['actual_paint_costs', 'actual_painters', 'profit_snapshot']);
        });
    }
};
