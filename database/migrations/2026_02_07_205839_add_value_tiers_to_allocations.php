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
        Schema::table('company_allocations', function (Blueprint $table) {
            $table->json('value_tiers')->nullable()->after('conditions_logic');
        });

        Schema::table('job_cost_card_allocations', function (Blueprint $table) {
            $table->string('applied_tier')->nullable()->after('conditions_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('company_allocations', function (Blueprint $table) {
            $table->dropColumn('value_tiers');
        });

        Schema::table('job_cost_card_allocations', function (Blueprint $table) {
            $table->dropColumn('applied_tier');
        });
    }
};
