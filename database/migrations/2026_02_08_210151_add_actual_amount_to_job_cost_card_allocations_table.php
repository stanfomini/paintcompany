<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_cost_card_allocations', function (Blueprint $table) {
            $table->decimal('actual_amount', 10, 2)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('job_cost_card_allocations', function (Blueprint $table) {
            $table->dropColumn('actual_amount');
        });
    }
};
