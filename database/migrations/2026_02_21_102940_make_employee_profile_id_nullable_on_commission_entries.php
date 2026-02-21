<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite cannot alter foreign keys; column is already effectively nullable in SQLite
            Schema::table('commission_entries', function (Blueprint $table) {
                $table->unsignedBigInteger('employee_profile_id')->nullable()->change();
            });
        } else {
            Schema::table('commission_entries', function (Blueprint $table) {
                $table->dropForeign(['employee_profile_id']);
            });

            Schema::table('commission_entries', function (Blueprint $table) {
                $table->unsignedBigInteger('employee_profile_id')->nullable()->change();
                $table->foreign('employee_profile_id')
                    ->references('id')
                    ->on('employee_profiles')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('commission_entries', function (Blueprint $table) {
                $table->unsignedBigInteger('employee_profile_id')->nullable(false)->change();
            });
        } else {
            Schema::table('commission_entries', function (Blueprint $table) {
                $table->dropForeign(['employee_profile_id']);
            });

            Schema::table('commission_entries', function (Blueprint $table) {
                $table->unsignedBigInteger('employee_profile_id')->nullable(false)->change();
                $table->foreign('employee_profile_id')
                    ->references('id')
                    ->on('employee_profiles')
                    ->cascadeOnDelete();
            });
        }
    }
};
