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
            $table->json('conditions')->nullable()->after('description');
            $table->string('conditions_logic', 10)->default('all')->after('conditions');
        });
    }

    public function down(): void
    {
        Schema::table('company_allocations', function (Blueprint $table) {
            $table->dropColumn(['conditions', 'conditions_logic']);
        });
    }
};
