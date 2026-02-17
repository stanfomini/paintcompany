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
        Schema::table('employee_onboarding_progress', function (Blueprint $table) {
            $table->foreignId('employee_contract_id')
                ->nullable()
                ->after('onboarding_checklist_item_id')
                ->constrained('employee_contracts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employee_onboarding_progress', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employee_contract_id');
        });
    }
};
