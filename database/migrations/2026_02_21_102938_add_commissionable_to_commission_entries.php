<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_entries', function (Blueprint $table) {
            $table->string('commissionable_type')->nullable()->after('employee_profile_id');
            $table->unsignedBigInteger('commissionable_id')->nullable()->after('commissionable_type');
            $table->index(['commissionable_type', 'commissionable_id'], 'commission_entries_commissionable_index');
        });

        // Backfill existing rows: set commissionable to EmployeeProfile
        DB::table('commission_entries')
            ->whereNotNull('employee_profile_id')
            ->whereNull('commissionable_type')
            ->update([
                'commissionable_type' => 'App\\Models\\EmployeeProfile',
                'commissionable_id' => DB::raw('employee_profile_id'),
            ]);
    }

    public function down(): void
    {
        Schema::table('commission_entries', function (Blueprint $table) {
            $table->dropIndex('commission_entries_commissionable_index');
            $table->dropColumn(['commissionable_type', 'commissionable_id']);
        });
    }
};
