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
        Schema::table('job_cost_cards', function (Blueprint $table) {
            $table->decimal('allocation_total', 10, 2)->default(0)->after('sales_commission');
        });
    }

    public function down(): void
    {
        Schema::table('job_cost_cards', function (Blueprint $table) {
            $table->dropColumn('allocation_total');
        });
    }
};
